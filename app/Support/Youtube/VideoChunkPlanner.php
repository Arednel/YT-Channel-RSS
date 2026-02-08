<?php

namespace App\Support\Youtube;

use App\Jobs\FetchYoutubeVideoChunkJob;
use App\Models\YoutubeChannel;
use App\Models\YoutubeVideo;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Psr\Log\LoggerInterface;

class VideoChunkPlanner
{
    public function plan(
        YoutubeChannel $channel,
        string $videosJsonPath,
        string $outputDirectory,
        LoggerInterface $logger
    ): VideoChunkPlan {
        $chunkSize = max(1, (int) config('youtube.video_chunk_size', 50));
        $chunkDirectory = $outputDirectory . '/video_id_chunks';
        File::ensureDirectoryExists($chunkDirectory);
        File::cleanDirectory($chunkDirectory);
        $videoChunkDirectory = $outputDirectory . '/video_chunks';
        File::ensureDirectoryExists($videoChunkDirectory);
        File::cleanDirectory($videoChunkDirectory);

        $lastVideoId = null;
        $lastVideoDate = null;
        $seenVideoIds = [];
        $chunkEntries = [];
        $state = [
            'jobs' => [],
            'chunkCount' => 0,
            'queuedVideoCount' => 0,
            'skippedExistingCount' => 0,
            'recheckedUpcomingCount' => 0,
            'encodingSkippedCount' => 0,
        ];

        foreach (File::lines($videosJsonPath) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $video = json_decode($line, true);
            if (! is_array($video) || empty($video['id'])) {
                continue;
            }

            $videoId = (string) $video['id'];
            if (isset($seenVideoIds[$videoId])) {
                continue;
            }

            $seenVideoIds[$videoId] = true;
            $publishedDate = $this->parseUploadDate($video['upload_date'] ?? null);
            if ($publishedDate instanceof Carbon && ($lastVideoDate === null || $publishedDate->gt($lastVideoDate))) {
                $lastVideoDate = $publishedDate;
                $lastVideoId = $videoId;
            }

            $chunkEntries[] = [
                'id' => $videoId,
                'title' => $video['title'] ?? null,
                'upload_date' => $video['upload_date'] ?? null,
                'timestamp' => $video['timestamp'] ?? null,
                'release_timestamp' => $video['release_timestamp'] ?? null,
                'live_status' => $video['live_status'] ?? null,
                'thumbnail' => $video['thumbnail'] ?? null,
                'thumbnails' => $video['thumbnails'] ?? null,
                'description' => $video['description'] ?? null,
            ];

            if (count($chunkEntries) >= $chunkSize) {
                $this->flushPendingChunk(
                    channel: $channel,
                    outputDirectory: $outputDirectory,
                    logger: $logger,
                    chunkEntries: $chunkEntries,
                    state: $state,
                );
            }
        }

        $this->flushPendingChunk(
            channel: $channel,
            outputDirectory: $outputDirectory,
            logger: $logger,
            chunkEntries: $chunkEntries,
            state: $state,
        );

        return new VideoChunkPlan(
            jobs: $state['jobs'],
            lastVideoId: $lastVideoId,
            chunkCount: $state['chunkCount'],
            chunkSize: $chunkSize,
            queuedVideoCount: $state['queuedVideoCount'],
            skippedExistingCount: $state['skippedExistingCount'],
            recheckedUpcomingCount: $state['recheckedUpcomingCount'],
            encodingSkippedCount: $state['encodingSkippedCount'],
        );
    }

    /**
     * @param list<array{id: string, title: mixed, upload_date: mixed, timestamp: mixed, release_timestamp: mixed, live_status: mixed, thumbnail: mixed, thumbnails: mixed, description: mixed}> $chunkEntries
     * @param array{
     *   jobs: list<FetchYoutubeVideoChunkJob>,
     *   chunkCount: int,
     *   queuedVideoCount: int,
     *   skippedExistingCount: int,
     *   recheckedUpcomingCount: int,
     *   encodingSkippedCount: int
     * } $state
     */
    private function flushPendingChunk(
        YoutubeChannel $channel,
        string $outputDirectory,
        LoggerInterface $logger,
        array &$chunkEntries,
        array &$state
    ): void {
        if ($chunkEntries === []) {
            return;
        }

        $this->flushChunk(
            channel: $channel,
            entries: $chunkEntries,
            outputDirectory: $outputDirectory,
            logger: $logger,
            state: $state,
        );

        $chunkEntries = [];
    }

    /**
     * @param list<array{id: string, title: mixed, upload_date: mixed, timestamp: mixed, release_timestamp: mixed, live_status: mixed, thumbnail: mixed, thumbnails: mixed, description: mixed}> $entries
     * @param array{
     *   jobs: list<FetchYoutubeVideoChunkJob>,
     *   chunkCount: int,
     *   queuedVideoCount: int,
     *   skippedExistingCount: int,
     *   recheckedUpcomingCount: int,
     *   encodingSkippedCount: int
     * } $state
     */
    private function flushChunk(
        YoutubeChannel $channel,
        array $entries,
        string $outputDirectory,
        LoggerInterface $logger,
        array &$state
    ): void {
        if ($entries === []) {
            return;
        }

        $chunkVideoIds = array_values(array_unique(array_column($entries, 'id')));
        $existingVideoStates = YoutubeVideo::query()
            ->where('youtube_channel_id', $channel->id)
            ->whereIn('youtube_video_id', $chunkVideoIds)
            ->pluck('is_upcoming', 'youtube_video_id')
            ->all();
        $encodedChunkEntries = [];

        foreach ($entries as $entry) {
            $videoId = $entry['id'];
            $alreadyExists = array_key_exists($videoId, $existingVideoStates);
            $isUpcomingInDatabase = $alreadyExists && (bool) $existingVideoStates[$videoId] === true;
            if ($alreadyExists && ! $isUpcomingInDatabase) {
                $state['skippedExistingCount']++;
                continue;
            }

            if ($isUpcomingInDatabase) {
                $state['recheckedUpcomingCount']++;
            }

            try {
                $encodedChunkEntries[] = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                $logger->warning('Failed to encode chunk entry; skipping video.', [
                    'youtube_id' => $channel->youtube_id,
                    'youtube_video_id' => $videoId,
                    'error' => $exception->getMessage(),
                ]);
                $state['encodingSkippedCount']++;
            }
        }

        if ($encodedChunkEntries === []) {
            return;
        }

        $chunkFile = 'video_id_chunks/chunk_' . str_pad((string) ($state['chunkCount'] + 1), 5, '0', STR_PAD_LEFT) . '.jsonl';
        File::put($outputDirectory . '/' . $chunkFile, implode(PHP_EOL, $encodedChunkEntries) . PHP_EOL);

        $state['jobs'][] = new FetchYoutubeVideoChunkJob(
            $channel->id,
            $channel->youtube_id,
            $state['chunkCount'],
            count($encodedChunkEntries),
            $chunkFile
        );

        $state['chunkCount']++;
        $state['queuedVideoCount'] += count($encodedChunkEntries);
    }

    private function parseUploadDate(mixed $uploadDate): ?Carbon
    {
        if (! is_string($uploadDate) || $uploadDate === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Ymd', $uploadDate, 'UTC')
                ->startOfDay()
                ->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}

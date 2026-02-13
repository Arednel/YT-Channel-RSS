<?php

namespace App\Actions\Youtube;

use App\Jobs\BuildYoutubeFeedJob;
use App\Models\YoutubeChannel;
use App\Support\YoutubeBatchManager;
use App\Support\Youtube\ChannelFetchRunner;
use App\Support\Youtube\VideoChunkPlanner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RunYoutubeChannelSyncAction
{
    public function __construct(
        private ChannelFetchRunner $channelFetchRunner,
        private VideoChunkPlanner $videoChunkPlanner,
        private DispatchYoutubeVideoChunkBatchAction $dispatchVideoChunkBatch,
        private FinalizeYoutubeChannelSyncWithoutBatchAction $finalizeWithoutBatch,
        private YoutubeBatchManager $youtubeBatchManager,
    ) {
    }

    public function fetchChannelInfoAndVideoList(int $channelId): bool
    {
        $channel = YoutubeChannel::query()->find($channelId);
        if ($channel === null) {
            return false;
        }

        if ($channel->isDeleting()) {
            return false;
        }

        if ($this->youtubeBatchManager->hasActiveVideoBatch($channel)) {
            Log::channel('youtube')->info('Sync skipped: an active video chunk batch already exists for this channel.', [
                'local_database_channel_id' => $channel->id,
                'youtube_id' => $channel->youtube_id,
            ]);

            return false;
        }

        Log::channel('youtube')->info('YouTube channel info/list fetch started.', [
            'local_database_channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
        ]);

        $channel->markFetchingVideoList();

        $fetchRun = $this->channelFetchRunner->run($channel);
        $channelJsonPath = $fetchRun->channelJsonPath;
        $videosJsonPath = $fetchRun->videosJsonPath;

        if (File::exists($channelJsonPath)) {
            $channelData = json_decode(File::get($channelJsonPath), true);
            $channelName = $this->resolveChannelName($channelData);

            if ($channelName !== null) {
                $channel->update([
                    'channel_name' => $channelName,
                ]);
            }
        }

        if (! File::exists($videosJsonPath)) {
            Log::channel('youtube')->error('Channel fetch did not produce videos.jsonl.', [
                'channel_id' => $channel->id,
                'youtube_id' => $channel->youtube_id,
                'expected_path' => $videosJsonPath,
            ]);

            throw new \RuntimeException('Channel fetch output missing videos.jsonl.');
        }

        Log::channel('youtube')->info('YouTube channel info/list fetch finished.', [
            'local_database_channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
        ]);

        return true;
    }

    public function dispatchVideoPhase(int $channelId): void
    {
        $channel = YoutubeChannel::query()->find($channelId);
        if ($channel === null) {
            return;
        }

        if ($channel->isDeleting()) {
            return;
        }

        if ($this->youtubeBatchManager->hasActiveVideoBatch($channel)) {
            Log::channel('youtube')->info('Video phase skipped: an active video chunk batch already exists for this channel.', [
                'local_database_channel_id' => $channel->id,
                'youtube_id' => $channel->youtube_id,
            ]);

            return;
        }

        $outputDirectory = base_path('python/yt-dlp_jsons/' . $channel->youtube_id);
        $videosJsonPath = $outputDirectory . '/videos.jsonl';
        if (! File::exists($videosJsonPath)) {
            Log::channel('youtube')->error('Video phase did not find videos.jsonl.', [
                'channel_id' => $channel->id,
                'youtube_id' => $channel->youtube_id,
                'expected_path' => $videosJsonPath,
            ]);

            throw new \RuntimeException('Video phase input missing videos.jsonl.');
        }

        $plan = $this->videoChunkPlanner->plan(
            channel: $channel,
            videosJsonPath: $videosJsonPath,
            outputDirectory: $outputDirectory,
        );

        if ($plan->hasJobs()) {
            $batch = $this->dispatchVideoChunkBatch->handle($channel, $plan);

            Log::channel('youtube')->info('Video chunk jobs dispatched.', [
                'queued_video_count' => $plan->queuedVideoCount,
                'skipped_existing_count' => $plan->skippedExistingCount,
                'rechecked_upcoming_count' => $plan->recheckedUpcomingCount,
                'chunk_count' => $plan->chunkCount,
                'chunk_size' => $plan->chunkSize,
                'batch_id' => $batch->id,
                'encoding_skipped_count' => $plan->encodingSkippedCount,
            ]);

            return;
        }

        if ($plan->lastVideoId !== null) {
            $channel->update(['last_video_id' => $plan->lastVideoId]);
        }

        Log::channel('youtube')->info('No videos required detail refresh.', [
            'queued_video_count' => $plan->queuedVideoCount,
            'skipped_existing_count' => $plan->skippedExistingCount,
            'rechecked_upcoming_count' => $plan->recheckedUpcomingCount,
            'encoding_skipped_count' => $plan->encodingSkippedCount,
        ]);

        $shouldBuildFeed = ! Storage::disk('public')->exists('feeds/' . $channel->youtube_id . '.xml');
        $this->finalizeWithoutBatch->handle($channel->id, $shouldBuildFeed);

        if ($shouldBuildFeed) {
            BuildYoutubeFeedJob::dispatch($channel->id);
        }

        Log::channel('youtube')->info('YouTube channel video phase finished.', [
            'local_database_channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
        ]);
    }

    private function resolveChannelName(mixed $channelData): ?string
    {
        if (! is_array($channelData)) {
            return null;
        }

        $name = $this->firstNonEmptyString([
            $channelData['channel'] ?? null,
            $channelData['uploader'] ?? null,
        ]);

        $title = $channelData['title'] ?? null;
        if ($name === null && is_string($title) && $title !== '' && ! str_starts_with($title, '@')) {
            $name = $title;
        }

        if ($name !== null) {
            return $name;
        }

        $entries = $channelData['entries'] ?? null;
        if (! is_array($entries)) {
            return null;
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = $this->firstNonEmptyString([
                $entry['channel'] ?? null,
                $entry['uploader'] ?? null,
            ]);

            if ($name !== null) {
                return $name;
            }

            $nestedEntries = $entry['entries'] ?? null;
            if (! is_array($nestedEntries)) {
                continue;
            }

            foreach ($nestedEntries as $nestedEntry) {
                if (! is_array($nestedEntry)) {
                    continue;
                }

                $name = $this->firstNonEmptyString([
                    $nestedEntry['channel'] ?? null,
                    $nestedEntry['uploader'] ?? null,
                ]);

                if ($name !== null) {
                    return $name;
                }
            }
        }

        return null;
    }

    private function firstNonEmptyString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}

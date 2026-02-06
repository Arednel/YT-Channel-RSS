<?php

namespace App\Jobs;

use App\Enums\YoutubeChannelStatus;
use App\Models\YoutubeChannel;
use App\Support\YoutubeBatchManager;
use Carbon\Carbon;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class SyncYoutubeChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public int $channelId
    ) {
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('youtube-sync:' . $this->channelId))
                ->releaseAfter(15)
                ->expireAfter(7200),
            (new RateLimited('youtube-sync'))->releaseAfter(15),
        ];
    }

    public function handle(): void
    {
        $channel = YoutubeChannel::query()->find($this->channelId);
        if ($channel === null) {
            return;
        }

        if (YoutubeBatchManager::hasActiveVideoBatch($channel)) {
            $this->channelLogger($channel->youtube_id)->info('Sync skipped: an active video chunk batch already exists for this channel.', [
                'local_database_channel_id' => $channel->id,
                'youtube_id' => $channel->youtube_id,
            ]);

            return;
        }

        $this->channelLogger($channel->youtube_id)->info('', []);
        $this->channelLogger($channel->youtube_id)->info('YouTube channel sync started.', [
            'local_database_channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
        ]);

        $channel->update([
            'status' => YoutubeChannelStatus::FetchingVideoList,
            'last_error' => null,
            'active_video_batch_id' => null,
        ]);

        $channelUrl = 'https://www.youtube.com/' . ltrim($channel->youtube_id, '/');
        $outputDirectory = base_path('python/yt-dlp_jsons/' . $channel->youtube_id);
        File::ensureDirectoryExists($outputDirectory);

        $logDirectory = base_path('python/logs/' . $channel->youtube_id);
        File::ensureDirectoryExists($logDirectory);
        $pythonLogFile = $logDirectory . '/channel_fetch_' . now()->format('Ymd_His') . '.log';

        $processResult = Process::forever()->run([
            $this->resolvePythonBinary(),
            base_path('python/yt-dlp/channel_fetch.py'),
            '--channel-url',
            $channelUrl,
            '--out-dir',
            $outputDirectory,
            '--log-file',
            $pythonLogFile,
        ]);

        if ($processResult->failed()) {
            $errorOutput = trim($processResult->errorOutput()) ?: trim($processResult->output());
            throw new \RuntimeException($errorOutput ?: 'yt-dlp channel fetch failed.');
        }

        $channelJsonPath = $outputDirectory . '/channel.json';
        $videosJsonPath = $outputDirectory . '/videos.jsonl';

        if (File::exists($channelJsonPath)) {
            $channelData = json_decode(File::get($channelJsonPath), true);
            $channelName = $channelData['channel'] ?? $channelData['uploader'] ?? $channelData['title'] ?? null;

            $channel->update([
                'channel_name' => $channelName,
            ]);
        }

        if (File::exists($videosJsonPath)) {
            $lastVideoId = null;
            $lastVideoDate = null;
            $seenVideoIds = [];
            $chunkSize = max(1, (int) config('youtube.video_chunk_size', 50));
            $chunkDirectory = $outputDirectory . '/video_id_chunks';
            File::ensureDirectoryExists($chunkDirectory);
            File::cleanDirectory($chunkDirectory);

            $chunkEntries = [];
            $chunkCount = 0;
            $videoCount = 0;
            $encodingSkippedCount = 0;
            $jobs = [];
            $channelId = $channel->id;

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

                try {
                    $encodedChunkEntry = json_encode([
                        'id' => $videoId,
                        'title' => $video['title'] ?? null,
                        'upload_date' => $video['upload_date'] ?? null,
                        'timestamp' => $video['timestamp'] ?? null,
                        'thumbnail' => $video['thumbnail'] ?? null,
                        'thumbnails' => $video['thumbnails'] ?? null,
                        'description' => $video['description'] ?? null,
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    $this->channelLogger($channel->youtube_id)->warning('Failed to encode chunk entry; skipping video.', [
                        'youtube_id' => $channel->youtube_id,
                        'youtube_video_id' => $videoId,
                        'error' => $exception->getMessage(),
                    ]);
                    $encodingSkippedCount++;

                    continue;
                }

                $chunkEntries[] = $encodedChunkEntry;
                $videoCount++;

                $publishedDate = null;
                if (! empty($video['upload_date'])) {
                    try {
                        $publishedDate = Carbon::createFromFormat('Ymd', $video['upload_date'])->startOfDay();
                    } catch (\Throwable) {
                        $publishedDate = null;
                    }
                }

                if ($publishedDate instanceof Carbon && ($lastVideoDate === null || $publishedDate->gt($lastVideoDate))) {
                    $lastVideoDate = $publishedDate;
                    $lastVideoId = $videoId;
                }

                if (count($chunkEntries) >= $chunkSize) {
                    $chunkFile = 'video_id_chunks/chunk_' . str_pad((string) ($chunkCount + 1), 5, '0', STR_PAD_LEFT) . '.jsonl';
                    File::put($outputDirectory . '/' . $chunkFile, implode(PHP_EOL, $chunkEntries) . PHP_EOL);

                    $jobs[] = new FetchYoutubeVideoChunkJob(
                        $channelId,
                        $channel->youtube_id,
                        $chunkCount,
                        $chunkSize,
                        $chunkFile
                    );

                    $chunkCount++;
                    $chunkEntries = [];
                }
            }

            if ($chunkEntries !== []) {
                $chunkFile = 'video_id_chunks/chunk_' . str_pad((string) ($chunkCount + 1), 5, '0', STR_PAD_LEFT) . '.jsonl';
                File::put($outputDirectory . '/' . $chunkFile, implode(PHP_EOL, $chunkEntries) . PHP_EOL);

                $jobs[] = new FetchYoutubeVideoChunkJob(
                    $channelId,
                    $channel->youtube_id,
                    $chunkCount,
                    $chunkSize,
                    $chunkFile
                );

                $chunkCount++;
            }

            if ($jobs !== []) {
                $batch = DB::transaction(function () use ($channelId, $jobs, $lastVideoId, $channel): Batch {
                    $freshChannel = YoutubeChannel::query()->find($channelId);
                    if ($freshChannel === null) {
                        throw new \RuntimeException('Channel deleted during sync dispatch.');
                    }

                    $freshChannel->fill([
                        'status' => YoutubeChannelStatus::FetchingVideos,
                    ]);

                    if ($lastVideoId !== null) {
                        $freshChannel->last_video_id = $lastVideoId;
                    }

                    $freshChannel->save();

                    $batch = Bus::batch($jobs)
                        ->name('youtube_video_chunks:' . $channel->youtube_id)
                        ->allowFailures()
                        ->finally(function (Batch $batch) use ($channelId): void {
                            $freshChannel = YoutubeChannel::query()->find($channelId);
                            if ($freshChannel === null) {
                                return;
                            }

                            DB::transaction(function () use ($freshChannel, $batch): void {
                                YoutubeBatchManager::setActiveVideoBatchId($freshChannel, null);

                                if ($freshChannel->status === YoutubeChannelStatus::Deleting) {
                                    return;
                                }

                                if ($batch->failedJobs > 0) {
                                    $freshChannel->update([
                                        'status' => YoutubeChannelStatus::Failed,
                                        'last_error' => 'One or more video chunks failed after retries.',
                                    ]);

                                    return;
                                }

                                $freshChannel->update([
                                    'status' => YoutubeChannelStatus::Idle,
                                    'last_sync_at' => now(),
                                ]);
                            });
                        })
                        ->dispatch();

                    YoutubeBatchManager::setActiveVideoBatchId($freshChannel, $batch->id);

                    return $batch;
                });

                $this->channelLogger($channel->youtube_id)->info('Video chunk jobs dispatched.', [
                    'video_count' => $videoCount,
                    'chunk_count' => $chunkCount,
                    'chunk_size' => $chunkSize,
                    'batch_id' => $batch->id,
                    'encoding_skipped_count' => $encodingSkippedCount,
                ]);

                return;
            }

            if ($lastVideoId !== null) {
                $channel->update(['last_video_id' => $lastVideoId]);
            }
        }

        DB::transaction(function () use ($channel): void {
            $freshChannel = YoutubeChannel::query()->find($channel->id);
            if ($freshChannel === null) {
                return;
            }

            $freshChannel->update([
                'status' => YoutubeChannelStatus::Idle,
                'last_sync_at' => now(),
                'active_video_batch_id' => null,
            ]);
        });

        $this->channelLogger($channel->youtube_id)->info('YouTube channel sync finished.', [
            'local_database_channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $channel = YoutubeChannel::query()->find($this->channelId);
        if ($channel === null) {
            return;
        }

        $channel->update([
            'status' => YoutubeChannelStatus::Failed,
            'last_error' => $exception->getMessage(),
        ]);

        YoutubeBatchManager::setActiveVideoBatchId($channel, null);

        $this->channelLogger($channel->youtube_id)->error('YouTube channel sync failed.', [
            'channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
            'error' => $exception->getMessage(),
        ]);
    }

    private function channelLogger(string $youtubeId): \Psr\Log\LoggerInterface
    {
        $directory = storage_path('logs/' . $youtubeId);
        File::ensureDirectoryExists($directory);

        return Log::build([
            'driver' => 'single',
            'path' => $directory . '/sync.log',
        ]);
    }

    private function resolvePythonBinary(): string
    {
        $candidates = [
            base_path('python/venv/Scripts/python.exe'),
            base_path('python/venv/bin/python'),
            'python3',
            'python',
        ];

        foreach ($candidates as $candidate) {
            if (str_contains($candidate, base_path('python/venv')) && File::exists($candidate)) {
                return $candidate;
            }
        }

        return 'python';
    }
}

<?php

namespace App\Jobs;

use App\Enums\YoutubeChannelStatus;
use App\Models\YoutubeChannel;
use App\Support\YoutubeBatchManager;
use App\Support\Youtube\ChannelFetchRunner;
use App\Support\Youtube\VideoChunkPlan;
use App\Support\Youtube\VideoChunkPlanner;
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
use Illuminate\Support\Facades\Storage;

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
            (new WithoutOverlapping('youtube-channel:' . $this->channelId))
                ->releaseAfter(15)
                ->expireAfter(7200),
            (new RateLimited('youtube-sync'))->releaseAfter(15),
        ];
    }

    public function handle(ChannelFetchRunner $channelFetchRunner, VideoChunkPlanner $videoChunkPlanner): void
    {
        $channel = YoutubeChannel::query()->find($this->channelId);
        if ($channel === null) {
            return;
        }

        if ($channel->status === YoutubeChannelStatus::Deleting) {
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

        $fetchRun = $channelFetchRunner->run($channel);
        $channelJsonPath = $fetchRun->channelJsonPath;
        $videosJsonPath = $fetchRun->videosJsonPath;
        $outputDirectory = $fetchRun->outputDirectory;
        $logger = $this->channelLogger($channel->youtube_id);

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
            $logger->error('Channel fetch did not produce videos.jsonl.', [
                'channel_id' => $channel->id,
                'youtube_id' => $channel->youtube_id,
                'expected_path' => $videosJsonPath,
            ]);

            throw new \RuntimeException('Channel fetch output missing videos.jsonl.');
        }

        $plan = $videoChunkPlanner->plan(
            channel: $channel,
            videosJsonPath: $videosJsonPath,
            outputDirectory: $outputDirectory,
            logger: $logger,
        );

        if ($plan->hasJobs()) {
            $batch = $this->dispatchVideoBatch($channel, $plan);

            $logger->info('Video chunk jobs dispatched.', [
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

        $logger->info('No videos required detail refresh.', [
            'queued_video_count' => $plan->queuedVideoCount,
            'skipped_existing_count' => $plan->skippedExistingCount,
            'rechecked_upcoming_count' => $plan->recheckedUpcomingCount,
            'encoding_skipped_count' => $plan->encodingSkippedCount,
        ]);

        $shouldBuildFeed = ! Storage::disk('public')->exists('feeds/' . $channel->youtube_id . '.xml');
        $this->finalizeWithoutBatch($channel->id, $shouldBuildFeed);

        if ($shouldBuildFeed) {
            BuildYoutubeFeedJob::dispatch($channel->id);
        }

        $this->channelLogger($channel->youtube_id)->info('YouTube channel sync finished.', [
            'local_database_channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
        ]);
    }

    private function dispatchVideoBatch(YoutubeChannel $channel, VideoChunkPlan $plan): Batch
    {
        $channelId = $channel->id;
        $lastVideoId = $plan->lastVideoId;
        $jobs = $plan->jobs;

        DB::beginTransaction();
        try {
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
                ->withOption('channel_id', $channelId)
                ->allowFailures()
                ->finally([self::class, 'handleVideoBatchFinally'])
                ->dispatch();

            YoutubeBatchManager::setActiveVideoBatchId($freshChannel, $batch->id);

            DB::commit();
            return $batch;
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }

    public static function handleVideoBatchFinally(Batch $batch): void
    {
        $rawChannelId = $batch->options['channel_id'] ?? null;
        $channelId = self::normalizeChannelId($rawChannelId);
        if ($channelId === null) {
            return;
        }

        $freshChannel = YoutubeChannel::query()->find($channelId);
        if ($freshChannel === null) {
            return;
        }

        $shouldBuildFeed = false;
        DB::beginTransaction();
        try {
            YoutubeBatchManager::setActiveVideoBatchId($freshChannel, null);

            if ($freshChannel->status === YoutubeChannelStatus::Deleting) {
                DB::commit();
                return;
            }

            if ($batch->failedJobs > 0) {
                $freshChannel->update([
                    'status' => YoutubeChannelStatus::Failed,
                    'last_error' => 'One or more video chunks failed after retries.',
                ]);

                DB::commit();
                return;
            }

            $freshChannel->update([
                'status' => YoutubeChannelStatus::BuildingFeed,
            ]);
            $shouldBuildFeed = true;

            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }

        if ($shouldBuildFeed) {
            BuildYoutubeFeedJob::dispatch($channelId);
        }
    }

    private function finalizeWithoutBatch(int $channelId, bool $shouldBuildFeed): void
    {
        DB::beginTransaction();
        try {
            $freshChannel = YoutubeChannel::query()->find($channelId);
            if ($freshChannel === null || $freshChannel->status === YoutubeChannelStatus::Deleting) {
                DB::commit();
                return;
            }

            if ($shouldBuildFeed) {
                $freshChannel->update([
                    'status' => YoutubeChannelStatus::BuildingFeed,
                    'active_video_batch_id' => null,
                ]);
                DB::commit();
                return;
            }

            $freshChannel->update([
                'status' => YoutubeChannelStatus::Idle,
                'active_video_batch_id' => null,
                'last_sync_at' => now(),
                'last_error' => null,
            ]);
            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }

    private static function normalizeChannelId(mixed $rawChannelId): ?int
    {
        if (is_int($rawChannelId)) {
            return $rawChannelId;
        }

        if (is_string($rawChannelId) && ctype_digit($rawChannelId)) {
            return (int) $rawChannelId;
        }

        return null;
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

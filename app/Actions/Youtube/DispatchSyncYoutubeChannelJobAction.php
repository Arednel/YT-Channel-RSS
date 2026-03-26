<?php

namespace App\Actions\Youtube;

use App\Enums\YoutubeChannelStatus;
use App\Jobs\SyncYoutubeChannelJob;
use App\Models\YoutubeChannel;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;

class DispatchSyncYoutubeChannelJobAction
{
    public function __construct(
        private QueueingDispatcher $dispatcher,
        private CacheRepository $cacheRepository,
    ) {
    }

    /**
     * Acquire the unique lock before mutating state so "queued" only means a real queued job.
     */
    public function handle(YoutubeChannel $channel): bool
    {
        $channel->refresh();

        $job = new SyncYoutubeChannelJob($channel->id);
        $lock = new UniqueLock($this->cacheRepository);

        if (! $lock->acquire($job)) {
            Log::channel('youtube')->info('Sync dispatch skipped: unique lock already held.', [
                'channel_id' => $channel->id,
                'youtube_id' => $channel->youtube_id,
            ]);

            return false;
        }

        $previousStatus = $channel->resolvedStatus() ?? YoutubeChannelStatus::Idle;
        $previousLastError = $channel->last_error;
        $previousVideoFetchProgressCurrent = $channel->video_fetch_progress_current;
        $previousVideoFetchProgressTotal = $channel->video_fetch_progress_total;

        try {
            $channel->markQueuedForSync();
            $this->dispatcher->dispatchToQueue($job);
        } catch (\Throwable $exception) {
            $channel->update([
                'status' => $previousStatus,
                'last_error' => $previousLastError,
                'video_fetch_progress_current' => $previousVideoFetchProgressCurrent,
                'video_fetch_progress_total' => $previousVideoFetchProgressTotal,
            ]);

            $lock->release($job);

            throw $exception;
        }

        return true;
    }
}

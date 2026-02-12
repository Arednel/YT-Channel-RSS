<?php

namespace App\Support;

use App\Models\YoutubeChannel;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;

class YoutubeBatchManager
{
    // Returns true only when the tracked batch still exists and is actionable.
    // Cancelled batches are treated as inactive for status checks.
    public function hasActiveVideoBatch(YoutubeChannel $channel): bool
    {
        return $this->resolveTrackedBatch($channel, clearIfCancelled: true) !== null;
    }

    // Cancels the tracked batch when possible, then clears the channel pointer.
    // Uses a transaction + row lock so concurrent requests cannot clobber batch-id state.
    public function cancelActiveVideoBatch(YoutubeChannel $channel): bool
    {
        return DB::transaction(function () use ($channel): bool {
            $lockedChannel = YoutubeChannel::query()
                ->whereKey($channel->id)
                ->lockForUpdate()
                ->first();
            if (! $lockedChannel instanceof YoutubeChannel) {
                return false;
            }

            $batch = $this->resolveTrackedBatch($lockedChannel);
            if (! $batch instanceof Batch) {
                $channel->active_video_batch_id = $lockedChannel->active_video_batch_id;
                return false;
            }

            if (! $batch->cancelled()) {
                $batch->cancel();
            }

            $lockedChannel->clearActiveVideoBatchId();
            $channel->active_video_batch_id = null;

            return true;
        });
    }

    // Resolves the tracked batch and self-heals stale ids when batch is missing/finished.
    // Optional flag allows treating cancelled batches as stale too.
    private function resolveTrackedBatch(
        YoutubeChannel $channel,
        bool $clearIfCancelled = false
    ): ?Batch {
        $batchId = $this->resolveTrackedBatchId($channel);
        if ($batchId === null) {
            return null;
        }

        $batch = Bus::findBatch($batchId);
        if (! $batch instanceof Batch) {
            $channel->clearActiveVideoBatchId();
            return null;
        }

        if ($batch->finished() || ($clearIfCancelled && $batch->cancelled())) {
            $channel->clearActiveVideoBatchId();
            return null;
        }

        return $batch;
    }

    // Normalizes the model value into a usable batch id.
    private function resolveTrackedBatchId(YoutubeChannel $channel): ?string
    {
        $batchId = $channel->active_video_batch_id;
        if (! is_string($batchId) || $batchId === '') {
            return null;
        }

        return $batchId;
    }
}

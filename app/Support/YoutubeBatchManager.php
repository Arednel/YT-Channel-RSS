<?php

namespace App\Support;

use App\Models\YoutubeChannel;
use Illuminate\Support\Facades\Bus;

class YoutubeBatchManager
{
    public static function hasActiveVideoBatch(YoutubeChannel $channel): bool
    {
        $batchId = $channel->active_video_batch_id;
        if (! is_string($batchId) || $batchId === '') {
            return false;
        }

        $batch = Bus::findBatch($batchId);
        if ($batch === null || $batch->finished() || $batch->cancelled()) {
            self::setActiveVideoBatchId($channel, null);
            return false;
        }

        return true;
    }

    public static function cancelActiveVideoBatch(YoutubeChannel $channel): bool
    {
        $batchId = $channel->active_video_batch_id;
        if (! is_string($batchId) || $batchId === '') {
            return false;
        }

        $batch = Bus::findBatch($batchId);
        if ($batch === null || $batch->finished()) {
            self::setActiveVideoBatchId($channel, null);
            return false;
        }

        if (! $batch->cancelled()) {
            $batch->cancel();
        }

        self::setActiveVideoBatchId($channel, null);

        return true;
    }

    public static function setActiveVideoBatchId(YoutubeChannel $channel, ?string $batchId): void
    {
        YoutubeChannel::query()->whereKey($channel->id)->update([
            'active_video_batch_id' => $batchId,
        ]);

        $channel->active_video_batch_id = $batchId;
    }
}

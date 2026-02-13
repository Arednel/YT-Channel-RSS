<?php

namespace App\Actions\Youtube;

use App\Models\YoutubeChannel;
use App\Support\Youtube\YtDlpAutoUpdateManager;
use Illuminate\Support\Facades\Log;
use Throwable;

class HandleYoutubeChannelSyncFailureAction
{
    public function handle(int $channelId, Throwable $exception, string $logMessage): void
    {
        app(YtDlpAutoUpdateManager::class)->recordFailure(
            YtDlpAutoUpdateManager::CHANNEL_UPDATE_JOB,
            $exception->getMessage()
        );

        $channel = YoutubeChannel::query()->find($channelId);
        if ($channel === null || $channel->isDeleting()) {
            return;
        }

        $channel->markFailed($exception->getMessage());
        $channel->clearActiveVideoBatchId();

        Log::channel('youtube')->error($logMessage, [
            'channel_id' => $channel->id,
            'youtube_id' => $channel->youtube_id,
            'error' => $exception->getMessage(),
        ]);
    }
}

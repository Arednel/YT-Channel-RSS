<?php

namespace App\Jobs;

use App\Actions\Youtube\RunYoutubeChannelSyncAction;
use App\Jobs\Middleware\PreventOverlappingYoutubeChannel;
use App\Jobs\Middleware\RateLimitYoutubeSync;
use App\Support\Youtube\YtDlpAutoUpdateManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FetchYoutubeChannelInfoAndVideoListJob implements ShouldQueue
{
    use Queueable;

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
            new PreventOverlappingYoutubeChannel($this->channelId),
            new RateLimitYoutubeSync,
        ];
    }

    public function handle(
        RunYoutubeChannelSyncAction $runYoutubeChannelSync,
        YtDlpAutoUpdateManager $ytDlpAutoUpdateManager
    ): void {
        $didFetch = $runYoutubeChannelSync->fetchChannelInfoAndVideoList($this->channelId);

        if ($didFetch) {
            $ytDlpAutoUpdateManager->recordSuccess(YtDlpAutoUpdateManager::CHANNEL_UPDATE_JOB);
        }
    }
}

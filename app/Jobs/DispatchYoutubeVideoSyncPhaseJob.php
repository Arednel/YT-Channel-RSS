<?php

namespace App\Jobs;

use App\Actions\Youtube\RunYoutubeChannelSyncAction;
use App\Jobs\Middleware\PreventOverlappingYoutubeChannel;
use App\Jobs\Middleware\RateLimitYoutubeSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchYoutubeVideoSyncPhaseJob implements ShouldQueue
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

    public function handle(RunYoutubeChannelSyncAction $runYoutubeChannelSync): void
    {
        $runYoutubeChannelSync->dispatchVideoPhase($this->channelId);
    }
}

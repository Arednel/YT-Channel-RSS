<?php

namespace App\Jobs;

use App\Actions\Youtube\HandleYoutubeChannelSyncFailureAction;
use App\Jobs\Middleware\PreventOverlappingYoutubeChannel;
use App\Jobs\Middleware\RateLimitYoutubeSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

class SyncYoutubeChannelJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60;
    public int $uniqueFor = 7200;

    public function __construct(
        public int $channelId
    ) {}

    public function uniqueId(): string
    {
        return 'youtube-sync-channel:' . $this->channelId;
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

    public function handle(): void
    {
        $channelId = $this->channelId;

        $chain = Bus::chain([
            new FetchYoutubeChannelInfoAndVideoListJob($channelId),
            new DispatchYoutubeVideoSyncPhaseJob($channelId),
        ])->catch(static function (Throwable $exception) use ($channelId): void {
            app(HandleYoutubeChannelSyncFailureAction::class)->handle(
                channelId: $channelId,
                exception: $exception,
                logMessage: 'YouTube channel sync chain failed.'
            );
        });

        if (is_string($this->connection) && $this->connection !== '') {
            $chain->onConnection($this->connection);
        }

        if (is_string($this->queue) && $this->queue !== '') {
            $chain->onQueue($this->queue);
        }

        $chain->dispatch();
    }

    public function failed(\Throwable $exception): void
    {
        app(HandleYoutubeChannelSyncFailureAction::class)->handle(
            channelId: $this->channelId,
            exception: $exception,
            logMessage: 'YouTube channel sync dispatch failed.'
        );
    }
}

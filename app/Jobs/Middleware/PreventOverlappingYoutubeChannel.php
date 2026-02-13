<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class PreventOverlappingYoutubeChannel
{
    public function __construct(
        private readonly int $channelId
    ) {}

    public function handle(object $job, Closure $next): mixed
    {
        return (new WithoutOverlapping('youtube-channel:' . $this->channelId))
            ->releaseAfter(900)
            ->expireAfter(7200)
            ->handle($job, $next);
    }
}

<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class PreventOverlappingYoutubeFeedBuild
{
    public function __construct(
        private readonly int $channelId
    ) {
    }

    public function handle(object $job, Closure $next): mixed
    {
        return (new WithoutOverlapping('youtube-feed-build:' . $this->channelId))
            ->releaseAfter(15)
            ->expireAfter(3600)
            ->handle($job, $next);
    }
}

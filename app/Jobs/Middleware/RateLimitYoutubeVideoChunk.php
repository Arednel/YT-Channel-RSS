<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Queue\Middleware\RateLimited;

class RateLimitYoutubeVideoChunk
{
    public function handle(object $job, Closure $next): mixed
    {
        return (new RateLimited('youtube-video-chunk'))
            ->releaseAfter(15)
            ->handle($job, $next);
    }
}

<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Queue\Middleware\RateLimited;

class RateLimitYoutubeSync
{
    public function handle(object $job, Closure $next): mixed
    {
        return (new RateLimited('youtube-sync'))
            ->releaseAfter(15)
            ->handle($job, $next);
    }
}

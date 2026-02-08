<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;

class SkipIfYoutubeBatchCancelled
{
    public function handle(object $job, Closure $next): mixed
    {
        return (new SkipIfBatchCancelled)->handle($job, $next);
    }
}

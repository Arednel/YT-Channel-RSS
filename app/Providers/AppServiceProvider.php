<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('youtube-sync', function (object $job) {
            $channelId = property_exists($job, 'channelId') ? (string) $job->channelId : 'global';
            $maxAttempts = (int) config('youtube.sync_jobs_per_minute', 0);
            if ($maxAttempts <= 0) {
                return Limit::none();
            }

            return Limit::perMinute($maxAttempts)->by('youtube-sync:' . $channelId);
        });

        RateLimiter::for('youtube-video-chunk', function (object $job) {
            $youtubeId = property_exists($job, 'youtubeId') ? (string) $job->youtubeId : 'global';
            $maxAttempts = (int) config('youtube.video_chunk_jobs_per_minute', 0);
            if ($maxAttempts <= 0) {
                return Limit::none();
            }

            return Limit::perMinute($maxAttempts)->by('youtube-video-chunk:' . $youtubeId);
        });
    }
}

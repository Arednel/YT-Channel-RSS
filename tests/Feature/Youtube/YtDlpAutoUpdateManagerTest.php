<?php

namespace Tests\Feature\Youtube;

use App\Jobs\UpdateYtDlpJob;
use App\Support\Youtube\YtDlpAutoUpdateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class YtDlpAutoUpdateManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_update_job_after_non_rate_limit_failure_threshold(): void
    {
        Queue::fake();
        Cache::flush();

        config()->set('youtube.yt_dlp_auto_update_enabled', true);
        config()->set('youtube.yt_dlp_failure_threshold', 2);
        config()->set('youtube.yt_dlp_failure_cooldown_minutes', 240);

        $manager = app(YtDlpAutoUpdateManager::class);

        $manager->recordFailure(YtDlpAutoUpdateManager::CHANNEL_UPDATE_JOB, 'temporary extractor failure');
        $manager->recordFailure(YtDlpAutoUpdateManager::CHANNEL_UPDATE_JOB, 'temporary extractor failure');
        Queue::assertNothingPushed();

        $manager->recordFailure(YtDlpAutoUpdateManager::CHANNEL_UPDATE_JOB, 'temporary extractor failure');
        Queue::assertPushedTimes(UpdateYtDlpJob::class, 1);
        Queue::assertPushed(UpdateYtDlpJob::class, function (UpdateYtDlpJob $job): bool {
            return $job->reason === 'failure-threshold:' . YtDlpAutoUpdateManager::CHANNEL_UPDATE_JOB;
        });

        $manager->recordFailure(YtDlpAutoUpdateManager::VIDEO_UPDATE_JOB, 'HTTP Error 429');
        $manager->recordFailure(YtDlpAutoUpdateManager::VIDEO_UPDATE_JOB, 'too many requests');
        Queue::assertPushedTimes(UpdateYtDlpJob::class, 1);
    }
}

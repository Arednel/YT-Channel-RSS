<?php

namespace Tests\Feature\Youtube;

use App\Enums\YoutubeChannelStatus;
use App\Jobs\SyncYoutubeChannelJob;
use App\Models\YoutubeChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class YoutubeMaintenanceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_only_eligible_channels_and_skips_busy_or_active_batch_channels(): void
    {
        Queue::fake();

        $idleChannel = YoutubeChannel::factory()->idle()->forYoutubeId('@maintenance-idle-' . uniqid())->create();
        $busyChannel = YoutubeChannel::factory()->fetchingVideos()->forYoutubeId('@maintenance-busy-' . uniqid())->create();
        $deletingChannel = YoutubeChannel::factory()->deleting()->forYoutubeId('@maintenance-deleting-' . uniqid())->create();
        $activeBatchChannel = YoutubeChannel::factory()->idle()->forYoutubeId('@maintenance-active-batch-' . uniqid())->create();

        $activeBatchId = (string) Str::orderedUuid();
        DB::table('job_batches')->insert([
            'id' => $activeBatchId,
            'name' => 'fixture-active-batch',
            'total_jobs' => 1,
            'pending_jobs' => 1,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => serialize([]),
            'cancelled_at' => null,
            'created_at' => now()->timestamp,
            'finished_at' => null,
        ]);
        $activeBatchChannel->setActiveVideoBatchId($activeBatchId);

        $this->artisan('youtube:maintenance')->assertSuccessful();

        Queue::assertPushedTimes(SyncYoutubeChannelJob::class, 1);
        Queue::assertPushed(SyncYoutubeChannelJob::class, function (SyncYoutubeChannelJob $job) use ($idleChannel): bool {
            return $job->channelId === $idleChannel->id;
        });
        Queue::assertNotPushed(SyncYoutubeChannelJob::class, function (SyncYoutubeChannelJob $job) use ($busyChannel): bool {
            return $job->channelId === $busyChannel->id;
        });
        Queue::assertNotPushed(SyncYoutubeChannelJob::class, function (SyncYoutubeChannelJob $job) use ($deletingChannel): bool {
            return $job->channelId === $deletingChannel->id;
        });
        Queue::assertNotPushed(SyncYoutubeChannelJob::class, function (SyncYoutubeChannelJob $job) use ($activeBatchChannel): bool {
            return $job->channelId === $activeBatchChannel->id;
        });

        $idleChannel->refresh();
        $busyChannel->refresh();
        $deletingChannel->refresh();
        $activeBatchChannel->refresh();

        $this->assertTrue($idleChannel->hasStatus(YoutubeChannelStatus::Queued));
        $this->assertTrue($busyChannel->hasStatus(YoutubeChannelStatus::FetchingVideos));
        $this->assertTrue($deletingChannel->hasStatus(YoutubeChannelStatus::Deleting));
        $this->assertTrue($activeBatchChannel->hasStatus(YoutubeChannelStatus::Idle));
        $this->assertSame($activeBatchId, $activeBatchChannel->active_video_batch_id);
    }
}

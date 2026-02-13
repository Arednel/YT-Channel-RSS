<?php

namespace Tests\Feature\Youtube;

use App\Actions\Youtube\DispatchYoutubeVideoChunkBatchAction;
use App\Enums\YoutubeChannelStatus;
use App\Jobs\FetchYoutubeVideoChunkJob;
use App\Models\YoutubeChannel;
use App\Support\Youtube\VideoChunkPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class DispatchYoutubeVideoChunkBatchActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rolls_back_channel_state_when_batch_dispatch_throws(): void
    {
        $channel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId('@batch-rollback-' . uniqid())
            ->create();

        $plan = new VideoChunkPlan(
            jobs: [
                new FetchYoutubeVideoChunkJob(
                    channelId: $channel->id,
                    youtubeId: $channel->youtube_id,
                    chunkIndex: 0,
                    chunkSize: 1,
                    sourceFile: 'video_id_chunks/chunk_00001.jsonl',
                ),
            ],
            lastVideoId: 'rollback-video-001',
            chunkCount: 1,
            chunkSize: 50,
            queuedVideoCount: 1,
            skippedExistingCount: 0,
            recheckedUpcomingCount: 0,
            encodingSkippedCount: 0,
        );

        Bus::shouldReceive('batch')
            ->once()
            ->andThrow(new \RuntimeException('batch dispatch boom'));

        try {
            app(DispatchYoutubeVideoChunkBatchAction::class)->handle($channel, $plan);
            $this->fail('Expected runtime exception was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('batch dispatch boom', $exception->getMessage());
        }

        $channel->refresh();
        $this->assertTrue($channel->hasStatus(YoutubeChannelStatus::Idle));
        $this->assertNull($channel->active_video_batch_id);
        $this->assertNull($channel->video_fetch_progress_current);
        $this->assertNull($channel->video_fetch_progress_total);
        $this->assertNull($channel->last_video_id);
    }
}

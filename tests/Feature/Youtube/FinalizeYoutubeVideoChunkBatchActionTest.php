<?php

namespace Tests\Feature\Youtube;

use App\Actions\Youtube\FinalizeYoutubeVideoChunkBatchAction;
use App\Enums\YoutubeChannelStatus;
use App\Jobs\BuildYoutubeFeedJob;
use App\Models\YoutubeChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FinalizeYoutubeVideoChunkBatchActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_clears_active_batch_and_dispatches_feed_build_when_batch_succeeds(): void
    {
        Queue::fake();

        $channel = YoutubeChannel::factory()
            ->fetchingVideos()
            ->forYoutubeId('@finalize-batch-' . uniqid())
            ->create([
                'active_video_batch_id' => null,
            ]);

        $batch = Bus::batch([])->withOption('channel_id', $channel->id)->dispatch();
        $channel->setActiveVideoBatchId($batch->id);

        app(FinalizeYoutubeVideoChunkBatchAction::class)->handle($batch);

        $channel->refresh();
        $this->assertNull($channel->active_video_batch_id);
        $this->assertTrue($channel->hasStatus(YoutubeChannelStatus::BuildingFeed));

        Queue::assertPushed(BuildYoutubeFeedJob::class, function (BuildYoutubeFeedJob $job) use ($channel): bool {
            return $job->channelId === $channel->id;
        });
    }
}

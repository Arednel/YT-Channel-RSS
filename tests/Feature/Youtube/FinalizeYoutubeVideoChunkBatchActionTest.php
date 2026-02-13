<?php

namespace Tests\Feature\Youtube;

use App\Actions\Youtube\FinalizeYoutubeVideoChunkBatchAction;
use App\Enums\YoutubeChannelStatus;
use App\Jobs\BuildYoutubeFeedJob;
use App\Models\YoutubeChannel;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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

    public function test_it_marks_channel_failed_and_skips_feed_build_when_batch_has_failures(): void
    {
        Queue::fake();

        $channel = YoutubeChannel::factory()
            ->fetchingVideos()
            ->forYoutubeId('@finalize-batch-failed-' . uniqid())
            ->create([
                'active_video_batch_id' => (string) Str::orderedUuid(),
            ]);

        $batch = new Batch(
            app(QueueFactory::class),
            app(BatchRepository::class),
            (string) Str::orderedUuid(),
            'youtube_video_chunks:' . $channel->youtube_id,
            1,
            0,
            1,
            ['failed-job-id'],
            ['channel_id' => $channel->id],
            CarbonImmutable::now(),
        );

        app(FinalizeYoutubeVideoChunkBatchAction::class)->handle($batch);

        $channel->refresh();
        $this->assertNull($channel->active_video_batch_id);
        $this->assertTrue($channel->hasStatus(YoutubeChannelStatus::Failed));
        $this->assertSame('One or more video chunks failed after retries.', $channel->last_error);
        Queue::assertNotPushed(BuildYoutubeFeedJob::class);
    }
}

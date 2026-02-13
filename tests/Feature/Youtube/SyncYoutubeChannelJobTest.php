<?php

namespace Tests\Feature\Youtube;

use App\Enums\YoutubeChannelStatus;
use App\Jobs\DispatchYoutubeVideoSyncPhaseJob;
use App\Jobs\FetchYoutubeChannelInfoAndVideoListJob;
use App\Jobs\SyncYoutubeChannelJob;
use App\Models\YoutubeChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncYoutubeChannelJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_channel_failed_via_chain_catch_callback(): void
    {
        $channel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId('@sync-chain-catch-' . uniqid())
            ->create([
                'active_video_batch_id' => (string) Str::orderedUuid(),
            ]);
        $channel->markQueuedForSync();

        $catchCallback = null;

        $pendingChain = \Mockery::mock();
        $pendingChain->shouldReceive('catch')
            ->once()
            ->with(\Mockery::on(function ($callback) use (&$catchCallback): bool {
                $catchCallback = $callback;
                return is_callable($callback);
            }))
            ->andReturnSelf();
        $pendingChain->shouldReceive('dispatch')->once();

        Bus::shouldReceive('chain')
            ->once()
            ->with(\Mockery::on(function (array $jobs) use ($channel): bool {
                return count($jobs) === 2
                    && $jobs[0] == new FetchYoutubeChannelInfoAndVideoListJob($channel->id)
                    && $jobs[1] == new DispatchYoutubeVideoSyncPhaseJob($channel->id);
            }))
            ->andReturn($pendingChain);

        (new SyncYoutubeChannelJob($channel->id))->handle();

        $this->assertIsCallable($catchCallback);
        $catchCallback(new \RuntimeException('chain fetch failure'));

        $channel->refresh();
        $this->assertTrue($channel->hasStatus(YoutubeChannelStatus::Failed));
        $this->assertSame('chain fetch failure', $channel->last_error);
        $this->assertNull($channel->active_video_batch_id);
    }

    public function test_failed_callback_marks_channel_failed_and_clears_active_batch(): void
    {
        $channel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId('@sync-failed-callback-' . uniqid())
            ->create([
                'active_video_batch_id' => (string) Str::orderedUuid(),
            ]);
        $channel->markQueuedForSync();

        $job = new SyncYoutubeChannelJob($channel->id);
        $job->failed(new \RuntimeException('sync dispatch failure'));

        $channel->refresh();
        $this->assertTrue($channel->hasStatus(YoutubeChannelStatus::Failed));
        $this->assertSame('sync dispatch failure', $channel->last_error);
        $this->assertNull($channel->active_video_batch_id);
    }
}

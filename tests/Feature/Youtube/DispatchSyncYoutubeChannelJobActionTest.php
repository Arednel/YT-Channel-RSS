<?php

namespace Tests\Feature\Youtube;

use App\Actions\Youtube\DispatchSyncYoutubeChannelJobAction;
use App\Enums\YoutubeChannelStatus;
use App\Jobs\SyncYoutubeChannelJob;
use App\Models\YoutubeChannel;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchSyncYoutubeChannelJobActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_does_not_mark_channel_queued_when_unique_lock_is_already_held(): void
    {
        Queue::fake();

        $channel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId('@dispatch-lock-' . uniqid())
            ->create();

        $job = new SyncYoutubeChannelJob($channel->id);
        $lock = new UniqueLock(app(CacheRepository::class));
        $this->assertTrue($lock->acquire($job));

        try {
            $dispatched = app(DispatchSyncYoutubeChannelJobAction::class)->handle($channel);

            $this->assertFalse($dispatched);

            $channel->refresh();
            $this->assertTrue($channel->hasStatus(YoutubeChannelStatus::Idle));

            Queue::assertNotPushed(SyncYoutubeChannelJob::class);
        } finally {
            $lock->release($job);
        }
    }

    public function test_it_restores_state_and_releases_unique_lock_when_dispatch_fails(): void
    {
        $channel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId('@dispatch-failure-' . uniqid())
            ->create();

        $channel->markFailed('already failed');
        $channel->refresh();

        $dispatcher = $this->mock(QueueingDispatcher::class);
        $dispatcher->shouldReceive('dispatchToQueue')
            ->once()
            ->andThrow(new \RuntimeException('queue unavailable'));

        try {
            app(DispatchSyncYoutubeChannelJobAction::class)->handle($channel);
            $this->fail('Expected runtime exception was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('queue unavailable', $exception->getMessage());
        }

        $channel->refresh();
        $this->assertTrue($channel->hasStatus(YoutubeChannelStatus::Failed));
        $this->assertSame('already failed', $channel->last_error);

        $job = new SyncYoutubeChannelJob($channel->id);
        $lock = new UniqueLock(app(CacheRepository::class));
        $this->assertTrue($lock->acquire($job));
        $lock->release($job);
    }
}

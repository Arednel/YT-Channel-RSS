<?php

namespace Tests\Unit\Youtube;

use App\Jobs\DispatchYoutubeVideoSyncPhaseJob;
use App\Jobs\FetchYoutubeChannelInfoAndVideoListJob;
use App\Jobs\SyncYoutubeChannelJob;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SyncYoutubeChannelJobChainTest extends TestCase
{
    public function test_it_dispatches_chain_in_expected_sync_order(): void
    {
        Bus::fake();

        $channelId = 12345;

        (new SyncYoutubeChannelJob($channelId))->handle();

        Bus::assertChained([
            new FetchYoutubeChannelInfoAndVideoListJob($channelId),
            new DispatchYoutubeVideoSyncPhaseJob($channelId),
        ]);
    }
}

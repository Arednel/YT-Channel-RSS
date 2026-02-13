<?php

namespace Tests\Feature\Youtube;

use App\Enums\YoutubeChannelStatus;
use App\Models\YoutubeChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class YoutubeChannelVideoFetchProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_increments_video_fetch_progress_and_clamps_to_total(): void
    {
        $channel = YoutubeChannel::factory()
            ->forYoutubeId('@progress-increment-' . uniqid())
            ->create([
                'status' => YoutubeChannelStatus::FetchingVideos,
                'video_fetch_progress_current' => 0,
                'video_fetch_progress_total' => 150,
            ]);

        $channel->incrementVideoFetchProgress(50);
        $channel->refresh();
        $this->assertSame(50, $channel->video_fetch_progress_current);

        $channel->incrementVideoFetchProgress(200);
        $channel->refresh();
        $this->assertSame(150, $channel->video_fetch_progress_current);
    }
}

<?php

namespace Tests\Unit\Youtube;

use App\Enums\YoutubeChannelStatus;
use App\Models\YoutubeChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class YoutubeChannelStatusLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_formats_status_label_with_video_fetch_progress(): void
    {
        $channel = YoutubeChannel::factory()
            ->forYoutubeId('@progress-label-' . uniqid())
            ->create([
                'status' => YoutubeChannelStatus::FetchingVideos,
                'video_fetch_progress_current' => 50,
                'video_fetch_progress_total' => 150,
            ]);

        $this->assertSame('fetching videos (50 out of 150)', $channel->status_label);
    }

    public function test_it_does_not_append_progress_to_fetching_video_list_status_label(): void
    {
        $channel = YoutubeChannel::factory()
            ->forYoutubeId('@progress-list-label-' . uniqid())
            ->create([
                'status' => YoutubeChannelStatus::FetchingVideoList,
                'video_fetch_progress_current' => 1,
                'video_fetch_progress_total' => 3,
            ]);

        $this->assertSame('fetching video list', $channel->status_label);
    }
}

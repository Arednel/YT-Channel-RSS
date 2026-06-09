<?php

namespace Tests\Feature\Youtube;

use App\Models\YoutubeChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class YoutubeChannelFeedRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_serves_feed_for_dotted_handle(): void
    {
        Storage::fake('public');

        $youtubeId = '@C.H.A.N.N.E.L.';

        YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId($youtubeId)
            ->create();

        Storage::disk('public')->put(
            'feeds/' . $youtubeId . '.xml',
            '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom"></feed>'
        );

        $this->get('/feeds/' . $youtubeId . '.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/atom+xml; charset=UTF-8');
    }
}

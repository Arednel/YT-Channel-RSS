<?php

namespace Tests\Feature;

use App\Livewire\ChannelPaginationSettings;
use App\Livewire\YoutubeRssChannels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class YoutubeRssChannelsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_channels_page_mounts_channel_app_with_required_assets(): void
    {
        $response = $this->get(route('index'))
            ->assertOk()
            ->assertViewIs('Index')
            ->assertSeeLivewire(YoutubeRssChannels::class)
            ->assertDontSeeLivewire(ChannelPaginationSettings::class);

        $html = $response->getContent();

        $this->assertStringContainsString('css/shared.css?v=', $html);
        $this->assertStringContainsString('css/index.css?v=', $html);
        $this->assertStringContainsString('js/app-ui.js?v=', $html);
        $this->assertStringContainsString('js/rss-copy-to-clipboard.js?v=', $html);
        $this->assertStringContainsString('js/localize-datetime.js?v=', $html);
        $this->assertStringContainsString('href="'.route('options', [], false).'"', $html);

        $this->assertStringNotContainsString('css/options.css?v=', $html);
        $this->assertStringNotContainsString('templatemo-glass-admin', $html);
    }

    public function test_channels_search_is_normalized(): void
    {
        Livewire::test(YoutubeRssChannels::class, ['search' => '  alpha   beta  '])
            ->assertSet('search', 'alpha beta')
            ->set('search', " gamma\t delta ")
            ->assertSet('search', 'gamma delta');
    }
}

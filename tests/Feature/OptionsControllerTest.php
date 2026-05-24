<?php

namespace Tests\Feature;

use App\Livewire\ChannelPaginationSettings;
use App\Livewire\YoutubeRssChannels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OptionsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_options_page_mounts_pagination_settings_with_required_assets(): void
    {
        $response = $this->get(route('options'))
            ->assertOk()
            ->assertViewIs('Options')
            ->assertSeeLivewire(ChannelPaginationSettings::class)
            ->assertDontSeeLivewire(YoutubeRssChannels::class);

        $html = $response->getContent();

        $this->assertStringContainsString('css/shared.css?v=', $html);
        $this->assertStringContainsString('css/options.css?v=', $html);
        $this->assertStringContainsString('js/app-ui.js?v=', $html);
        $this->assertStringContainsString('href="'.route('index', [], false).'"', $html);
        $this->assertStringContainsString('href="'.route('options', [], false).'"', $html);
        $this->assertStringContainsString('Channel table pagination', $html);

        $this->assertStringNotContainsString('css/index.css?v=', $html);
        $this->assertStringNotContainsString('js/rss-copy-to-clipboard.js?v=', $html);
        $this->assertStringNotContainsString('js/localize-datetime.js?v=', $html);
        $this->assertStringNotContainsString('templatemo-glass-admin', $html);
    }
}

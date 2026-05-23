<?php

namespace Tests\Feature;

use App\Livewire\YoutubeRssChannels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class YoutubeRssChannelsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_channels_page_uses_channels_wording(): void
    {
        $this->get(route('index'))
            ->assertOk()
            ->assertSee('Channels')
            ->assertSee('css/templatemo-glass-admin-style.css?v=')
            ->assertSee('js/templatemo-glass-admin-script.js?v=')
            ->assertSee('js/rss-copy-to-clipboard.js?v=')
            ->assertSee('js/localize-datetime.js?v=')
            ->assertSee('<footer class="site-footer">', false)
            ->assertSee('YouTube RSS');
    }

    public function test_channels_search_is_normalized(): void
    {
        Livewire::test(YoutubeRssChannels::class, ['search' => '  alpha   beta  '])
            ->assertSet('search', 'alpha beta')
            ->set('search', " gamma\t delta ")
            ->assertSet('search', 'gamma delta');
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OptionsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_options_page_renders_channel_pagination_settings(): void
    {
        $this->get(route('options'))
            ->assertOk()
            ->assertSee('Options')
            ->assertSee('Channels')
            ->assertSee('Channel table pagination')
            ->assertSee('Pagination')
            ->assertSee('css/templatemo-glass-admin-style.css?v=')
            ->assertSee('js/templatemo-glass-admin-script.js?v=')
            ->assertSee('<footer class="site-footer">', false)
            ->assertSee('YouTube RSS')
            ->assertSee('href="' . route('index', [], false) . '"', false)
            ->assertSee('href="' . route('options', [], false) . '"', false);
    }
}

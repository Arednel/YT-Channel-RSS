<?php

namespace Tests\Feature;

use App\Livewire\ChannelPaginationSettings;
use App\Models\Option;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ChannelPaginationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_component_defaults_to_100_without_saved_option(): void
    {
        Livewire::test(ChannelPaginationSettings::class)
            ->assertSet('mode', '100')
            ->assertSet('customValue', '');
    }

    public function test_settings_component_saves_fixed_custom_and_unlimited_values(): void
    {
        Livewire::test(ChannelPaginationSettings::class)
            ->set('mode', '250')
            ->call('save')
            ->assertSee('Channel pagination setting saved.');

        $this->assertSame(250, Option::channelsPerPage());
        $this->assertSame('250', DB::table('options')->where('key', Option::CHANNELS_PER_PAGE)->value('value'));

        Livewire::test(ChannelPaginationSettings::class)
            ->set('mode', 'custom')
            ->set('customValue', '12345')
            ->call('save')
            ->assertSet('mode', 'custom')
            ->assertSet('customValue', '12345');

        $this->assertSame(12345, Option::channelsPerPage());
        $this->assertSame('12345', DB::table('options')->where('key', Option::CHANNELS_PER_PAGE)->value('value'));

        Livewire::test(ChannelPaginationSettings::class)
            ->set('mode', Option::CHANNELS_PER_PAGE_UNLIMITED)
            ->call('save');

        $this->assertSame(Option::CHANNELS_PER_PAGE_UNLIMITED, Option::channelsPerPage());
        $this->assertSame(
            Option::CHANNELS_PER_PAGE_UNLIMITED,
            DB::table('options')->where('key', Option::CHANNELS_PER_PAGE)->value('value')
        );
    }

    public function test_settings_component_saves_only_when_submitted(): void
    {
        $component = Livewire::test(ChannelPaginationSettings::class)
            ->set('mode', '250');

        $this->assertSame(Option::DEFAULT_CHANNELS_PER_PAGE, Option::channelsPerPage());

        $component->call('save');

        $this->assertSame(250, Option::channelsPerPage());
    }

    public function test_settings_component_shows_custom_input_only_for_custom_mode(): void
    {
        Livewire::test(ChannelPaginationSettings::class)
            ->assertDontSee('Custom channels per page')
            ->set('mode', 'custom')
            ->assertSee('Custom channels per page');
    }

    public function test_settings_component_rejects_invalid_custom_values(): void
    {
        foreach (['0', '-1', '1.5'] as $customValue) {
            Livewire::test(ChannelPaginationSettings::class)
                ->set('mode', 'custom')
                ->set('customValue', $customValue)
                ->call('save')
                ->assertHasErrors(['customValue']);
        }

        $this->assertSame(Option::DEFAULT_CHANNELS_PER_PAGE, Option::channelsPerPage());
    }

    public function test_settings_component_ignores_stale_custom_value_for_non_custom_modes(): void
    {
        Livewire::test(ChannelPaginationSettings::class)
            ->set('mode', 'custom')
            ->set('customValue', '0')
            ->set('mode', '250')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('mode', '250')
            ->assertSet('customValue', '');

        $this->assertSame(250, Option::channelsPerPage());

        Livewire::test(ChannelPaginationSettings::class)
            ->set('mode', 'custom')
            ->set('customValue', '-1')
            ->set('mode', Option::CHANNELS_PER_PAGE_UNLIMITED)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('mode', Option::CHANNELS_PER_PAGE_UNLIMITED)
            ->assertSet('customValue', '');

        $this->assertSame(Option::CHANNELS_PER_PAGE_UNLIMITED, Option::channelsPerPage());
    }

    public function test_settings_component_keeps_the_fixed_choices_custom_and_unlimited_options(): void
    {
        $component = Livewire::test(ChannelPaginationSettings::class);

        foreach ([10, 25, 50, 100, 250, 500, 1000] as $value) {
            $component->assertSee("{$value} channels per page");
        }

        $component
            ->assertSee('Custom value')
            ->assertSee('Unlimited');
    }
}

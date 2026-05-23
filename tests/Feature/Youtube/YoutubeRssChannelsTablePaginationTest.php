<?php

namespace Tests\Feature\Youtube;

use App\Jobs\DeleteYoutubeChannelJob;
use App\Livewire\YoutubeRssChannelsTable;
use App\Models\Option;
use App\Models\YoutubeChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class YoutubeRssChannelsTablePaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_uses_default_page_size_when_no_option_exists(): void
    {
        foreach (range(1, 101) as $number) {
            $this->createChannel($number);
        }

        Livewire::test(YoutubeRssChannelsTable::class)
            ->assertSee('Channel 100')
            ->assertDontSee('Channel 101')
            ->assertSee('Showing 1-100 of 101 channels');
    }

    public function test_table_uses_fixed_custom_and_unlimited_page_size_options(): void
    {
        foreach (range(1, 5) as $number) {
            $this->createChannel($number);
        }

        Option::setChannelsPerPage(2);

        Livewire::test(YoutubeRssChannelsTable::class)
            ->assertSee('Channel 001')
            ->assertSee('Channel 002')
            ->assertDontSee('Channel 003')
            ->call('nextPage')
            ->assertSee('Channel 003')
            ->assertSee('Channel 004')
            ->assertDontSee('Channel 001');

        Option::setChannelsPerPage(3);

        Livewire::test(YoutubeRssChannelsTable::class)
            ->assertSee('Channel 001')
            ->assertSee('Channel 003')
            ->assertDontSee('Channel 004')
            ->assertSee('Showing 1-3 of 5 channels');

        Option::setChannelsPerPage(Option::CHANNELS_PER_PAGE_UNLIMITED);

        Livewire::test(YoutubeRssChannelsTable::class)
            ->assertSee('Channel 001')
            ->assertSee('Channel 005')
            ->assertSee('Showing all 5 channels')
            ->assertDontSee('Next');
    }

    public function test_built_in_pagination_methods_change_visible_page(): void
    {
        Option::setChannelsPerPage(2);

        foreach (range(1, 5) as $number) {
            $this->createChannel($number);
        }

        Livewire::test(YoutubeRssChannelsTable::class)
            ->assertSee('Channel 001')
            ->assertDontSee('Channel 003')
            ->call('nextPage')
            ->assertSee('Channel 003')
            ->assertDontSee('Channel 001')
            ->call('previousPage')
            ->assertSee('Channel 001')
            ->assertDontSee('Channel 003')
            ->call('gotoPage', 3)
            ->assertSee('Channel 005')
            ->assertDontSee('Channel 001');
    }

    public function test_search_and_sort_changes_reset_pagination_to_first_page(): void
    {
        Option::setChannelsPerPage(1);

        YoutubeChannel::factory()->create([
            'youtube_id' => '@reset-b',
            'channel_name' => 'Reset B',
        ]);
        YoutubeChannel::factory()->create([
            'youtube_id' => '@reset-a',
            'channel_name' => 'Reset A',
        ]);

        Livewire::test(YoutubeRssChannelsTable::class)
            ->call('nextPage')
            ->assertSee('Reset A')
            ->set('search', 'Reset B')
            ->assertSee('Reset B')
            ->assertDontSee('Reset A');

        Livewire::test(YoutubeRssChannelsTable::class)
            ->call('nextPage')
            ->assertSee('Reset A')
            ->call('sortBy', 'channel')
            ->assertSee('Reset A')
            ->assertDontSee('Reset B');
    }

    public function test_delete_modal_lists_all_channels_not_only_the_current_page(): void
    {
        Option::setChannelsPerPage(1);

        $first = $this->createChannel(1, 'Delete Choice 001');
        $second = $this->createChannel(2, 'Delete Choice 002');

        Livewire::test(YoutubeRssChannelsTable::class)
            ->call('openDeleteModal')
            ->assertSee('Delete Choice 001 - '.$first->youtube_id)
            ->assertSee('Delete Choice 002 - '.$second->youtube_id);
    }

    public function test_deleting_selected_channel_resets_pagination_to_first_page(): void
    {
        Queue::fake();
        Option::setChannelsPerPage(1);

        $first = $this->createChannel(1, 'Delete Reset 001');
        $second = $this->createChannel(2, 'Delete Reset 002');

        Livewire::test(YoutubeRssChannelsTable::class)
            ->assertSee('Delete Reset 001')
            ->call('nextPage')
            ->assertSee('Delete Reset 002')
            ->call('openDeleteModal')
            ->set('deleteChannelId', $second->id)
            ->call('promptDelete')
            ->call('deleteSelected')
            ->assertSee('Delete Reset 001')
            ->assertDontSee('Delete Reset 002');

        Queue::assertPushed(DeleteYoutubeChannelJob::class, static fn (DeleteYoutubeChannelJob $job): bool => $job->channelId === $second->id);
        $this->assertDatabaseHas('youtube_channels', [
            'id' => $first->id,
        ]);
    }

    private function createChannel(int $number, ?string $name = null): YoutubeChannel
    {
        $padded = str_pad((string) $number, 3, '0', STR_PAD_LEFT);

        return YoutubeChannel::factory()->create([
            'youtube_id' => '@channel-'.$padded,
            'channel_name' => $name ?? 'Channel '.$padded,
        ]);
    }
}

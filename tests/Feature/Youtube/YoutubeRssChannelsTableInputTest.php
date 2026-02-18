<?php

namespace Tests\Feature\Youtube;

use App\Actions\Youtube\DispatchSyncYoutubeChannelJobAction;
use App\Livewire\YoutubeRssChannelsTable;
use App\Models\YoutubeChannel;
use App\Support\YoutubeBatchManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class YoutubeRssChannelsTableInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_accepts_raw_handle_input_in_livewire_save_flow(): void
    {
        $this->mock(DispatchSyncYoutubeChannelJobAction::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')->once()->andReturn(true);
        });

        $component = $this->makeComponent();
        $component->channelUrl = " \t@MateusGuimar%C3%A3es ";
        $component->save();

        $this->assertFalse($component->getErrorBag()->has('channelUrl'));

        $this->assertDatabaseHas('youtube_channels', [
            'youtube_id' => rawurldecode('@MateusGuimar%C3%A3es'),
            'youtube_channel_id' => null,
        ]);
    }

    public function test_it_accepts_uc_channel_url_and_stores_channel_id_in_livewire_save_flow(): void
    {
        $this->mock(DispatchSyncYoutubeChannelJobAction::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')->once()->andReturn(true);
        });

        $channelId = 'UCABCDEFGHIJKLMN_OPQRSTU';

        $component = $this->makeComponent();
        $component->channelUrl = 'https://www.youtube.com/channel/' . $channelId;
        $component->save();

        $this->assertFalse($component->getErrorBag()->has('channelUrl'));

        $this->assertDatabaseHas('youtube_channels', [
            'youtube_id' => $channelId,
            'youtube_channel_id' => $channelId,
        ]);
    }

    public function test_it_rejects_duplicate_when_same_channel_exists_by_uc_identifier(): void
    {
        $channelId = 'UCABCDEFGHIJKLMN_OPQRSTU';

        YoutubeChannel::factory()->create([
            'youtube_id' => '@existing-channel',
            'youtube_channel_id' => $channelId,
        ]);

        $this->mock(DispatchSyncYoutubeChannelJobAction::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('handle');
        });

        $component = $this->makeComponent();
        $component->channelUrl = 'https://www.youtube.com/channel/' . $channelId;
        $component->save();

        $this->assertTrue($component->getErrorBag()->has('channelUrl'));

        $this->assertSame(1, YoutubeChannel::query()->count());
    }

    private function makeComponent(): YoutubeRssChannelsTable
    {
        $component = app(YoutubeRssChannelsTable::class);
        $component->boot(
            app(YoutubeBatchManager::class),
            app(DispatchSyncYoutubeChannelJobAction::class),
        );

        return $component;
    }
}

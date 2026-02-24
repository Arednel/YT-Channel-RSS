<?php

namespace Tests\Feature\Youtube;

use App\Actions\Youtube\DispatchSyncYoutubeChannelJobAction;
use App\Livewire\YoutubeRssChannelsTable;
use App\Models\YoutubeChannel;
use App\Support\YoutubeBatchManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class YoutubeRssChannelsTableInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_accepts_handle_url_with_tail_path_and_stores_normalized_handle_in_livewire_save_flow(): void
    {
        $this->mock(DispatchSyncYoutubeChannelJobAction::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')->once()->andReturn(true);
        });

        $component = $this->makeComponent();
        $component->channelUrl = 'https://youtube.com/@MateusGuimar%C3%A3es/videos';
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

    #[DataProvider('invalidChannelInputProvider')]
    public function test_it_rejects_invalid_channel_input_when_only_links_are_allowed(string $input): void
    {
        $this->mock(DispatchSyncYoutubeChannelJobAction::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('handle');
        });

        $component = $this->makeComponent();
        $component->channelUrl = $input;
        try {
            $component->save();
            $this->fail('Expected channelUrl validation to fail for unsupported input: ' . $input);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('channelUrl', $exception->errors());
        }

        $this->assertSame(0, YoutubeChannel::query()->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidChannelInputProvider(): array
    {
        return [
            'raw_handle' => ['@MateusGuimar%C3%A3es'],
            'raw_uc_id' => ['UCABCDEFGHIJKLMN_OPQRSTU'],
            'watch_url' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
        ];
    }

    public function test_matching_reference_scope_matches_handle_and_channel_id_forms(): void
    {
        $channelId = 'UCABCDEFGHIJKLMN_OPQRSTU';
        YoutubeChannel::factory()->create([
            'youtube_id' => '@scope-handle',
            'youtube_channel_id' => $channelId,
        ]);

        $this->assertTrue(
            YoutubeChannel::query()->matchingReference('@scope-handle')->exists()
        );

        $this->assertTrue(
            YoutubeChannel::query()->matchingReference('@other', $channelId)->exists()
        );

        $this->assertFalse(
            YoutubeChannel::query()->matchingReference('@missing')->exists()
        );
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

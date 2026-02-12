<?php

namespace Tests\Feature\Youtube;

use App\Actions\Youtube\RunYoutubeChannelSyncAction;
use App\Enums\YoutubeChannelStatus;
use App\Models\YoutubeChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RunYoutubeChannelSyncActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_runs_real_channel_sync_workflow_with_configured_channel(): void
    {
        if (! $this->networkTestsEnabled()) {
            $this->markTestSkipped('Set YOUTUBE_TESTS_WITH_NETWORK=true in phpunit.xml to run network integration tests.');
        }

        $youtubeId = $this->configuredRealTestChannel();
        if ($youtubeId === null) {
            $this->markTestSkipped('Set YOUTUBE_TEST_REAL_CHANNEL in phpunit.xml to run real channel-sync integration test.');
        }

        $channel = YoutubeChannel::query()->create([
            'youtube_id' => $youtubeId,
            'status' => YoutubeChannelStatus::Idle,
        ]);

        app(RunYoutubeChannelSyncAction::class)->handle($channel->id);

        $channel->refresh();

        $outputDirectory = base_path('python/yt-dlp_jsons/' . $youtubeId);
        $channelJsonPath = $outputDirectory . '/channel.json';
        $videosJsonPath = $outputDirectory . '/videos.jsonl';

        $this->assertFileExists($channelJsonPath);
        $this->assertFileExists($videosJsonPath);

        $batchId = $channel->active_video_batch_id;
        if (is_string($batchId) && $batchId !== '') {
            $this->assertTrue($channel->hasStatus(YoutubeChannelStatus::FetchingVideos));
            $this->assertNotNull($channel->last_video_id);
            $this->assertDatabaseHas('job_batches', [
                'id' => $batchId,
                'name' => 'youtube_video_chunks:' . $youtubeId,
            ]);

            return;
        }

        $this->assertNull($channel->active_video_batch_id);
        $this->assertSame(0, (int) DB::table('job_batches')->count());
        $this->assertTrue(
            $channel->hasStatus(YoutubeChannelStatus::BuildingFeed)
            || $channel->hasStatus(YoutubeChannelStatus::Idle)
        );
    }

    private function configuredRealTestChannel(): ?string
    {
        $configured = trim((string) env('YOUTUBE_TEST_REAL_CHANNEL', ''));
        if ($configured === '') {
            return null;
        }

        $withoutDomain = preg_replace('~^https?://(?:www\.)?youtube\.com/~i', '', $configured);
        $normalized = ltrim((string) $withoutDomain, '/');

        return $normalized !== '' ? $normalized : null;
    }

    private function networkTestsEnabled(): bool
    {
        $raw = strtolower(trim((string) env('YOUTUBE_TESTS_WITH_NETWORK', 'false')));

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }
}

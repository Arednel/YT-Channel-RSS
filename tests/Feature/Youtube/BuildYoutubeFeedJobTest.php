<?php

namespace Tests\Feature\Youtube;

use App\Jobs\BuildYoutubeFeedJob;
use App\Models\YoutubeChannel;
use App\Models\YoutubeVideo;
use App\Support\YoutubeFeedXmlBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BuildYoutubeFeedJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_expected_feed_xml_contract_for_channel_and_videos(): void
    {
        Storage::fake('public');

        $youtubeId = '@feed-contract-' . uniqid();
        $channel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId($youtubeId)
            ->named('Feed Contract Channel')
            ->create();

        YoutubeVideo::factory()
            ->for($channel, 'channel')
            ->forVideoId('feed-video-001')
            ->titled('Feed Video 1')
            ->withDescription('Feed description 1')
            ->publishedAt(now()->subDay())
            ->create();

        YoutubeVideo::factory()
            ->for($channel, 'channel')
            ->forVideoId('feed-video-002')
            ->titled('Feed Video 2')
            ->withDescription('Feed description 2')
            ->publishedAt(now())
            ->create();

        $channelJsonPath = base_path('python/yt-dlp_jsons/' . $youtubeId . '/channel.json');
        File::ensureDirectoryExists(dirname($channelJsonPath));
        File::put($channelJsonPath, json_encode([
            'channel_id' => 'UCFEEDCONTRACT123',
            'channel' => 'Feed Contract Channel',
        ], JSON_THROW_ON_ERROR));

        try {
            $job = new BuildYoutubeFeedJob($channel->id);
            $job->handle(app(YoutubeFeedXmlBuilder::class));

            $channel->refresh();
            $this->assertTrue($channel->isIdle());

            $relativeFeedPath = 'feeds/' . $youtubeId . '.xml';
            $this->assertTrue(Storage::disk('public')->exists($relativeFeedPath));

            $xml = Storage::disk('public')->get($relativeFeedPath);
            $this->assertStringContainsString('<feed ', $xml);
            $this->assertStringContainsString('xmlns="http://www.w3.org/2005/Atom"', $xml);
            $this->assertStringContainsString('UCFEEDCONTRACT123</yt:channelId>', $xml);
            $this->assertStringContainsString('<id>yt:channel:UCFEEDCONTRACT123</id>', $xml);
            $this->assertStringContainsString('feed-video-001</yt:videoId>', $xml);
            $this->assertStringContainsString('feed-video-002</yt:videoId>', $xml);
            $this->assertStringContainsString('Feed Contract Channel', $xml);
        } finally {
            File::deleteDirectory(base_path('python/yt-dlp_jsons/' . $youtubeId));
        }
    }
}

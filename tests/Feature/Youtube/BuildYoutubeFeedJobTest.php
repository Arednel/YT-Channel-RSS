<?php

namespace Tests\Feature\Youtube;

use App\Actions\Youtube\RunYoutubeChannelSyncAction;
use App\Enums\YoutubeChannelStatus;
use App\Jobs\BuildYoutubeFeedJob;
use App\Models\YoutubeChannel;
use App\Models\YoutubeVideo;
use App\Support\PythonBinaryResolver;
use App\Support\Youtube\YoutubeChannelReference;
use App\Support\YoutubeFeedXmlBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BuildYoutubeFeedJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_compares_generated_feed_against_real_feed_for_channel_and_first_15_entries_with_offline_live_skip(): void
    {
        if (! $this->networkTestsEnabled()) {
            $this->markTestSkipped('Set YOUTUBE_TESTS_WITH_NETWORK=true in phpunit.xml to run network integration tests.');
        }

        $channelId = $this->configuredRealFeedChannelId();
        if ($channelId === null) {
            $this->markTestSkipped('Set YOUTUBE_TEST_REAL_FEED_URL to a YouTube feed URL in phpunit.xml to run real feed comparison test.');
        }

        Storage::fake('public');

        $realXml = $this->fetchRealYoutubeFeedXml($channelId);
        $allVideoIds = $this->extractVideoIds($realXml);

        $this->assertNotEmpty($allVideoIds, 'Expected at least one entry from real YouTube feed.');

        $videoIdsToImport = array_slice($allVideoIds, 0, 15);
        $expectedSnapshot = $this->extractFeedSnapshot($realXml, $videoIdsToImport);
        $importSnapshot = $this->extractFeedSnapshot($realXml, $videoIdsToImport);

        $channel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId($channelId)
            ->named($expectedSnapshot['channel']['title'])
            ->create([
                'youtube_channel_id' => $channelId,
            ]);

        foreach ($importSnapshot['videos'] as $expectedVideo) {
            YoutubeVideo::factory()
                ->for($channel, 'channel')
                ->create([
                    'youtube_video_id' => $expectedVideo['video_id'],
                    'video_title' => $expectedVideo['title'],
                    'published_date' => $expectedVideo['published'],
                    'updated_date' => $expectedVideo['updated'],
                    'media_title' => $expectedVideo['media_title'],
                    'media_content_url' => $expectedVideo['media_content_url'],
                    'media_thumbnail_url' => $expectedVideo['media_thumbnail_url'],
                    'media_description' => $expectedVideo['media_description'],
                ]);
        }

        $job = new BuildYoutubeFeedJob($channel->id);
        $job->handle(
            app(YoutubeFeedXmlBuilder::class),
            app(RunYoutubeChannelSyncAction::class)
        );

        $generatedXml = Storage::disk('public')->get('feeds/' . $channel->youtube_id . '.xml');
        $actualSnapshot = $this->extractFeedSnapshot($generatedXml, $videoIdsToImport, false);

        // 1) General info lines.
        $this->assertSame(
            $this->normalizeXmlLine($this->extractXmlDeclarationLine($realXml)),
            $this->normalizeXmlLine($this->extractXmlDeclarationLine($generatedXml))
        );
        $this->assertSame(
            $this->normalizeXmlLine($this->extractFeedOpenLine($realXml)),
            $this->normalizeXmlLine($this->extractFeedOpenLine($generatedXml))
        );

        // 2) Channel info.
        $this->assertSame(
            $this->normalizeFeedIdForComparison($expectedSnapshot['channel']['feed_id']),
            $this->normalizeFeedIdForComparison($actualSnapshot['channel']['feed_id'])
        );
        $this->assertSame(
            $this->normalizeChannelIdForComparison($expectedSnapshot['channel']['channel_id']),
            $this->normalizeChannelIdForComparison($actualSnapshot['channel']['channel_id'])
        );
        $this->assertSame($expectedSnapshot['channel']['title'], $actualSnapshot['channel']['title']);
        $this->assertSame($expectedSnapshot['channel']['alternate_url'], $actualSnapshot['channel']['alternate_url']);
        $this->assertSame($expectedSnapshot['channel']['author_name'], $actualSnapshot['channel']['author_name']);
        $this->assertSame($expectedSnapshot['channel']['author_uri'], $actualSnapshot['channel']['author_uri']);
        $this->assertNotEmpty($actualSnapshot['channel']['published']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+\-]\d{2}:\d{2})$/',
            $actualSnapshot['channel']['published']
        );

        // 3) Per-entry comparison.
        $actualVideosById = [];
        foreach ($actualSnapshot['videos'] as $actualVideo) {
            $actualVideosById[$actualVideo['video_id']] = $actualVideo;
        }

        $comparedCount = 0;

        foreach ($expectedSnapshot['videos'] as $expectedVideo) {
            $videoId = $expectedVideo['video_id'];
            $actualVideo = $actualVideosById[$videoId] ?? null;

            if (! is_array($actualVideo)) {
                if ($this->isLikelyLiveStreamOfflineVideo($videoId)) {
                    continue;
                }

                $this->fail("Expected feed entry for video id [{$videoId}] was not found in generated XML.");
            }

            $this->assertSame($expectedVideo['entry_id'], $actualVideo['entry_id']);
            $this->assertSame($expectedVideo['video_id'], $actualVideo['video_id']);
            $this->assertSame(
                $this->normalizeChannelIdForComparison($expectedVideo['channel_id']),
                $this->normalizeChannelIdForComparison($actualVideo['channel_id'])
            );
            $this->assertSame($expectedVideo['title'], $actualVideo['title']);
            $this->assertSame($expectedVideo['entry_url'], $actualVideo['entry_url']);
            $this->assertSame($expectedVideo['author_name'], $actualVideo['author_name']);
            $this->assertSame($expectedVideo['author_uri'], $actualVideo['author_uri']);
            $this->assertSame($expectedVideo['published'], $actualVideo['published']);
            $this->assertSame($expectedVideo['media_title'], $actualVideo['media_title']);
            $this->assertSame($expectedVideo['media_content_url'], $actualVideo['media_content_url']);
            $this->assertSame($expectedVideo['media_description'], $actualVideo['media_description']);

            $comparedCount++;
        }

        $this->assertGreaterThan(0, $comparedCount, 'No comparable entries remained after filtering offline live streams.');
    }

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
            'channel_id' => 'UCFEEDCONTRACT1234567890',
            'channel' => 'Feed Contract Channel',
        ], JSON_THROW_ON_ERROR));

        try {
            $job = new BuildYoutubeFeedJob($channel->id);
            $job->handle(
                app(YoutubeFeedXmlBuilder::class),
                app(RunYoutubeChannelSyncAction::class)
            );

            $channel->refresh();
            $this->assertTrue($channel->isIdle());

            $relativeFeedPath = 'feeds/' . $youtubeId . '.xml';
            $this->assertTrue(Storage::disk('public')->exists($relativeFeedPath));

            $xml = Storage::disk('public')->get($relativeFeedPath);
            $this->assertStringContainsString('<feed ', $xml);
            $this->assertStringContainsString('xmlns="http://www.w3.org/2005/Atom"', $xml);
            $this->assertStringContainsString('UCFEEDCONTRACT1234567890</yt:channelId>', $xml);
            $this->assertStringContainsString('<id>yt:channel:UCFEEDCONTRACT1234567890</id>', $xml);
            $this->assertStringContainsString('feed-video-001</yt:videoId>', $xml);
            $this->assertStringContainsString('feed-video-002</yt:videoId>', $xml);
            $this->assertStringContainsString('Feed Contract Channel', $xml);
        } finally {
            File::deleteDirectory(base_path('python/yt-dlp_jsons/' . $youtubeId));
        }
    }

    public function test_failed_marks_channel_feed_failed(): void
    {
        $channel = YoutubeChannel::factory()
            ->forYoutubeId('@feed-failed-' . uniqid())
            ->create([
                'status' => YoutubeChannelStatus::BuildingFeed,
            ]);

        $job = new BuildYoutubeFeedJob($channel->id);
        $job->failed(new \RuntimeException('feed write failed'));

        $channel->refresh();
        $this->assertTrue($channel->hasStatus(YoutubeChannelStatus::FeedFailed));
        $this->assertSame('Feed build failed: feed write failed', $channel->last_error);
    }

    /**
     * @param list<string> $videoIds
     * @return array{
     *     channel: array{
     *         feed_id: string,
     *         channel_id: string,
     *         title: string,
     *         alternate_url: string,
     *         author_name: string,
     *         author_uri: string,
     *         published: string,
     *         updated: string
     *     },
     *     videos: list<array{
     *         video_id: string,
     *         entry_id: string,
     *         channel_id: string,
     *         title: string,
     *         entry_url: string,
     *         author_name: string,
     *         author_uri: string,
     *         published: string,
     *         updated: string,
     *         media_title: string,
     *         media_content_url: string,
     *         media_thumbnail_url: string,
     *         media_description: string
     *     }>
     * }
     */
    private function extractFeedSnapshot(string $xml, array $videoIds, bool $requireEntries = true): array
    {
        $feed = new \SimpleXMLElement($xml);
        $namespaces = $feed->getNamespaces(true);

        $ytNamespace = $namespaces['yt'];
        $mediaNamespace = $namespaces['media'];
        $atomNamespace = $namespaces[''] ?? null;
        $atom = $atomNamespace !== null ? $feed->children($atomNamespace) : $feed;
        $channelAlternateUrl = '';
        foreach ($atom->link as $linkNode) {
            $attributes = $linkNode->attributes();
            if ((string) ($attributes['rel'] ?? '') === 'alternate') {
                $channelAlternateUrl = (string) ($attributes['href'] ?? '');
                break;
            }
        }

        $snapshot = [
            'channel' => [
                'feed_id' => (string) $atom->id,
                'channel_id' => (string) $feed->children($ytNamespace)->channelId,
                'title' => (string) $atom->title,
                'alternate_url' => $channelAlternateUrl,
                'author_name' => (string) $atom->author->name,
                'author_uri' => (string) $atom->author->uri,
                'published' => (string) $atom->published,
                'updated' => (string) $atom->updated,
            ],
            'videos' => [],
        ];

        foreach ($videoIds as $videoId) {
            $entry = $this->findEntryByVideoId($atom, $ytNamespace, $videoId);
            if (! $entry instanceof \SimpleXMLElement) {
                if ($requireEntries) {
                    $this->fail("Expected entry for video id [{$videoId}] was not found.");
                }

                continue;
            }

            $entryMedia = $entry->children($mediaNamespace)->group->children($mediaNamespace);
            $mediaContentAttributes = $entryMedia->content->attributes();
            $mediaThumbnailAttributes = $entryMedia->thumbnail->attributes();
            $entryLinkUrl = '';
            foreach ($entry->link as $linkNode) {
                $attributes = $linkNode->attributes();
                if ((string) ($attributes['rel'] ?? '') === 'alternate') {
                    $entryLinkUrl = (string) ($attributes['href'] ?? '');
                    break;
                }
            }

            $snapshot['videos'][] = [
                'video_id' => $videoId,
                'entry_id' => (string) $entry->id,
                'channel_id' => (string) $entry->children($ytNamespace)->channelId,
                'title' => (string) $entry->title,
                'entry_url' => $entryLinkUrl,
                'author_name' => (string) $entry->author->name,
                'author_uri' => (string) $entry->author->uri,
                'published' => (string) $entry->published,
                'updated' => (string) $entry->updated,
                'media_title' => (string) $entryMedia->title,
                'media_content_url' => (string) $mediaContentAttributes['url'],
                'media_thumbnail_url' => (string) $mediaThumbnailAttributes['url'],
                'media_description' => (string) $entryMedia->description,
            ];
        }

        return $snapshot;
    }

    private function findEntryByVideoId(
        \SimpleXMLElement $atomFeed,
        string $ytNamespace,
        string $videoId
    ): ?\SimpleXMLElement {
        foreach ($atomFeed->entry as $entry) {
            if ((string) $entry->children($ytNamespace)->videoId === $videoId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function extractVideoIds(string $xml): array
    {
        $feed = new \SimpleXMLElement($xml);
        $namespaces = $feed->getNamespaces(true);
        $ytNamespace = $namespaces['yt'];
        $atomNamespace = $namespaces[''] ?? null;
        $atom = $atomNamespace !== null ? $feed->children($atomNamespace) : $feed;

        $videoIds = [];

        foreach ($atom->entry as $entry) {
            $videoId = (string) $entry->children($ytNamespace)->videoId;
            if ($videoId !== '') {
                $videoIds[] = $videoId;
            }
        }

        return $videoIds;
    }

    private function fetchRealYoutubeFeedXml(string $channelId): string
    {
        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::timeout(30)
            ->retry(2, 500)
            ->accept('application/xml')
            ->get('https://www.youtube.com/feeds/videos.xml', [
                'channel_id' => $channelId,
            ]);

        $this->assertTrue(
            $response->successful(),
            'Failed to fetch real YouTube feed XML. HTTP status: ' . $response->status()
        );

        $xml = $response->body();
        $this->assertNotEmpty($xml, 'Fetched YouTube feed XML is empty.');
        $this->assertStringContainsString('<feed', $xml);

        return $xml;
    }

    private function configuredRealFeedChannelId(): ?string
    {
        $configuredFeedUrl = trim((string) env('YOUTUBE_TEST_REAL_FEED_URL', ''));
        if ($configuredFeedUrl === '') {
            return null;
        }

        $parts = parse_url($configuredFeedUrl);
        if (! is_array($parts)) {
            return null;
        }

        parse_str((string) ($parts['query'] ?? ''), $queryParams);
        $channelId = $queryParams['channel_id'] ?? null;

        return YoutubeChannelReference::normalizeChannelId($channelId);
    }

    private function networkTestsEnabled(): bool
    {
        $raw = strtolower(trim((string) env('YOUTUBE_TESTS_WITH_NETWORK', 'false')));

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    private function extractXmlDeclarationLine(string $xml): string
    {
        if (preg_match('/^\s*(<\?xml[^>]+\?>)/u', $xml, $matches) === 1) {
            return $matches[1];
        }

        $this->fail('Could not find XML declaration line.');
    }

    private function extractFeedOpenLine(string $xml): string
    {
        if (preg_match('/<feed\b[^>]*>/u', $xml, $matches) === 1) {
            return $matches[0];
        }

        $this->fail('Could not find feed opening line.');
    }

    private function normalizeXmlLine(string $line): string
    {
        return preg_replace('/\s+/', ' ', trim($line)) ?? trim($line);
    }

    private function normalizeFeedIdForComparison(string $feedId): string
    {
        $prefix = 'yt:channel:';
        if (str_starts_with($feedId, $prefix)) {
            $rawChannelId = substr($feedId, strlen($prefix));

            return $prefix . $this->normalizeChannelIdForComparison($rawChannelId);
        }

        return $feedId;
    }

    private function normalizeChannelIdForComparison(string $channelId): string
    {
        $trimmedChannelId = trim($channelId);

        if (preg_match('/^UC([A-Za-z0-9_-]{22})$/i', $trimmedChannelId, $matches) === 1) {
            return 'UC' . $matches[1];
        }

        if (preg_match('/^C([A-Za-z0-9_-]{22})$/i', $trimmedChannelId, $matches) === 1) {
            return 'UC' . $matches[1];
        }

        if (preg_match('/^([A-Za-z0-9_-]{22})$/', $trimmedChannelId, $matches) === 1) {
            return 'UC' . $matches[1];
        }

        return $trimmedChannelId;
    }

    private function isLikelyLiveStreamOfflineVideo(string $videoId): bool
    {
        try {
            $result = Process::timeout(30)->run([
                PythonBinaryResolver::resolve(),
                '-m',
                'yt_dlp',
                '--skip-download',
                '--dump-single-json',
                'https://www.youtube.com/watch?v=' . $videoId,
            ]);
        } catch (\Throwable) {
            return false;
        }

        if ($result->successful()) {
            $payload = json_decode($result->output(), true);
            if (! is_array($payload)) {
                return false;
            }

            $liveStatus = $payload['live_status'] ?? null;

            return is_string($liveStatus) && in_array($liveStatus, ['is_upcoming', 'is_live'], true);
        }

        $output = strtolower($result->errorOutput() . PHP_EOL . $result->output());

        foreach (
            [
                'this live event will begin in',
                'premieres in',
                'this live event has not started yet',
                'live event is offline',
                'this live stream recording is not available',
            ] as $pattern
        ) {
            if (str_contains($output, $pattern)) {
                return true;
            }
        }

        return false;
    }
}

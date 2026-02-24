<?php

namespace Tests\Feature\Youtube;

use App\Actions\Youtube\RunYoutubeChannelSyncAction;
use App\Enums\YoutubeChannelStatus;
use App\Models\YoutubeChannel;
use App\Support\Youtube\YoutubeChannelReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesYoutubePythonProcesses;
use Tests\TestCase;

class RunYoutubeChannelSyncActionTest extends TestCase
{
    use FakesYoutubePythonProcesses;
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

        $outputDirectory = base_path('python/yt-dlp_jsons/' . $youtubeId);
        $channelJsonPath = $outputDirectory . '/channel.json';
        $videosJsonPath = $outputDirectory . '/videos.jsonl';
        $outputDirectoryExistedBeforeRun = File::isDirectory($outputDirectory);

        try {
            $channel = YoutubeChannel::factory()
                ->idle()
                ->forYoutubeId($youtubeId)
                ->create();

            $this->runSyncActionWorkflow($channel->id);

            $channel->refresh();

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
        } finally {
            if (! $outputDirectoryExistedBeforeRun) {
                File::deleteDirectory($outputDirectory);
            }
        }
    }

    public function test_it_processes_chunk_jobs_and_builds_feed_in_full_queue_pipeline(): void
    {
        Storage::fake('public');
        config()->set('youtube.video_chunk_size', 2);
        config()->set('youtube.video_fetch_threads', 1);
        $fixtureYoutubeId = '@fixture-full-pipeline-' . uniqid();

        $channel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId($fixtureYoutubeId)
            ->create();

        $outputDirectory = base_path('python/yt-dlp_jsons/' . $channel->youtube_id);
        $channelJsonPath = $outputDirectory . '/channel.json';
        $videosJsonPath = $outputDirectory . '/videos.jsonl';

        try {
            $this->prepareChannelFetchArtifacts(
                outputDirectory: $outputDirectory,
                channelJsonPath: $channelJsonPath,
                videosJsonPath: $videosJsonPath,
                videos: [
                    [
                        'id' => 'fixture-video-001',
                        'title' => 'Fixture Video 1',
                        'upload_date' => '20260101',
                        'timestamp' => 1767225600,
                        'description' => 'Fixture description 1',
                    ],
                    [
                        'id' => 'fixture-video-002',
                        'title' => 'Fixture Video 2',
                        'upload_date' => '20260102',
                        'timestamp' => 1767312000,
                        'description' => 'Fixture description 2',
                    ],
                    [
                        'id' => 'fixture-video-003',
                        'title' => 'Fixture Video 3',
                        'timestamp' => 1767398400,
                        'description' => 'Fixture description 3',
                    ],
                ],
                channelPayload: [
                    'channel' => 'Fixture Channel',
                    'channel_id' => 'UCFIXTURE123456789012345',
                ],
            );

            $this->fakeYoutubeChunkProcessFromSource(function (array $entry, string $videoId): array {
                $timestamp = $entry['timestamp'] ?? null;
                if (! is_int($timestamp) && ! is_float($timestamp) && ! (is_string($timestamp) && ctype_digit($timestamp))) {
                    $timestamp = 1767225600;
                }
                $timestamp = (int) $timestamp;

                return [
                    'id' => $videoId,
                    'title' => (string) ($entry['title'] ?? ('Video ' . $videoId)),
                    'description' => (string) ($entry['description'] ?? ''),
                    'timestamp' => $timestamp,
                    'modified_timestamp' => $timestamp,
                    'upload_date' => $entry['upload_date'] ?? null,
                    'release_timestamp' => $entry['release_timestamp'] ?? null,
                    'live_status' => $entry['live_status'] ?? null,
                    'thumbnail' => 'https://example.test/' . $videoId . '.jpg',
                ];
            });

            $this->runSyncActionWorkflow($channel->id);

            $channel->refresh();
            $this->assertTrue($channel->hasStatus(YoutubeChannelStatus::FetchingVideos));
            $this->assertNotNull($channel->active_video_batch_id);

            $this->drainQueueUntilEmpty();

            $channel->refresh();
            $this->assertTrue($channel->hasStatus(YoutubeChannelStatus::Idle));
            $this->assertNull($channel->active_video_batch_id);
            $this->assertSame('fixture-video-003', $channel->last_video_id);

            $this->assertDatabaseCount('youtube_videos', 3);
            $this->assertDatabaseHas('youtube_videos', [
                'youtube_channel_id' => $channel->id,
                'youtube_video_id' => 'fixture-video-003',
            ]);
            $this->assertSame(0, (int) DB::table('failed_jobs')->count());

            $this->assertTrue(
                Storage::disk('public')->exists('feeds/' . $channel->youtube_id . '.xml')
            );
            $feedXml = Storage::disk('public')->get('feeds/' . $channel->youtube_id . '.xml');
            $this->assertStringContainsString('yt:channel:UCFIXTURE123456789012345', $feedXml);
            $this->assertStringContainsString('yt:video:fixture-video-001', $feedXml);
            $this->assertStringContainsString('yt:video:fixture-video-003', $feedXml);
        } finally {
            File::deleteDirectory($outputDirectory);
            File::deleteDirectory(storage_path('framework/testing/disks/public/feeds'));
        }
    }

    public function test_it_resolves_uc_channel_metadata_then_promotes_to_handle_in_safe_sync_step(): void
    {
        $initialChannelId = 'UCABCDEFGHIJKLMN_OPQRSTU';
        $resolvedHandle = '@resolved-handle-' . uniqid();

        $channel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId($initialChannelId)
            ->create([
                'youtube_channel_id' => $initialChannelId,
            ]);

        $oldOutputDirectory = base_path('python/yt-dlp_jsons/' . $initialChannelId);
        $oldChannelJsonPath = $oldOutputDirectory . '/channel.json';
        $oldVideosJsonPath = $oldOutputDirectory . '/videos.jsonl';

        $this->fakeYoutubeChunkProcessFromSource(static fn (array $entry, string $videoId): ?array => null);

        try {
            $this->prepareChannelFetchArtifacts(
                outputDirectory: $oldOutputDirectory,
                channelJsonPath: $oldChannelJsonPath,
                videosJsonPath: $oldVideosJsonPath,
                videos: [
                    [
                        'id' => 'resolved-video-001',
                        'title' => 'Resolved Video 1',
                        'timestamp' => 1767225600,
                    ],
                ],
                channelPayload: [
                    'channel_id' => $initialChannelId,
                    'uploader_id' => $resolvedHandle,
                    'channel' => 'Resolved Channel Name',
                ],
            );

            $didFetch = app(RunYoutubeChannelSyncAction::class)->fetchChannelInfoAndVideoList($channel->id);
            $this->assertTrue($didFetch);

            $channel->refresh();
            $this->assertSame($initialChannelId, $channel->youtube_id);
            $this->assertSame($initialChannelId, $channel->youtube_channel_id);
            $this->assertSame('Resolved Channel Name', $channel->channel_name);
            $this->assertSame('https://www.youtube.com/channel/' . $initialChannelId, $channel->youtube_url);

            app(RunYoutubeChannelSyncAction::class)->syncIdentifiersFromPersistedMetadata($channel->id, true);

            $channel->refresh();
            $this->assertSame($resolvedHandle, $channel->youtube_id);
            $this->assertSame($initialChannelId, $channel->youtube_channel_id);
            $this->assertSame('https://www.youtube.com/' . $resolvedHandle, $channel->youtube_url);

            $this->assertDirectoryExists($oldOutputDirectory);
            $this->assertFileExists($oldOutputDirectory . '/channel.json');
            $this->assertFileExists($oldOutputDirectory . '/videos.jsonl');
        } finally {
            File::deleteDirectory($oldOutputDirectory);
        }
    }

    public function test_it_promotes_to_handle_from_metadata_uploader_url_when_uploader_id_is_missing(): void
    {
        $initialChannelId = 'UCABCDEFGHIJKLMN_OPQRSTU';
        $resolvedHandle = '@resolved-url-handle-' . uniqid();

        $channel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId($initialChannelId)
            ->create([
                'youtube_channel_id' => $initialChannelId,
            ]);

        $outputDirectory = base_path('python/yt-dlp_jsons/' . $initialChannelId);
        $channelJsonPath = $outputDirectory . '/channel.json';
        $videosJsonPath = $outputDirectory . '/videos.jsonl';

        $this->fakeYoutubeChunkProcessFromSource(static fn (array $entry, string $videoId): ?array => null);

        try {
            $this->prepareChannelFetchArtifacts(
                outputDirectory: $outputDirectory,
                channelJsonPath: $channelJsonPath,
                videosJsonPath: $videosJsonPath,
                videos: [
                    [
                        'id' => 'resolved-url-video-001',
                        'title' => 'Resolved URL Video 1',
                        'timestamp' => 1767225600,
                    ],
                ],
                channelPayload: [
                    'channel_id' => $initialChannelId,
                    'uploader_url' => 'https://www.youtube.com/' . $resolvedHandle . '/videos',
                    'channel' => 'Resolved URL Channel Name',
                ],
            );

            $didFetch = app(RunYoutubeChannelSyncAction::class)->fetchChannelInfoAndVideoList($channel->id);
            $this->assertTrue($didFetch);

            $channel->refresh();
            $this->assertSame($initialChannelId, $channel->youtube_id);
            $this->assertSame($initialChannelId, $channel->youtube_channel_id);

            app(RunYoutubeChannelSyncAction::class)->syncIdentifiersFromPersistedMetadata($channel->id, true);

            $channel->refresh();
            $this->assertSame($resolvedHandle, $channel->youtube_id);
            $this->assertSame($initialChannelId, $channel->youtube_channel_id);
            $this->assertSame('https://www.youtube.com/' . $resolvedHandle, $channel->youtube_url);
        } finally {
            File::deleteDirectory($outputDirectory);
        }
    }

    public function test_it_rejects_newer_duplicate_channel_when_metadata_matches_existing_uc_identifier(): void
    {
        $resolvedChannelId = 'UCABCDEFGHIJKLMN_OPQRSTU';
        $canonicalHandle = '@canonical-handle-' . uniqid();
        $duplicateHandle = '@duplicate-handle-' . uniqid();

        $canonicalChannel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId($canonicalHandle)
            ->create([
                'youtube_channel_id' => $resolvedChannelId,
            ]);

        $duplicateChannel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId($duplicateHandle)
            ->create();

        $duplicateOutputDirectory = base_path('python/yt-dlp_jsons/' . $duplicateHandle);

        $this->fakeYoutubeChunkProcessFromSource(static fn (array $entry, string $videoId): ?array => null);

        try {
            $this->prepareChannelFetchArtifacts(
                outputDirectory: $duplicateOutputDirectory,
                channelJsonPath: $duplicateOutputDirectory . '/channel.json',
                videosJsonPath: $duplicateOutputDirectory . '/videos.jsonl',
                videos: [
                    [
                        'id' => 'dup-video-001',
                        'title' => 'Duplicate Video 1',
                        'timestamp' => 1767225600,
                    ],
                ],
                channelPayload: [
                    'channel_id' => $resolvedChannelId,
                    'uploader_id' => $canonicalHandle,
                    'channel' => 'Canonical Channel',
                ],
            );

            $didFetch = app(RunYoutubeChannelSyncAction::class)->fetchChannelInfoAndVideoList($duplicateChannel->id);
            $this->assertFalse($didFetch);

            $this->assertDatabaseMissing('youtube_channels', [
                'id' => $duplicateChannel->id,
            ]);
            $this->assertDatabaseHas('youtube_channels', [
                'id' => $canonicalChannel->id,
                'youtube_channel_id' => $resolvedChannelId,
            ]);
        } finally {
            File::deleteDirectory($duplicateOutputDirectory);
        }
    }

    public function test_it_is_idempotent_when_re_syncing_same_channel_data(): void
    {
        Storage::fake('public');
        config()->set('youtube.video_chunk_size', 2);
        config()->set('youtube.video_fetch_threads', 1);
        $fixtureYoutubeId = '@fixture-idempotent-' . uniqid();

        $channel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId($fixtureYoutubeId)
            ->create();

        $outputDirectory = base_path('python/yt-dlp_jsons/' . $channel->youtube_id);
        $channelJsonPath = $outputDirectory . '/channel.json';
        $videosJsonPath = $outputDirectory . '/videos.jsonl';

        try {
            $this->prepareChannelFetchArtifacts(
                outputDirectory: $outputDirectory,
                channelJsonPath: $channelJsonPath,
                videosJsonPath: $videosJsonPath,
                videos: [
                    [
                        'id' => 'idem-video-001',
                        'title' => 'Idempotent Video 1',
                        'upload_date' => '20260101',
                        'timestamp' => 1767225600,
                    ],
                    [
                        'id' => 'idem-video-002',
                        'title' => 'Idempotent Video 2',
                        'upload_date' => '20260102',
                        'timestamp' => 1767312000,
                    ],
                    [
                        'id' => 'idem-video-003',
                        'title' => 'Idempotent Video 3',
                        'timestamp' => 1767398400,
                    ],
                ],
                channelPayload: [
                    'channel' => 'Idempotent Fixture Channel',
                    'channel_id' => 'UCIDEMPOTENT123456789012',
                ],
            );

            $this->fakeYoutubeChunkProcessFromSource(function (array $entry, string $videoId): array {
                $timestamp = $entry['timestamp'] ?? null;
                if (! is_int($timestamp) && ! is_float($timestamp) && ! (is_string($timestamp) && ctype_digit($timestamp))) {
                    $timestamp = 1767225600;
                }
                $timestamp = (int) $timestamp;

                return [
                    'id' => $videoId,
                    'title' => (string) ($entry['title'] ?? ('Video ' . $videoId)),
                    'description' => '',
                    'timestamp' => $timestamp,
                    'modified_timestamp' => $timestamp,
                    'upload_date' => $entry['upload_date'] ?? null,
                    'thumbnail' => 'https://example.test/' . $videoId . '.jpg',
                ];
            });

            $this->runSyncActionWorkflow($channel->id);
            $this->drainQueueUntilEmpty();

            $channel->refresh();
            $this->assertTrue($channel->hasStatus(YoutubeChannelStatus::Idle));
            $this->assertSame(3, DB::table('youtube_videos')->count());
            $this->assertSame(3, DB::table('youtube_videos')->distinct('youtube_video_id')->count('youtube_video_id'));
            $this->assertSame('idem-video-003', $channel->last_video_id);

            $this->runSyncActionWorkflow($channel->id);
            $this->drainQueueUntilEmpty();

            $channel->refresh();
            $this->assertTrue($channel->hasStatus(YoutubeChannelStatus::Idle));
            $this->assertSame(3, DB::table('youtube_videos')->count());
            $this->assertSame(3, DB::table('youtube_videos')->distinct('youtube_video_id')->count('youtube_video_id'));
            $this->assertSame('idem-video-003', $channel->last_video_id);
            $this->assertSame(1, (int) DB::table('job_batches')->count());

            $this->assertTrue(
                Storage::disk('public')->exists('feeds/' . $channel->youtube_id . '.xml')
            );
            $feedXml = Storage::disk('public')->get('feeds/' . $channel->youtube_id . '.xml');
            $this->assertStringContainsString('yt:channel:UCIDEMPOTENT123456789012', $feedXml);
            $this->assertStringContainsString('yt:video:idem-video-001', $feedXml);
            $this->assertStringContainsString('yt:video:idem-video-003', $feedXml);
        } finally {
            File::deleteDirectory($outputDirectory);
            File::deleteDirectory(storage_path('framework/testing/disks/public/feeds'));
        }
    }

    private function configuredRealTestChannel(): ?string
    {
        $configured = trim((string) env('YOUTUBE_TEST_REAL_CHANNEL', ''));
        if ($configured === '') {
            return null;
        }

        $normalized = YoutubeChannelReference::normalizeInput($configured);

        return $normalized['youtube_id'] ?? null;
    }

    private function runSyncActionWorkflow(int $channelId): void
    {
        $action = app(RunYoutubeChannelSyncAction::class);
        if (! $action->fetchChannelInfoAndVideoList($channelId)) {
            return;
        }

        $action->dispatchVideoPhase($channelId);
    }

    private function networkTestsEnabled(): bool
    {
        $raw = strtolower(trim((string) env('YOUTUBE_TESTS_WITH_NETWORK', 'false')));

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param list<array<string, mixed>> $videos
     * @param array<string, mixed> $channelPayload
     */
    private function prepareChannelFetchArtifacts(
        string $outputDirectory,
        string $channelJsonPath,
        string $videosJsonPath,
        array $videos,
        array $channelPayload
    ): void {
        File::deleteDirectory($outputDirectory);
        File::ensureDirectoryExists($outputDirectory);
        File::put($channelJsonPath, json_encode($channelPayload, JSON_THROW_ON_ERROR));

        $encodedRows = array_map(
            static fn(array $video): string => json_encode(
                $video,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
            $videos
        );

        File::put($videosJsonPath, implode(PHP_EOL, $encodedRows) . PHP_EOL);
    }

    private function drainQueueUntilEmpty(int $maxIterations = 25): void
    {
        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            $pendingJobCount = (int) DB::table('jobs')->count();
            if ($pendingJobCount === 0) {
                return;
            }

            Artisan::call('queue:work', [
                '--once' => true,
                '--queue' => 'default',
                '--timeout' => 120,
            ]);
        }

        $this->fail('Queue did not drain after max iterations. Pending jobs: ' . DB::table('jobs')->count());
    }

}

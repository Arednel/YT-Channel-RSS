<?php

namespace Tests\Feature\Youtube;

use App\Actions\Youtube\DispatchSyncYoutubeChannelJobAction;
use App\Enums\YoutubeChannelStatus;
use App\Models\YoutubeChannel;
use App\Support\YoutubeFeedXmlBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Concerns\FakesYoutubePythonProcesses;
use Tests\TestCase;

class SyncYoutubeChannelQueuedWorkflowTest extends TestCase
{
    use FakesYoutubePythonProcesses;
    use RefreshDatabase;

    public function test_it_runs_sync_pipeline_through_real_queued_orchestration(): void
    {
        config()->set('youtube.video_chunk_size', 2);
        config()->set('youtube.video_fetch_threads', 1);

        $channel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId('@queue-workflow-' . uniqid())
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
                        'id' => 'queue-video-001',
                        'title' => 'Queued Video 1',
                        'upload_date' => '20260101',
                        'timestamp' => 1767225600,
                    ],
                    [
                        'id' => 'queue-video-002',
                        'title' => 'Queued Video 2',
                        'upload_date' => '20260102',
                        'timestamp' => 1767312000,
                    ],
                    [
                        'id' => 'queue-video-003',
                        'title' => 'Queued Video 3',
                        'timestamp' => 1767398400,
                    ],
                ],
                channelPayload: [
                    'channel' => 'Queued Fixture Channel',
                    'channel_id' => 'UCQUEUED123',
                ],
            );

            $this->fakeYoutubeChunkProcessFromSource(function (array $entry, string $videoId): array {
                $timestamp = $entry['timestamp'] ?? 1767225600;
                if (is_string($timestamp) && ctype_digit($timestamp)) {
                    $timestamp = (int) $timestamp;
                } elseif (! is_int($timestamp)) {
                    $timestamp = 1767225600;
                }

                return [
                    'id' => $videoId,
                    'title' => (string) ($entry['title'] ?? ('Video ' . $videoId)),
                    'description' => (string) ($entry['description'] ?? ''),
                    'timestamp' => $timestamp,
                    'modified_timestamp' => $timestamp,
                    'upload_date' => $entry['upload_date'] ?? null,
                    'thumbnail' => 'https://example.test/' . $videoId . '.jpg',
                ];
            });

            $this->mock(YoutubeFeedXmlBuilder::class, function (MockInterface $mock): void {
                $mock->shouldReceive('build')
                    ->once()
                    ->andReturn([
                        'entry_count' => 3,
                        'path' => storage_path('app/public/feeds/queued-fixture.xml'),
                    ]);
            });

            $dispatched = app(DispatchSyncYoutubeChannelJobAction::class)->handle($channel);
            $this->assertTrue($dispatched);

            $channel->refresh();
            $this->assertTrue($channel->hasStatus(YoutubeChannelStatus::Queued));

            $this->drainQueueUntilEmpty();

            $channel->refresh();
            $this->assertTrue($channel->hasStatus(YoutubeChannelStatus::Idle));
            $this->assertNull($channel->active_video_batch_id);
            $this->assertSame('queue-video-003', $channel->last_video_id);

            $this->assertDatabaseCount('youtube_videos', 3);
            $this->assertSame(0, (int) DB::table('failed_jobs')->count());
        } finally {
            File::deleteDirectory($outputDirectory);
        }
    }

    public function test_it_retries_reserved_sync_job_after_retry_after_and_resumes_pipeline(): void
    {
        config()->set('youtube.video_chunk_size', 2);
        config()->set('youtube.video_fetch_threads', 1);
        $retryAfterSeconds = max(1, (int) config('queue.connections.database.retry_after', 90));

        $channel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId('@queue-restart-retry-' . uniqid())
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
                        'id' => 'restart-video-001',
                        'title' => 'Restart Video 1',
                        'upload_date' => '20260101',
                        'timestamp' => 1767225600,
                    ],
                    [
                        'id' => 'restart-video-002',
                        'title' => 'Restart Video 2',
                        'upload_date' => '20260102',
                        'timestamp' => 1767312000,
                    ],
                    [
                        'id' => 'restart-video-003',
                        'title' => 'Restart Video 3',
                        'timestamp' => 1767398400,
                    ],
                ],
                channelPayload: [
                    'channel' => 'Restart Retry Fixture Channel',
                    'channel_id' => 'UCRESTARTRETRY123',
                ],
            );

            $this->fakeYoutubeChunkProcessFromSource(function (array $entry, string $videoId): array {
                $timestamp = $entry['timestamp'] ?? 1767225600;
                if (is_string($timestamp) && ctype_digit($timestamp)) {
                    $timestamp = (int) $timestamp;
                } elseif (! is_int($timestamp)) {
                    $timestamp = 1767225600;
                }

                return [
                    'id' => $videoId,
                    'title' => (string) ($entry['title'] ?? ('Video ' . $videoId)),
                    'description' => (string) ($entry['description'] ?? ''),
                    'timestamp' => $timestamp,
                    'modified_timestamp' => $timestamp,
                    'upload_date' => $entry['upload_date'] ?? null,
                    'thumbnail' => 'https://example.test/' . $videoId . '.jpg',
                ];
            });

            $this->mock(YoutubeFeedXmlBuilder::class, function (MockInterface $mock): void {
                $mock->shouldReceive('build')
                    ->once()
                    ->andReturn([
                        'entry_count' => 3,
                        'path' => storage_path('app/public/feeds/restart-retry-fixture.xml'),
                    ]);
            });

            $dispatched = app(DispatchSyncYoutubeChannelJobAction::class)->handle($channel);
            $this->assertTrue($dispatched);

            $reservedJobId = DB::table('jobs')
                ->orderBy('id')
                ->value('id');
            $this->assertNotNull($reservedJobId);

            DB::table('jobs')
                ->where('id', $reservedJobId)
                ->update([
                    'reserved_at' => now()->timestamp,
                    'attempts' => 1,
                ]);

            Artisan::call('queue:work', [
                '--once' => true,
                '--queue' => 'default',
                '--timeout' => 120,
                '--tries' => 1,
            ]);

            $channel->refresh();
            $this->assertTrue($channel->hasStatus(YoutubeChannelStatus::Queued));
            $this->assertSame(1, (int) DB::table('jobs')->count());
            $this->assertSame(0, (int) DB::table('youtube_videos')->count());

            DB::table('jobs')
                ->where('id', $reservedJobId)
                ->update([
                    'reserved_at' => now()->subSeconds($retryAfterSeconds + 5)->timestamp,
                    'attempts' => 1,
                ]);

            $this->drainQueueUntilEmpty();

            $channel->refresh();
            $this->assertTrue($channel->hasStatus(YoutubeChannelStatus::Idle));
            $this->assertNull($channel->active_video_batch_id);
            $this->assertSame('restart-video-003', $channel->last_video_id);
            $this->assertDatabaseCount('youtube_videos', 3);
            $this->assertSame(0, (int) DB::table('failed_jobs')->count());
        } finally {
            File::deleteDirectory($outputDirectory);
        }
    }

    public function test_it_does_not_get_stuck_with_stale_active_batch_after_restart(): void
    {
        config()->set('youtube.video_chunk_size', 2);
        config()->set('youtube.video_fetch_threads', 1);

        $channel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId('@queue-restart-stale-batch-' . uniqid())
            ->create([
                'active_video_batch_id' => (string) Str::orderedUuid(),
            ]);

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
                        'id' => 'stale-video-001',
                        'title' => 'Stale Recovery Video 1',
                        'upload_date' => '20260101',
                        'timestamp' => 1767225600,
                    ],
                    [
                        'id' => 'stale-video-002',
                        'title' => 'Stale Recovery Video 2',
                        'upload_date' => '20260102',
                        'timestamp' => 1767312000,
                    ],
                    [
                        'id' => 'stale-video-003',
                        'title' => 'Stale Recovery Video 3',
                        'timestamp' => 1767398400,
                    ],
                ],
                channelPayload: [
                    'channel' => 'Stale Batch Recovery Channel',
                    'channel_id' => 'UCSTALEBATCH123',
                ],
            );

            $this->fakeYoutubeChunkProcessFromSource(function (array $entry, string $videoId): array {
                $timestamp = $entry['timestamp'] ?? 1767225600;
                if (is_string($timestamp) && ctype_digit($timestamp)) {
                    $timestamp = (int) $timestamp;
                } elseif (! is_int($timestamp)) {
                    $timestamp = 1767225600;
                }

                return [
                    'id' => $videoId,
                    'title' => (string) ($entry['title'] ?? ('Video ' . $videoId)),
                    'description' => (string) ($entry['description'] ?? ''),
                    'timestamp' => $timestamp,
                    'modified_timestamp' => $timestamp,
                    'upload_date' => $entry['upload_date'] ?? null,
                    'thumbnail' => 'https://example.test/' . $videoId . '.jpg',
                ];
            });

            $this->mock(YoutubeFeedXmlBuilder::class, function (MockInterface $mock): void {
                $mock->shouldReceive('build')
                    ->once()
                    ->andReturn([
                        'entry_count' => 3,
                        'path' => storage_path('app/public/feeds/stale-batch-fixture.xml'),
                    ]);
            });

            $this->assertNotNull($channel->active_video_batch_id);

            $exitCode = Artisan::call('youtube:maintenance');
            $this->assertSame(0, $exitCode);

            $channel->refresh();
            $this->assertTrue($channel->hasStatus(YoutubeChannelStatus::Queued));
            $this->assertNull($channel->active_video_batch_id);

            $this->drainQueueUntilEmpty();

            $channel->refresh();
            $this->assertTrue($channel->hasStatus(YoutubeChannelStatus::Idle));
            $this->assertNull($channel->active_video_batch_id);
            $this->assertSame('stale-video-003', $channel->last_video_id);
            $this->assertDatabaseCount('youtube_videos', 3);
            $this->assertSame(0, (int) DB::table('failed_jobs')->count());
        } finally {
            File::deleteDirectory($outputDirectory);
        }
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

    private function drainQueueUntilEmpty(int $maxIterations = 30): void
    {
        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            if ((int) DB::table('jobs')->count() === 0) {
                return;
            }

            Artisan::call('queue:work', [
                '--once' => true,
                '--queue' => 'default',
                '--timeout' => 120,
                '--tries' => 1,
            ]);
        }

        $this->fail('Queue did not drain after max iterations. Pending jobs: ' . DB::table('jobs')->count());
    }
}

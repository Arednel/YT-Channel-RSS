<?php

namespace Tests\Feature\Youtube;

use App\Jobs\FetchYoutubeVideoChunkJob;
use App\Models\YoutubeChannel;
use App\Models\YoutubeVideo;
use App\Support\Youtube\YtDlpAutoUpdateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\Concerns\FakesYoutubePythonProcesses;
use Tests\TestCase;

class FetchYoutubeVideoChunkJobTest extends TestCase
{
    use FakesYoutubePythonProcesses;
    use RefreshDatabase;

    public function test_it_transitions_video_from_upcoming_live_to_regular_video(): void
    {
        $channel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId('@chunk-live-transition-' . uniqid())
            ->create();

        $baseDirectory = base_path('python/yt-dlp_jsons/' . $channel->youtube_id);
        $sourceFile = 'video_id_chunks/chunk_00001.jsonl';
        $sourcePath = $baseDirectory . '/' . $sourceFile;

        File::ensureDirectoryExists(dirname($sourcePath));
        File::put($sourcePath, json_encode(['id' => 'live-video-001'], JSON_THROW_ON_ERROR) . PHP_EOL);

        $this->fakeYoutubeChunkProcessWithRowsByCall([
            [[
                'id' => 'live-video-001',
                'title' => 'Upcoming Live',
                'live_status' => 'is_live',
                'release_timestamp' => 1768000000,
                'timestamp' => 1767000000,
                'thumbnail' => 'https://example.test/live-video-001.jpg',
                'description' => 'upcoming',
            ]],
            [[
                'id' => 'live-video-001',
                'title' => 'Archived Live',
                'live_status' => null,
                'timestamp' => 1769000000,
                'modified_timestamp' => 1769000000,
                'thumbnail' => 'https://example.test/live-video-001.jpg',
                'description' => 'archived',
            ]],
        ]);

        try {
            $job = new FetchYoutubeVideoChunkJob(
                channelId: $channel->id,
                youtubeId: $channel->youtube_id,
                chunkIndex: 0,
                chunkSize: 1,
                sourceFile: $sourceFile
            );
            $job->handle(app(YtDlpAutoUpdateManager::class));

            $video = YoutubeVideo::query()->where('youtube_video_id', 'live-video-001')->firstOrFail();
            $this->assertTrue($video->is_upcoming);
            $this->assertNotNull($video->scheduled_start_at);

            $job->handle(app(YtDlpAutoUpdateManager::class));

            $video->refresh();
            $this->assertFalse($video->is_upcoming);
            $this->assertNull($video->scheduled_start_at);
            $this->assertSame('Archived Live', $video->video_title);
        } finally {
            File::deleteDirectory($baseDirectory);
        }
    }

    public function test_it_prefers_release_date_over_upload_date_when_timestamps_are_missing(): void
    {
        $channel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId('@chunk-release-date-' . uniqid())
            ->create();

        $baseDirectory = base_path('python/yt-dlp_jsons/' . $channel->youtube_id);
        $sourceFile = 'video_id_chunks/chunk_00001.jsonl';
        $sourcePath = $baseDirectory . '/' . $sourceFile;

        File::ensureDirectoryExists(dirname($sourcePath));
        File::put($sourcePath, json_encode(['id' => 'live-video-002'], JSON_THROW_ON_ERROR) . PHP_EOL);

        $this->fakeYoutubeChunkProcessWithRowsByCall([
            [[
                'id' => 'live-video-002',
                'title' => 'Upcoming Placeholder',
                'live_status' => 'is_live',
                'upload_date' => '20250101',
                'thumbnail' => 'https://example.test/live-video-002.jpg',
                'description' => 'upcoming',
            ]],
            [[
                'id' => 'live-video-002',
                'title' => 'Archived Live',
                'live_status' => 'was_live',
                'upload_date' => '20250101',
                'release_date' => '20260223',
                'modified_timestamp' => 1771866653,
                'thumbnail' => 'https://example.test/live-video-002.jpg',
                'description' => 'archived',
            ]],
        ]);

        try {
            $job = new FetchYoutubeVideoChunkJob(
                channelId: $channel->id,
                youtubeId: $channel->youtube_id,
                chunkIndex: 0,
                chunkSize: 1,
                sourceFile: $sourceFile
            );
            $job->handle(app(YtDlpAutoUpdateManager::class));

            $video = YoutubeVideo::query()->where('youtube_video_id', 'live-video-002')->firstOrFail();
            $this->assertSame('2025-01-01', $video->published_date?->utc()->toDateString());

            $job->handle(app(YtDlpAutoUpdateManager::class));

            $video->refresh();
            $this->assertFalse($video->is_upcoming);
            $this->assertSame('2026-02-23', $video->published_date?->utc()->toDateString());
            $this->assertSame('Archived Live', $video->video_title);
        } finally {
            File::deleteDirectory($baseDirectory);
        }
    }

    public function test_it_skips_restricted_like_rows_without_failing_chunk_job(): void
    {
        $channel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId('@chunk-restricted-' . uniqid())
            ->create();

        $baseDirectory = base_path('python/yt-dlp_jsons/' . $channel->youtube_id);
        $sourceFile = 'video_id_chunks/chunk_00001.jsonl';
        $sourcePath = $baseDirectory . '/' . $sourceFile;

        File::ensureDirectoryExists(dirname($sourcePath));
        File::put($sourcePath, json_encode(['id' => 'placeholder'], JSON_THROW_ON_ERROR) . PHP_EOL);

        $this->fakeYoutubeChunkProcessWithRowsByCall([
            [
                [
                    'id' => 'restricted-video-001',
                    'title' => 'Members-only video',
                    'description' => 'not available',
                ],
                [
                    'id' => 'normal-video-001',
                    'title' => 'Normal video',
                    'timestamp' => 1768100000,
                    'modified_timestamp' => 1768100000,
                    'thumbnail' => 'https://example.test/normal-video-001.jpg',
                    'description' => 'available',
                ],
            ],
        ]);

        try {
            $job = new FetchYoutubeVideoChunkJob(
                channelId: $channel->id,
                youtubeId: $channel->youtube_id,
                chunkIndex: 0,
                chunkSize: 1,
                sourceFile: $sourceFile
            );
            $job->handle(app(YtDlpAutoUpdateManager::class));

            $this->assertDatabaseHas('youtube_videos', [
                'youtube_channel_id' => $channel->id,
                'youtube_video_id' => 'normal-video-001',
            ]);
            $this->assertDatabaseMissing('youtube_videos', [
                'youtube_channel_id' => $channel->id,
                'youtube_video_id' => 'restricted-video-001',
            ]);
            $this->assertSame(1, YoutubeVideo::query()->count());
        } finally {
            File::deleteDirectory($baseDirectory);
        }
    }

    public function test_it_re_dispatches_chunk_with_lower_threads_on_rate_limit_exit_code(): void
    {
        Queue::fake();
        config()->set('youtube.video_fetch_threads', 4);
        config()->set('youtube.video_rate_limit_max_retries', 5);
        config()->set('youtube.video_rate_limit_cooldown', 120);

        $channel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId('@chunk-rate-limit-' . uniqid())
            ->create();

        $baseDirectory = base_path('python/yt-dlp_jsons/' . $channel->youtube_id);
        $sourceFile = 'video_id_chunks/chunk_00001.jsonl';
        $sourcePath = $baseDirectory . '/' . $sourceFile;

        File::ensureDirectoryExists(dirname($sourcePath));
        File::put($sourcePath, json_encode(['id' => 'rate-limit-video-001'], JSON_THROW_ON_ERROR) . PHP_EOL);

        Process::fake(fn() => Process::result('', '', 29));
        Process::preventStrayProcesses();

        try {
            $job = new FetchYoutubeVideoChunkJob(
                channelId: $channel->id,
                youtubeId: $channel->youtube_id,
                chunkIndex: 0,
                chunkSize: 1,
                sourceFile: $sourceFile,
                threadCount: 3,
                rateLimitRetryAttempt: 0,
            );
            $job->handle(app(YtDlpAutoUpdateManager::class));

            Queue::assertPushedTimes(FetchYoutubeVideoChunkJob::class, 1);
            Queue::assertPushed(FetchYoutubeVideoChunkJob::class, function (FetchYoutubeVideoChunkJob $queuedJob) use ($channel, $sourceFile): bool {
                return $queuedJob->channelId === $channel->id
                    && $queuedJob->youtubeId === $channel->youtube_id
                    && $queuedJob->chunkIndex === 0
                    && $queuedJob->sourceFile === $sourceFile
                    && $queuedJob->threadCount === 2
                    && $queuedJob->rateLimitRetryAttempt === 1;
            });
        } finally {
            File::deleteDirectory($baseDirectory);
        }
    }

    public function test_it_passes_default_retention_to_python_when_configuration_is_invalid(): void
    {
        config()->set('logging.retention_days', 0);
        $channel = YoutubeChannel::factory()
            ->idle()
            ->forYoutubeId('@chunk-retention-' . uniqid())
            ->create();

        $baseDirectory = base_path('python/yt-dlp_jsons/' . $channel->youtube_id);
        $sourceFile = 'video_id_chunks/chunk_00001.jsonl';
        $sourcePath = $baseDirectory . '/' . $sourceFile;

        File::ensureDirectoryExists(dirname($sourcePath));
        File::put($sourcePath, json_encode(['id' => 'retention-video'], JSON_THROW_ON_ERROR) . PHP_EOL);
        $this->fakeYoutubeChunkProcessWithRowsByCall([[]]);

        try {
            $job = new FetchYoutubeVideoChunkJob(
                channelId: $channel->id,
                youtubeId: $channel->youtube_id,
                chunkIndex: 0,
                chunkSize: 1,
                sourceFile: $sourceFile,
            );
            $job->handle(app(YtDlpAutoUpdateManager::class));

            Process::assertRan(function (PendingProcess $process): bool {
                return $process->environment === ['LOG_RETENTION_DAYS' => '90']
                    && is_array($process->command)
                    && in_array(base_path('python/yt-dlp/video_fetch_chunk.py'), $process->command, true);
            });
        } finally {
            File::deleteDirectory($baseDirectory);
        }
    }

    public function test_failed_records_video_update_failure(): void
    {
        $this->mock(YtDlpAutoUpdateManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('recordFailure')
                ->once()
                ->with(YtDlpAutoUpdateManager::VIDEO_UPDATE_JOB, 'chunk job failed');
        });

        $job = new FetchYoutubeVideoChunkJob(
            channelId: 1,
            youtubeId: '@chunk-failed-' . uniqid(),
            chunkIndex: 0,
            chunkSize: 1,
            sourceFile: 'video_id_chunks/chunk_00001.jsonl',
        );

        $job->failed(new \RuntimeException('chunk job failed'));
    }
}

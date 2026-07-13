<?php

namespace App\Jobs;

use App\Jobs\Middleware\RateLimitYoutubeVideoChunk;
use App\Jobs\Middleware\SkipIfYoutubeBatchCancelled;
use App\Logging\WeeklyRotatingFileHandler;
use App\Models\YoutubeChannel;
use App\Models\YoutubeVideo;
use App\Support\PythonBinaryResolver;
use App\Support\Youtube\YtDlpAutoUpdateManager;
use Carbon\Carbon;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class FetchYoutubeVideoChunkJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    private const RATE_LIMIT_EXIT_CODE = 29;

    private const PARTIAL_FAILURE_EXIT_CODE = 30;

    public function __construct(
        public int $channelId,
        public string $youtubeId,
        public int $chunkIndex,
        public int $chunkSize,
        public string $sourceFile,
        public ?int $threadCount = null,
        public int $rateLimitRetryAttempt = 0
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new SkipIfYoutubeBatchCancelled,
            new RateLimitYoutubeVideoChunk,
        ];
    }

    public function handle(YtDlpAutoUpdateManager $ytDlpAutoUpdateManager): void
    {
        $channel = YoutubeChannel::query()->find($this->channelId);
        if ($channel === null || $channel->youtube_id !== $this->youtubeId) {
            return;
        }

        $baseDirectory = base_path('python/yt-dlp_jsons/' . $this->youtubeId);
        File::ensureDirectoryExists($baseDirectory);

        $chunksDirectory = $baseDirectory . '/video_chunks';
        File::ensureDirectoryExists($chunksDirectory);

        $chunkPrefix = 'chunk_' . str_pad((string) ($this->chunkIndex + 1), 5, '0', STR_PAD_LEFT) . '_' . now()->format('Ymd_His');
        $chunkJsonlPath = $chunksDirectory . '/' . $chunkPrefix . '.jsonl';
        $sourceFile = $baseDirectory . '/' . ltrim($this->sourceFile, '/');
        if (! File::exists($sourceFile)) {
            $message = 'Source chunk file missing.';
            Log::channel('youtube')->error($message, [
                'chunk_index' => $this->chunkIndex,
                'chunk_size' => $this->chunkSize,
                'source' => $sourceFile,
            ]);

            throw new \RuntimeException($message . ' Chunk source: ' . $sourceFile);
        }

        $pythonLogFile = (string) config('logging.channels.python.path', storage_path('logs/python.log'));
        $pythonLogDirectory = dirname($pythonLogFile);
        if ($pythonLogDirectory !== '' && $pythonLogDirectory !== '.') {
            File::ensureDirectoryExists($pythonLogDirectory);
        }

        $threadCount = $this->resolvedThreadCount();
        $pythonTimeoutSeconds = max(60, (int) config('youtube.python_process_timeout_seconds', 600));

        $processResult = Process::env([
            'LOG_RETENTION_DAYS' => (string) WeeklyRotatingFileHandler::normalizeRetentionDays(
                config('logging.retention_days'),
            ),
        ])->timeout($pythonTimeoutSeconds)->run([
            PythonBinaryResolver::resolve(),
            base_path('python/yt-dlp/video_fetch_chunk.py'),
            '--source-dir',
            $baseDirectory,
            '--source-file',
            ltrim($this->sourceFile, '/'),
            '--out-dir',
            $baseDirectory,
            '--jsonl-output',
            $chunkJsonlPath,
            '--log-file',
            $pythonLogFile,
            '--retries',
            '3',
            '--retry-delay',
            (string) config('youtube.video_retry_delay', 60),
            '--max-workers',
            (string) $threadCount,
            '--channel-id',
            $this->youtubeId,
        ]);

        if ($processResult->exitCode() === self::RATE_LIMIT_EXIT_CODE) {
            $this->handleRateLimit();

            return;
        }

        if ($processResult->exitCode() === self::PARTIAL_FAILURE_EXIT_CODE) {
            throw new \RuntimeException('yt-dlp video chunk fetch had one or more hard failures.');
        }

        if ($processResult->failed()) {
            $errorOutput = trim($processResult->errorOutput()) ?: trim($processResult->output());
            throw new \RuntimeException($errorOutput ?: 'yt-dlp video chunk fetch failed.');
        }

        if (! File::exists($chunkJsonlPath)) {
            $message = 'Chunk output missing.';
            Log::channel('youtube')->error($message, [
                'chunk_number' => $this->chunkIndex + 1,
                'expected_output' => $chunkJsonlPath,
            ]);

            throw new \RuntimeException($message . ' Expected output: ' . $chunkJsonlPath);
        }

        $rows = [];
        $now = now();

        foreach (File::lines($chunkJsonlPath) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $video = json_decode($line, true);
            if (! is_array($video)) {
                continue;
            }

            $youtubeVideoId = $video['id'] ?? null;
            if (! is_string($youtubeVideoId) || $youtubeVideoId === '') {
                continue;
            }

            $isUpcoming = $this->isUpcomingVideo($video);
            $scheduledStartAt = $this->parseDate(null, $video['release_timestamp'] ?? null);
            $publishedDate = $this->resolvePublishedDate($video, $isUpcoming);
            if (! $publishedDate instanceof Carbon) {
                Log::channel('youtube')->warning('Skipping video without published date.', [
                    'chunk_number' => $this->chunkIndex + 1,
                    'youtube_video_id' => $youtubeVideoId,
                ]);

                continue;
            }

            $updatedDate = $this->parseDate($video['modified_date'] ?? null, $video['modified_timestamp'] ?? null) ?? $publishedDate;

            $thumbnailUrl = $this->resolveThumbnailUrl($video);
            $videoTitle = (string) ($video['title'] ?? '');
            $description = (string) ($video['description'] ?? '');

            $rows[] = [
                'youtube_video_id' => $youtubeVideoId,
                'youtube_channel_id' => $this->channelId,
                'video_title' => $videoTitle,
                'published_date' => $publishedDate,
                'updated_date' => $updatedDate,
                'is_upcoming' => $isUpcoming,
                'scheduled_start_at' => $scheduledStartAt,
                'media_title' => $videoTitle,
                'media_content_url' => 'https://www.youtube.com/v/' . $youtubeVideoId . '?version=3',
                'media_thumbnail_url' => $thumbnailUrl,
                'media_description' => $description,
                'updated_at' => $now,
                'created_at' => $now,
            ];
        }

        if ($rows !== []) {
            if (! YoutubeChannel::query()->whereKey($this->channelId)->exists()) {
                return;
            }

            YoutubeVideo::query()->upsert(
                $rows,
                ['youtube_video_id'],
                [
                    'youtube_channel_id',
                    'video_title',
                    'published_date',
                    'updated_date',
                    'is_upcoming',
                    'scheduled_start_at',
                    'media_title',
                    'media_content_url',
                    'media_thumbnail_url',
                    'media_description',
                    'updated_at',
                ]
            );
        }

        $channel->incrementVideoFetchProgress($this->chunkSize);

        Log::channel('youtube')->info('Video chunk processed.', [
            'chunk_number' => $this->chunkIndex + 1,
            'chunk_size' => $this->chunkSize,
            'upserted_count' => count($rows),
            'output_jsonl' => $chunkJsonlPath,
            'thread_count' => $threadCount,
        ]);

        $ytDlpAutoUpdateManager->recordSuccess(YtDlpAutoUpdateManager::VIDEO_UPDATE_JOB);
    }

    public function failed(\Throwable $exception): void
    {
        app(YtDlpAutoUpdateManager::class)->recordFailure(
            YtDlpAutoUpdateManager::VIDEO_UPDATE_JOB,
            $exception->getMessage()
        );

        Log::channel('youtube')->error('Video chunk job failed.', [
            'channel_id' => $this->channelId,
            'youtube_id' => $this->youtubeId,
            'chunk_number' => $this->chunkIndex + 1,
            'error' => $exception->getMessage(),
        ]);
    }

    private function handleRateLimit(): void
    {
        $cooldown = max(1, (int) config('youtube.video_rate_limit_cooldown', 300));
        $minThreads = 1;
        $currentThreads = $this->resolvedThreadCount();
        $nextThreads = max($minThreads, $currentThreads - 1);
        $maxRetries = max(0, (int) config('youtube.video_rate_limit_max_retries', 5));
        $nextAttempt = $this->rateLimitRetryAttempt + 1;

        if ($nextAttempt > $maxRetries) {
            Log::channel('youtube')->error('Rate limit retry limit reached for chunk.', [
                'chunk_number' => $this->chunkIndex + 1,
                'attempt' => $this->rateLimitRetryAttempt,
                'max_retries' => $maxRetries,
                'current_threads' => $currentThreads,
            ]);

            throw new \RuntimeException('yt-dlp video chunk fetch exceeded rate-limit retries.');
        }

        Log::channel('youtube')->warning('Rate limit detected for chunk, re-dispatching with lower/equal threads.', [
            'chunk_number' => $this->chunkIndex + 1,
            'attempt' => $nextAttempt,
            'max_retries' => $maxRetries,
            'current_threads' => $currentThreads,
            'next_threads' => $nextThreads,
            'cooldown_seconds' => $cooldown,
        ]);

        $retryJob = new self(
            $this->channelId,
            $this->youtubeId,
            $this->chunkIndex,
            $this->chunkSize,
            $this->sourceFile,
            $nextThreads,
            $nextAttempt
        );
        $retryJob->delay(now()->addSeconds($cooldown));

        if ($this->batch() !== null) {
            if ($this->batch()->cancelled()) {
                return;
            }

            $this->batch()->add([$retryJob]);

            return;
        }

        dispatch($retryJob);
    }

    private function parseDate(mixed $ymdDate, mixed $timestamp, mixed $fallbackTimestamp = null): ?Carbon
    {
        if (is_numeric($timestamp)) {
            try {
                return Carbon::createFromTimestamp((int) $timestamp, 'UTC')->utc();
            } catch (\Throwable) {
                // Fall through.
            }
        }

        if (is_numeric($fallbackTimestamp)) {
            try {
                return Carbon::createFromTimestamp((int) $fallbackTimestamp, 'UTC')->utc();
            } catch (\Throwable) {
                // Fall through.
            }
        }

        if (is_string($ymdDate) && $ymdDate !== '') {
            try {
                return Carbon::createFromFormat('Ymd', $ymdDate, 'UTC')
                    ->startOfDay()
                    ->utc();
            } catch (\Throwable) {
                // Fall through.
            }
        }

        return null;
    }

    private function resolvePublishedDate(array $video, bool $isUpcoming): ?Carbon
    {
        $uploadDate = $video['upload_date'] ?? null;
        $releaseDate = $video['release_date'] ?? null;
        $timestamp = $video['timestamp'] ?? null;
        $releaseTimestamp = $video['release_timestamp'] ?? null;

        if ($isUpcoming) {
            return $this->parseDate($releaseDate, $releaseTimestamp, $timestamp)
                ?? $this->parseDate($uploadDate, $releaseTimestamp, $timestamp);
        }

        return $this->parseDate($releaseDate, $timestamp, $releaseTimestamp)
            ?? $this->parseDate($uploadDate, $timestamp, $releaseTimestamp);
    }

    private function isUpcomingVideo(array $video): bool
    {
        if (($video['is_upcoming'] ?? false) === true) {
            return true;
        }

        $liveStatus = $video['live_status'] ?? null;
        if (is_string($liveStatus) && in_array($liveStatus, ['is_upcoming', 'is_live'], true)) {
            return true;
        }

        return false;
    }

    private function resolveThumbnailUrl(array $video): string
    {
        $thumbnail = $video['thumbnail'] ?? null;
        if (is_string($thumbnail) && $thumbnail !== '') {
            return $thumbnail;
        }

        $thumbnails = $video['thumbnails'] ?? null;
        if (! is_array($thumbnails) || $thumbnails === []) {
            return '';
        }

        $last = end($thumbnails);
        if (is_array($last) && isset($last['url']) && is_string($last['url'])) {
            return $last['url'];
        }

        foreach ($thumbnails as $thumb) {
            if (is_array($thumb) && isset($thumb['url']) && is_string($thumb['url'])) {
                return $thumb['url'];
            }
        }

        return '';
    }

    private function resolvedThreadCount(): int
    {
        $configured = (int) config('youtube.video_fetch_threads', 8);

        return max(1, $this->threadCount ?? $configured);
    }
}

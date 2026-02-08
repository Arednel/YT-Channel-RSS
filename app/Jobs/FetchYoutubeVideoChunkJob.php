<?php

namespace App\Jobs;

use App\Jobs\Middleware\RateLimitYoutubeVideoChunk;
use App\Jobs\Middleware\SkipIfYoutubeBatchCancelled;
use App\Models\YoutubeChannel;
use App\Models\YoutubeVideo;
use App\Support\PythonBinaryResolver;
use Carbon\Carbon;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class FetchYoutubeVideoChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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

    public function handle(): void
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
            $this->channelLogger()->error($message, [
                'chunk_index' => $this->chunkIndex,
                'chunk_size' => $this->chunkSize,
                'source' => $sourceFile,
            ]);

            throw new \RuntimeException($message . ' Chunk source: ' . $sourceFile);
        }

        $pythonLogDirectory = base_path('python/logs/' . $this->youtubeId);
        File::ensureDirectoryExists($pythonLogDirectory);
        $pythonLogFile = $pythonLogDirectory . '/' . $chunkPrefix . '.log';
        $threadCount = $this->resolvedThreadCount();
        $pythonTimeoutSeconds = max(60, (int) config('youtube.python_process_timeout_seconds', 600));

        $processResult = Process::timeout($pythonTimeoutSeconds)->run([
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
            $this->channelLogger()->error($message, [
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

            $scheduledStartAt = $this->parseDate(null, $video['release_timestamp'] ?? null);
            $publishedDate = $this->parseDate(
                $video['upload_date'] ?? null,
                $video['timestamp'] ?? null,
                $video['release_timestamp'] ?? null
            );
            if (! $publishedDate instanceof Carbon) {
                $this->channelLogger()->warning('Skipping video without published date.', [
                    'chunk_number' => $this->chunkIndex + 1,
                    'youtube_video_id' => $youtubeVideoId,
                ]);
                continue;
            }

            $updatedDate = $this->parseDate($video['modified_date'] ?? null, $video['modified_timestamp'] ?? null) ?? $publishedDate;
            $isUpcoming = $this->isUpcomingVideo($video);

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

        $this->channelLogger()->info('Video chunk processed.', [
            'chunk_number' => $this->chunkIndex + 1,
            'chunk_size' => $this->chunkSize,
            'upserted_count' => count($rows),
            'output_jsonl' => $chunkJsonlPath,
            'thread_count' => $threadCount,
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
            $this->channelLogger()->error('Rate limit retry limit reached for chunk.', [
                'chunk_number' => $this->chunkIndex + 1,
                'attempt' => $this->rateLimitRetryAttempt,
                'max_retries' => $maxRetries,
                'current_threads' => $currentThreads,
            ]);

            throw new \RuntimeException('yt-dlp video chunk fetch exceeded rate-limit retries.');
        }

        $this->channelLogger()->warning('Rate limit detected for chunk, re-dispatching with lower/equal threads.', [
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

    private function isUpcomingVideo(array $video): bool
    {
        if (($video['is_upcoming'] ?? false) === true) {
            return true;
        }

        $liveStatus = $video['live_status'] ?? null;
        if (is_string($liveStatus) && $liveStatus === 'is_upcoming') {
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

    private function channelLogger(): \Psr\Log\LoggerInterface
    {
        $directory = storage_path('logs/' . $this->youtubeId);
        File::ensureDirectoryExists($directory);

        return Log::build([
            'driver' => 'single',
            'path' => $directory . '/video_chunk.log',
        ]);
    }

    private function resolvedThreadCount(): int
    {
        $configured = (int) config('youtube.video_fetch_threads', 8);
        return max(1, $this->threadCount ?? $configured);
    }
}

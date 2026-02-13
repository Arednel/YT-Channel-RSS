<?php

namespace App\Jobs;

use App\Support\PythonBinaryResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class UpdateYtDlpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 300;

    public function __construct(
        public string $reason = 'manual',
        public bool $force = false
    ) {
    }

    public static function isAutoUpdateEnabled(): bool
    {
        return (bool) config('youtube.yt_dlp_auto_update_enabled', true);
    }

    public static function weeklyUpdateDay(): int
    {
        return max(0, min(6, (int) config('youtube.yt_dlp_weekly_update_day', 1)));
    }

    public static function weeklyUpdateTime(): string
    {
        return (string) config('youtube.yt_dlp_weekly_update_time', '03:00');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('yt-dlp-update'))
                ->releaseAfter(300)
                ->expireAfter(3600),
        ];
    }

    public function handle(): void
    {
        if (! self::isAutoUpdateEnabled() && ! $this->force) {
            Log::channel('yt_dlp_update')->info('yt-dlp update skipped: auto update disabled.', [
                'reason' => $this->reason,
            ]);
            return;
        }

        $minimumIntervalHours = max(0, (int) config('youtube.yt_dlp_update_min_interval_hours', 24));
        $lastSuccessTimestamp = Cache::get($this->lastSuccessCacheKey());

        if (! $this->force && $minimumIntervalHours > 0 && is_numeric($lastSuccessTimestamp)) {
            $hoursSinceLastUpdate = now()->diffInHours(
                now()->setTimestamp((int) $lastSuccessTimestamp),
                true
            );

            if ($hoursSinceLastUpdate < $minimumIntervalHours) {
                Log::channel('yt_dlp_update')->info('yt-dlp update skipped: minimum interval not reached.', [
                    'reason' => $this->reason,
                    'hours_since_last_update' => $hoursSinceLastUpdate,
                    'minimum_interval_hours' => $minimumIntervalHours,
                ]);

                return;
            }
        }

        $timeoutSeconds = max(60, (int) config('youtube.yt_dlp_update_timeout_seconds', 1200));
        $python = PythonBinaryResolver::resolveVenvOrFail();
        $beforeVersion = $this->resolveYtDlpVersion($python);

        $result = Process::timeout($timeoutSeconds)->run([
            $python,
            '-m',
            'pip',
            'install',
            '--upgrade',
            '--disable-pip-version-check',
            'yt-dlp[default,deno]',
        ]);

        if ($result->failed()) {
            $errorOutput = trim($result->errorOutput()) ?: trim($result->output());
            throw new \RuntimeException($errorOutput !== '' ? $errorOutput : 'yt-dlp update failed.');
        }

        $afterVersion = $this->resolveYtDlpVersion($python);
        Cache::put($this->lastSuccessCacheKey(), now()->timestamp, now()->addDays(365));

        Log::channel('yt_dlp_update')->info('yt-dlp update completed.', [
            'reason' => $this->reason,
            'python_binary' => $python,
            'version_before' => $beforeVersion,
            'version_after' => $afterVersion,
            'output' => trim($result->output()),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('yt_dlp_update')->error('yt-dlp update job failed.', [
            'reason' => $this->reason,
            'error' => $exception->getMessage(),
        ]);
    }

    private function resolveYtDlpVersion(string $python): ?string
    {
        $versionResult = Process::timeout(60)->run([
            $python,
            '-m',
            'yt_dlp',
            '--version',
        ]);

        if ($versionResult->failed()) {
            return null;
        }

        $version = trim($versionResult->output());
        return $version !== '' ? $version : null;
    }

    private function lastSuccessCacheKey(): string
    {
        return 'youtube:yt-dlp:last-successful-update-at';
    }
}

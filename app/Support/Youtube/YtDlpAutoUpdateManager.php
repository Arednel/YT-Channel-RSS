<?php

namespace App\Support\Youtube;

use App\Jobs\UpdateYtDlpJob;
use Illuminate\Support\Facades\Cache;

class YtDlpAutoUpdateManager
{
    public const CHANNEL_UPDATE_JOB = 'channel_update';
    public const VIDEO_UPDATE_JOB = 'video_update';

    public function recordSuccess(string $jobType): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        Cache::forget($this->failureCountKey($jobType));
    }

    public function recordFailure(string $jobType, string $message): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if ($this->isRateLimitError($message)) {
            Cache::forget($this->failureCountKey($jobType));
            return;
        }

        $failureCount = (int) Cache::get($this->failureCountKey($jobType), 0) + 1;
        Cache::put($this->failureCountKey($jobType), $failureCount, now()->addDays(14));

        $threshold = max(0, (int) config('youtube.yt_dlp_failure_threshold', 3));
        if ($failureCount <= $threshold) {
            return;
        }

        $cooldownMinutes = max(1, (int) config('youtube.yt_dlp_failure_cooldown_minutes', 240));
        if (! Cache::add($this->failureDispatchGateKey($jobType), now()->timestamp, now()->addMinutes($cooldownMinutes))) {
            return;
        }

        Cache::forget($this->failureCountKey($jobType));

        UpdateYtDlpJob::dispatch('failure-threshold:' . $jobType);
    }

    private function isEnabled(): bool
    {
        return (bool) config('youtube.yt_dlp_auto_update_enabled', true);
    }

    private function isRateLimitError(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'rate limit')
            || str_contains($lower, 'rate-limit')
            || str_contains($lower, 'http error 429')
            || str_contains($lower, 'too many requests')
            || str_contains($lower, "confirm you're not a bot");
    }

    private function failureCountKey(string $jobType): string
    {
        return 'youtube:yt-dlp:auto-update:failure-count:' . $jobType;
    }

    private function failureDispatchGateKey(string $jobType): string
    {
        return 'youtube:yt-dlp:auto-update:dispatch-gate:' . $jobType;
    }
}

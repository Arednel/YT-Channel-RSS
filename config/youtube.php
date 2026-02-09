<?php

return [
    'video_chunk_size' => (int) env('YOUTUBE_VIDEO_CHUNK_SIZE', 50),
    'video_fetch_threads' => (int) env('YOUTUBE_VIDEO_FETCH_THREADS', 8),
    'video_rate_limit_cooldown' => (int) env('YOUTUBE_VIDEO_RATE_LIMIT_COOLDOWN', 300),
    'video_rate_limit_max_retries' => (int) env('YOUTUBE_VIDEO_RATE_LIMIT_MAX_RETRIES', 5),
    'video_retry_delay' => (int) env('YOUTUBE_VIDEO_RETRY_DELAY', 60),
    'python_process_timeout_seconds' => (int) env('YOUTUBE_PYTHON_PROCESS_TIMEOUT_SECONDS', 600),
    'sync_jobs_per_minute' => (int) env('YOUTUBE_SYNC_JOBS_PER_MINUTE', 0),
    'video_chunk_jobs_per_minute' => (int) env('YOUTUBE_VIDEO_CHUNK_JOBS_PER_MINUTE', 0),
    'maintenance_interval_minutes' => (int) env('YOUTUBE_MAINTENANCE_INTERVAL_MINUTES', 30),
    'yt_dlp_auto_update_enabled' => filter_var(env('YOUTUBE_YT_DLP_AUTO_UPDATE_ENABLED', true), FILTER_VALIDATE_BOOL),
    'yt_dlp_update_timeout_seconds' => (int) env('YOUTUBE_YT_DLP_UPDATE_TIMEOUT_SECONDS', 1200),
    'yt_dlp_update_min_interval_hours' => (int) env('YOUTUBE_YT_DLP_UPDATE_MIN_INTERVAL_HOURS', 24),
    'yt_dlp_failure_threshold' => (int) env('YOUTUBE_YT_DLP_FAILURE_THRESHOLD', 3),
    'yt_dlp_failure_cooldown_minutes' => (int) env('YOUTUBE_YT_DLP_FAILURE_COOLDOWN_MINUTES', 240),
    'yt_dlp_weekly_update_day' => (int) env('YOUTUBE_YT_DLP_WEEKLY_UPDATE_DAY', 1),
    'yt_dlp_weekly_update_time' => (string) env('YOUTUBE_YT_DLP_WEEKLY_UPDATE_TIME', '03:00'),
];

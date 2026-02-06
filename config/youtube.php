<?php

return [
    'video_chunk_size' => (int) env('YOUTUBE_VIDEO_CHUNK_SIZE', 50),
    'video_fetch_threads' => (int) env('YOUTUBE_VIDEO_FETCH_THREADS', 8),
    'video_rate_limit_cooldown' => (int) env('YOUTUBE_VIDEO_RATE_LIMIT_COOLDOWN', 300),
    'video_retry_delay' => (int) env('YOUTUBE_VIDEO_RETRY_DELAY', 60),
    'sync_jobs_per_minute' => (int) env('YOUTUBE_SYNC_JOBS_PER_MINUTE', 0),
    'video_chunk_jobs_per_minute' => (int) env('YOUTUBE_VIDEO_CHUNK_JOBS_PER_MINUTE', 0),
];

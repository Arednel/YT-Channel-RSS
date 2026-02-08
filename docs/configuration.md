# Configuration

## Runtime Requirements
- PHP `^8.2`
- Laravel `^12.0`
- Python 3 (project has been tested with Python 3.10.11)
- Python package: `yt-dlp[default,deno]` (`python/requirements.txt`)
- Database:
  - App runtime defaults to MySQL in `.env.example`
  - Queue driver defaults to `database`

## Core Laravel Environment
From `.env.example`:
- `APP_URL`: base URL used for RSS link generation.
- `DB_*`: primary DB settings.
- `QUEUE_CONNECTION=database`: required for queued jobs and batching tables.
- `CACHE_STORE=database`: used by maintenance interval gating.
- `FILESYSTEM_DISK=local`: default disk; feed writing explicitly uses `public` disk.

## YouTube-Specific Environment
Mapped in `config/youtube.php`.

### Chunking and concurrency
- `YOUTUBE_VIDEO_CHUNK_SIZE` (default `50`)
  - Number of video ids per chunk file.
- `YOUTUBE_VIDEO_FETCH_THREADS` (default `8`)
  - Python worker threads per chunk process.

### Retry/rate limit behavior
- `YOUTUBE_VIDEO_RATE_LIMIT_COOLDOWN` (default `300`)
  - Delay before re-dispatch when chunk exits with rate-limit code (`29`).
- `YOUTUBE_VIDEO_RATE_LIMIT_MAX_RETRIES` (default `5`)
  - Max rate-limit redispatch attempts per chunk.
- `YOUTUBE_VIDEO_RETRY_DELAY` (default `60`)
  - Passed to Python fetch script as per-video retry delay.

### Process and scheduling
- `YOUTUBE_PYTHON_PROCESS_TIMEOUT_SECONDS` (default `600`)
  - Timeout for both Python scripts.
- `YOUTUBE_MAINTENANCE_INTERVAL_MINUTES` (default `30`)
  - Cache gate interval for `youtube:maintenance --scheduled`.

### Queue throttle rate limiters
Registered in `AppServiceProvider`:
- `YOUTUBE_SYNC_JOBS_PER_MINUTE` (default `0`)
  - `0` means unlimited.
  - Applies to limiter `youtube-sync` by `channelId`.
- `YOUTUBE_VIDEO_CHUNK_JOBS_PER_MINUTE` (default `0`)
  - `0` means unlimited.
  - Applies to limiter `youtube-video-chunk` by `youtubeId`.

Queue middleware wrappers live under `App\Jobs\Middleware` and delegate to Laravel queue middleware primitives (`WithoutOverlapping`, `RateLimited`, `SkipIfBatchCancelled`).

## Filesystem Paths Used by Feature

### Feed output
- Disk: `public`
- Relative path: `feeds/{youtube_id}.xml`
- Absolute path: `storage/app/public/feeds/{youtube_id}.xml`

### Python artifacts
- Channel output root: `python/yt-dlp_jsons/{youtube_id}`
- Expected files:
  - `channel.json`
  - `videos.jsonl`
  - `video_id_chunks/*.jsonl`
  - `video_chunks/*.jsonl`

### Logs
- Laravel logs (per channel):
  - `storage/logs/{youtube_id}/sync.log`
  - `storage/logs/{youtube_id}/video_chunk.log`
  - `storage/logs/{youtube_id}/feed.log`
  - `storage/logs/{youtube_id}/delete.log`
- Python logs (per channel):
  - `python/logs/{youtube_id}/channel_fetch_*.log`
  - `python/logs/{youtube_id}/chunk_*.log`

## Python Binary Resolution
Resolved by `App\Support\PythonBinaryResolver`:
1. `python/venv/Scripts/python.exe`
2. `python/venv/bin/python`
3. fallback `python`

Recommended setup:
- Create and use local venv under `python/venv` so the resolver is deterministic.

## Recommended Production Defaults
- Keep queue worker running continuously (`queue:work`).
- Keep scheduler worker/cron active (`schedule:work` or cron + `schedule:run`).
- Set explicit, non-zero rate limits if target channels are large and rate-limits occur often.
- Set `APP_URL` to a publicly reachable domain if feeds will be consumed externally.

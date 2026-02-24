# Configuration

## Runtime Requirements
- PHP `^8.2`
- Laravel `^12.0`
- Python 3 (project has been tested with Python 3.10.11)
- Python package: `yt-dlp[default,deno]` (`python/requirements.txt`)
- Python test package: `pytest` (`python/requirements-dev.txt`)
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
  - Larger values increase per-job memory pressure because chunk rows are buffered before DB upsert.
- `YOUTUBE_VIDEO_FETCH_THREADS` (default `8`)
  - Python worker threads per chunk process.
  - Higher values increase parallel yt-dlp requests and can increase rate-limit risk.

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

## Logging Configuration
Defined in `config/logging.php`.

- `python` channel path is set to `storage/logs/python.log`.
- This is the shared Python process log target for channel fetch and chunk fetch scripts.

### yt-dlp auto-update
- `YOUTUBE_YT_DLP_AUTO_UPDATE_ENABLED` (default `true`)
  - Enables weekly scheduled update and failure-triggered update dispatch.
- `YOUTUBE_YT_DLP_WEEKLY_UPDATE_DAY` (default `1`)
  - Weekly day number for scheduler (`0=Sunday` ... `6=Saturday`).
- `YOUTUBE_YT_DLP_WEEKLY_UPDATE_TIME` (example env `05:00`, config fallback `03:00`)
  - Weekly schedule time (24h format).
- `YOUTUBE_YT_DLP_UPDATE_TIMEOUT_SECONDS` (default `1200`)
  - Process timeout for `pip install --upgrade`.
- `YOUTUBE_YT_DLP_UPDATE_MIN_INTERVAL_HOURS` (example env `6`, config fallback `24`)
  - Minimum time between successful updates.
- `YOUTUBE_YT_DLP_FAILURE_THRESHOLD` (default `3`)
  - Auto-update is triggered when a job type exceeds this non-rate-limit failure count.
- `YOUTUBE_YT_DLP_FAILURE_COOLDOWN_MINUTES` (default `240`)
  - Cooldown gate before another failure-triggered update can be dispatched.

### Queue throttle rate limiters
Registered in `AppServiceProvider`:
- `YOUTUBE_SYNC_JOBS_PER_MINUTE` (default `0`)
  - `0` means unlimited.
  - Applies to limiter `youtube-sync` by `channelId`.
- `YOUTUBE_VIDEO_CHUNK_JOBS_PER_MINUTE` (default `0`)
  - `0` means unlimited.
  - Applies to limiter `youtube-video-chunk` by `youtubeId`.

Queue middleware wrappers live under `App\Jobs\Middleware` and delegate to Laravel queue middleware primitives (`WithoutOverlapping`, `RateLimited`, `SkipIfBatchCancelled`).

## Chunk Payload Contract (Current)
- `python/yt-dlp/video_fetch_chunk.py` writes compact JSONL rows containing only fields Laravel currently persists.
- Successful yt-dlp responses are sanitized before JSON encoding to avoid non-serializable values.
- `restricted` and `upcoming` videos use fallback compact payloads so chunk jobs continue instead of failing fast.
- This reduced payload format is intentional and helps avoid PHP memory exhaustion when reading chunk files.

## Timestamp Persistence Behavior
- `published_date` and `updated_date` are stored as UTC timestamps in MySQL.
- Import behavior prefers Unix timestamp fields when present (`timestamp`, `modified_timestamp`, `release_timestamp`), then falls back to date-only `release_date` and `upload_date` (`Ymd`).
- Result: full time is preserved when yt-dlp provides it; otherwise values fall back to midnight UTC.

## Filesystem Paths Used by Feature

### Feed output
- Disk: `public`
- Relative path: `feeds/{youtube_id}.xml`
- Absolute path: `storage/app/public/feeds/{youtube_id}.xml`

### Python artifacts
- Channel output root: `python/yt-dlp_jsons/{youtube_id}`
  - Path key is the channel's `youtube_id` at fetch time.
  - If a channel later promotes from `UC...` to `@handle`, older artifact folders are not auto-renamed.
- Expected files:
  - `channel.json`
  - `videos.jsonl`
  - `video_id_chunks/*.jsonl`
  - `video_chunks/*.jsonl`

### Logs
- Laravel workflow log:
  - `storage/logs/youtube.log`
- Python fetch log:
  - `storage/logs/python.log`
- yt-dlp update log:
  - `storage/logs/yt-dlp-update.log`

## Python Binary Resolution
Resolved by `App\Support\PythonBinaryResolver`:
1. `python/venv/Scripts/python.exe`
2. `python/venv/bin/python`
3. fallback `python`

Recommended setup:
- Create and use local venv under `python/venv` so the resolver is deterministic.
- `UpdateYtDlpJob` is strict and updates only venv yt-dlp (`python/venv/*` interpreter); it fails if venv Python is missing.

## Test Configuration Files
- `.env.testing.example`: template for test-only DB and basic Laravel test env values (`DB_*`, queue/session/cache defaults).
- `phpunit.xml`: Laravel/PHPUnit environment and test suite paths.
- `pytest.ini`: points pytest to `python/tests`.

## Recommended Production Defaults
- Keep queue worker running continuously (`queue:work`).
- Keep scheduler worker/cron active (`schedule:work` or cron + `schedule:run`).
- Set explicit, non-zero rate limits if target channels are large and rate-limits occur often.
- Set `APP_URL` to a publicly reachable domain if feeds will be consumed externally.

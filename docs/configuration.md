# Configuration

## Environment Files
- `.env`: local/manual runtime
- `.env.example`: template for `.env`
- `.env.testing`: local test runtime
- `.env.testing.example`: template for `.env.testing`
- `docker/.env.docker`: Docker Compose runtime for `app`, `queue`, `scheduler`, `database`, and `pma`
- `docker/.env.testing.docker`: Docker Compose runtime for the one-off `tests` service

## Simple Docker Setup
Run from the project root:
- `docker compose --env-file docker/.env.docker up --build -d`

This path:
- builds the PHP 8.3 app image from `docker/app.dockerfile`
- installs Composer dependencies and the Python `yt-dlp` venv inside the app image
- builds the Nginx image from `docker/web.dockerfile`
- starts MySQL 8, the queue worker, scheduler worker, Nginx, and phpMyAdmin (phpMyAdmin disabled for security)
- keeps the Docker test service behind the `test` Compose profile
- runs `php artisan migrate` through `docker/docker-app-entrypoint.sh`

Access points:
- Channels page: `http://localhost:8080`
- Options page: `http://localhost:8080/options`
- phpMyAdmin: `http://localhost:8888` (uncomment in compose.yaml, disabled for security)

## Required Local Setup
1. Create `.env` from `.env.example` if it does not already exist.
2. Install PHP dependencies:
   - `composer install`
3. Configure app key:
   - `php artisan key:generate`
4. Run migrations:
   - `php artisan migrate`
5. Create Python venv and install fetcher dependencies:
   - `python -m venv python/venv`
   - activate venv
   - `pip install -r python/requirements.txt`

## Database Settings
Main DB settings are in:
- `.env` for local/manual runtime
- `docker/.env.docker` for Docker Compose runtime
- `docker/.env.testing.docker` for Docker Compose test runtime

Relevant variables:
- `DB_CONNECTION`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

Docker database services:
- `database` stores normal app data in the `dbdata` Docker volume
- `database_test` stores test data in the `dbdata_test` Docker volume and is used only by the `tests` service

## Queue and Scheduler
Queued channel sync, video chunks, feed builds, deletes, and yt-dlp updates use Laravel's database queue.

Relevant variables:
- `QUEUE_CONNECTION=database`
- `CACHE_STORE=database`

Run these from the project root for local/manual runtime:
```bash
php artisan queue:work
php artisan schedule:work
```

When using Docker Compose, `queue` and `scheduler` are separate services and do not need to be started manually inside the `app` container.

Optional local dev aggregate command:
```bash
composer dev
```

## Manual Maintenance Commands
Run maintenance for eligible channels:
```bash
php artisan youtube:maintenance
```

Run maintenance for one channel id:
```bash
php artisan youtube:maintenance --channel-id=123
```

Force scheduled maintenance behavior and bypass the interval gate:
```bash
php artisan youtube:maintenance --scheduled --force
```

Run yt-dlp update now:
```bash
php artisan youtube:yt-dlp:update
php artisan youtube:yt-dlp:update --force
php artisan youtube:yt-dlp:update --queued
```

## App Options
The `options` table stores app-level settings as scalar string values keyed by `options.key`.

- `channels_per_page`
  - Controls how many channels the list renders per page.
  - Default: `100`.
  - Fixed choices: `10`, `25`, `50`, `100`, `250`, `500`, `1000`.
  - The Options page also accepts a custom positive integer.
  - `unlimited` disables pagination links and renders every matching channel.

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

- `LOG_RETENTION_DAYS` (default `90`)
  - Controls all four weekly log families.
  - Missing, non-integer, zero, and negative values normalize to `90` in both PHP and Python.
  - As in DLSite List, Python constructs an expiry datetime from this value; keep custom positive values within Python's supported datetime range.
- Log weeks run Monday through Sunday in UTC. Each family writes plain UTF-8 text to the UTC Monday filename:
  - `storage/logs/laravel-{YYYY-MM-DD}.log`
  - `storage/logs/python-{YYYY-MM-DD}.log`
  - `storage/logs/youtube-{YYYY-MM-DD}.log`
  - `storage/logs/yt-dlp-update-{YYYY-MM-DD}.log`
- Laravel uses the custom Monolog handler `App\Logging\WeeklyRotatingFileHandler`; PHP writes use file locking.
- The `python` channel compatibility path remains `storage/logs/python.log`. Laravel passes that base path to both Python scripts, and `setup_logging(log_file)` derives the weekly directory and `python` stem from it.
- Laravel explicitly passes the normalized retention value to both Python process entrypoints. Direct script execution reads `LOG_RETENTION_DAYS` and defaults to `90`.
- Cleanup is lazy and checked before every write. An archive is eligible only after its complete week plus the retention period has elapsed, so records are effectively retained for 90–96 days after they are written with the default setting.
- Cleanup selects only exact family filenames containing a valid UTC Monday. Active, future, malformed, unrelated, and legacy base files are ignored.
- Concurrent removal is treated as successful cleanup. Other cleanup errors go to PHP system error output or Python stderr and do not block the current log write.

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
  - `storage/logs/youtube-{UTC Monday}.log`
- Python fetch log:
  - `storage/logs/python-{UTC Monday}.log`
- yt-dlp update log:
  - `storage/logs/yt-dlp-update-{UTC Monday}.log`
- Default Laravel application log:
  - `storage/logs/laravel-{UTC Monday}.log`

Existing `laravel.log`, `python.log`, `youtube.log`, and `yt-dlp-update.log` files are legacy files and are never removed by weekly cleanup.

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

# Operations Runbook

## First-Time Setup
Run from project root:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Create Python venv and install dependencies:

```bash
python -m venv python/venv

# Windows:
python\venv\Scripts\activate
# Linux/macOS:
source python/venv/bin/activate

pip install -r python/requirements.txt
```

Install Python test dependencies when running pytest:

```bash
pip install -r python/requirements-dev.txt
```

## Day-to-Day Runtime

### Queue worker
```bash
php artisan queue:work
```

### Scheduler worker
```bash
php artisan schedule:work
```

### Optional local dev aggregate script
```bash
composer dev
```

### yt-dlp auto-update behavior
- Weekly: scheduler dispatches `UpdateYtDlpJob` based on `YOUTUBE_YT_DLP_WEEKLY_UPDATE_DAY` / `YOUTUBE_YT_DLP_WEEKLY_UPDATE_TIME`.
- Failure-triggered: non-rate-limit failures in `SyncYoutubeChannelJob` (`channel_update`) or `FetchYoutubeVideoChunkJob` (`video_update`) increment counters.
- When failures are greater than `YOUTUBE_YT_DLP_FAILURE_THRESHOLD`, update job is dispatched (cooldown controlled by `YOUTUBE_YT_DLP_FAILURE_COOLDOWN_MINUTES`).

## Manual Commands

### Trigger maintenance for eligible channels
```bash
php artisan youtube:maintenance
```
- This uses `YoutubeChannel::eligibleForMaintenance()` and then skips any channel that still has an active batch id.

### Trigger maintenance for a single channel id
```bash
php artisan youtube:maintenance --channel-id=123
```

### Force scheduled run behavior (bypass interval gate)
```bash
php artisan youtube:maintenance --scheduled --force
```

### Run yt-dlp update now
```bash
php artisan youtube:yt-dlp:update
```

### Run yt-dlp update now and bypass interval gate
```bash
php artisan youtube:yt-dlp:update --force
```

### Dispatch yt-dlp update to queue
```bash
php artisan youtube:yt-dlp:update --queued
```

## Test Commands

### Laravel / PHPUnit
```bash
php artisan test
```

### Python / pytest
```bash
pytest
```

## Sync Lifecycle (Operational View)
1. Channel added in UI -> `YoutubeChannel::markQueuedForSync()` (`queued`).
2. `SyncYoutubeChannelJob` starts -> `YoutubeChannel::markFetchingVideoList()`.
3. Python channel list fetch runs.
4. Chunk plan generated:
   - New videos included.
   - Existing videos included only if currently marked `is_upcoming`.
5. If chunks exist -> `YoutubeChannel::markFetchingVideos()`, batch runs chunk jobs.
   - Per-video statuses `restricted` and `upcoming` are persisted as fallback metadata and do not count as hard chunk failures.
6. Batch finalize:
   - Any failed chunks -> `YoutubeChannel::markFailed()`.
   - Otherwise -> `YoutubeChannel::markBuildingFeed()`.
7. Feed built -> `YoutubeChannel::markIdle()` (`idle`, `last_sync_at` updated).

## Deletion Lifecycle
1. UI applies `YoutubeChannel::markDeleting()` (`deleting`).
2. Active chunk batch is canceled.
3. Feed file and Python artifact directory deleted.
4. Channel row deleted; videos deleted by FK cascade.

## Monitoring Checklist
- `youtube_channels.status`:
  - Many `failed`/`feed failed` rows indicate upstream or infra issues.
- `youtube_channels.last_error`:
  - Contains most recent failure detail.
- Queue backlog:
  - Check `jobs` / `failed_jobs` tables.
- Application logs:
  - Laravel workflow log: `storage/logs/youtube.log`
  - Python fetch log: `storage/logs/python.log`
  - yt-dlp update log: `storage/logs/yt-dlp-update.log`

## Failure Recovery

### Channel stuck in failed/feed failed
1. Inspect `last_error` and logs.
2. Fix root cause (network, Python env, yt-dlp behavior, DB issue).
3. Re-dispatch:
   - UI add/delete flow if needed, or
   - `php artisan youtube:maintenance --channel-id={id}`.

### Stale active batch reference
- `YoutubeBatchManager::hasActiveVideoBatch()` auto-cleans missing/finished/cancelled batch ids.
- Running maintenance again is enough in most cases.

### Rate-limit storms
- Symptoms:
  - frequent chunk exit code `29`
  - many retries with reduced threads
- Mitigations:
  - lower `YOUTUBE_VIDEO_FETCH_THREADS`
  - increase `YOUTUBE_VIDEO_RATE_LIMIT_COOLDOWN`
  - reduce queue throughput via `YOUTUBE_VIDEO_CHUNK_JOBS_PER_MINUTE`

## Feed Serving Notes
- Feeds are prebuilt XML files on `public` disk.
- Endpoint returns no-cache headers and removes conditional cache headers.
- If feed file does not exist, endpoint returns `404`.

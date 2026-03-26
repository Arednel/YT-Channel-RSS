# Architecture

## Goal
Generate and serve Atom feeds for YouTube channels identified by handle (`@channel`) or channel id (`UC...`) by combining:
- Laravel 12 app/API/UI and queue orchestration.
- Python `yt-dlp` scripts for YouTube metadata collection.
- MySQL persistence for channels/videos.

## Stack
- PHP 8.2+
- Laravel 12
- Livewire (single-page table + modals)
- Queue: Laravel database queue + batching
- Python 3 (tested with Python 3.10.11) `yt-dlp` wrappers (`python/yt-dlp`)

## Docker Compose Topology
Current containerized runtime is split by responsibility:
- `app`
  - PHP-FPM service built from `docker/app.dockerfile`.
  - Uses entrypoint `docker/docker-app-entrypoint.sh`.
  - Runs `php artisan migrate` during container startup, then starts `php-fpm`.
- `queue`
  - Reuses the same PHP/Laravel image as `app`.
  - Runs `php artisan queue:work`.
- `scheduler`
  - Reuses the same PHP/Laravel image as `app`.
  - Runs `php artisan schedule:work`.
- `web`
  - Nginx service built from `docker/web.dockerfile`.
  - Serves `/public` and proxies PHP requests to `app:9000`.
- `database`
  - MySQL 8.0.
- `pma`
  - phpMyAdmin.

Shared writable Docker volumes are mounted for:
- `python/yt-dlp_jsons`
- `storage/app`
- `storage/framework`
- `storage/logs`

## Main HTTP Endpoints
- `GET /`
  - Renders `resources/views/Index.blade.php`.
  - Mounts Livewire component `YoutubeRssDashboard` (which renders `YoutubeRssChannelsTable`).
- `GET /feeds/{youtubeChannel:youtube_id}.xml`
  - Serves prebuilt file: `storage/app/public/feeds/{youtube_id}.xml`.
  - Content type: `application/atom+xml; charset=UTF-8`.
  - Explicit no-cache headers, removes `ETag` and `Last-Modified`.

## UI Flow (Livewire)
- Add channel:
  - Validates `channelUrl`.
  - Accepts only channel URLs in `https://youtube.com/...`, `https://www.youtube.com/...`, or `https://m.youtube.com/...` hosts, with `/@...` or `/channel/UC...` paths (including tail paths like `/videos`).
  - Extracts canonical identifier from the URL and normalizes percent-encoded handles.
  - URL parsing/normalization is centralized in `App\Support\Youtube\YoutubeChannelReference`.
  - Applies duplicate checks across both `youtube_id` and `youtube_channel_id`.
  - Creates `youtube_channels` row.
  - Calls `DispatchSyncYoutubeChannelJobAction`.
  - Action acquires sync unique lock first, then applies `markQueuedForSync()` and dispatches `SyncYoutubeChannelJob`.
- Delete channel:
  - User picks a channel and confirms.
  - Cancels active batch (if present).
  - Applies domain transition `YoutubeChannel::markDeleting()`.
  - Dispatches `DeleteYoutubeChannelJob`.
- Copy RSS link:
  - Allowed only when `YoutubeChannel::canCopyRssLink()` is true (`idle`).
  - Busy states return message and block copy.

## Data Model

### `youtube_channels`
- `id` (PK)
- `youtube_id` (unique; route key, usually handle when resolved)
- `youtube_channel_id` (unique, nullable; stable `UC...` id)
- `channel_name` (nullable)
- `status` (string, enum-cast via `YoutubeChannelStatus`)
- `last_sync_at` (nullable datetime)
- `last_video_id` (nullable)
- `last_error` (nullable text)
- `active_video_batch_id` (nullable, indexed)
- `video_fetch_progress_current` (nullable unsigned int)
- `video_fetch_progress_total` (nullable unsigned int)
- timestamps

### `youtube_videos`
- `id` (PK)
- `youtube_video_id` (unique)
- `youtube_channel_id` (FK -> `youtube_channels`, cascade delete)
- `video_title`
- `published_date`
- `updated_date`
- `is_upcoming` (bool, indexed)
- `scheduled_start_at` (nullable datetime, indexed)
- `media_title`
- `media_content_url`
- `media_thumbnail_url`
- `media_description`
- timestamps
- index: `(youtube_channel_id, published_date)`

## Status Domain Rules
Centralized in:
- `App\Enums\YoutubeChannelStatus`
- `App\Models\YoutubeChannel`

Key rules:
- `isBusy()` is true for: `queued`, `syncing`, `fetching video list`, `fetching videos`, `building feed`, `deleting`.
- RSS copy allowed only for `idle`.
- Model helpers expose `isBusy()`, `isIdle()`, `isDeleting()`, `canCopyRssLink()`.
- `status_label` appends progress only for `fetching videos`, in format `fetching videos (x out of x)`.
- State transitions are applied via model methods:
  - `markQueuedForSync()`
  - `markDeleting()`
  - `markFetchingVideoList()`
  - `markFetchingVideos()`
  - `markBuildingFeed()`
  - `markIdle()`
  - `markFailed()`
  - `markFeedFailed()`
- Maintenance query scopes:
  - `busy()`
  - `notBusy()`
  - `eligibleForMaintenance()`

## Queue + Action Pipeline

### 1) Channel Sync Dispatch
- Entry points:
  - Livewire add-channel flow.
  - `YoutubeMaintenanceCommand` loop.
- Action: `DispatchSyncYoutubeChannelJobAction`
- Behavior:
  - Builds `SyncYoutubeChannelJob`.
  - Acquires Laravel `UniqueLock` before state mutation.
  - On lock success: applies `markQueuedForSync()` and dispatches to queue.
  - On lock miss: returns `false`; channel state is unchanged.

### 2) Channel Sync Job
- Job: `SyncYoutubeChannelJob`
- Contracts:
  - `ShouldQueue`
  - `ShouldBeUnique` (`uniqueFor=7200`, unique id by channel id)
- Middleware:
  - `App\Jobs\Middleware\PreventOverlappingYoutubeChannel`
  - `App\Jobs\Middleware\RateLimitYoutubeSync`
  - `WithoutOverlapping('youtube-channel:{id}')`
  - `RateLimited('youtube-sync')`
- Behavior:
  - Dispatches a Laravel chain:
    - `FetchYoutubeChannelInfoAndVideoListJob`
    - `DispatchYoutubeVideoSyncPhaseJob`
  - On failure: marks channel failed and clears `active_video_batch_id`.

### 3) Fetch Channel Info + Video List Phase
- Job: `FetchYoutubeChannelInfoAndVideoListJob`
- Middleware:
  - `App\Jobs\Middleware\PreventOverlappingYoutubeChannel`
  - `App\Jobs\Middleware\RateLimitYoutubeSync`
  - `WithoutOverlapping('youtube-channel:{id}')`
  - `RateLimited('youtube-sync')`
- Action method: `RunYoutubeChannelSyncAction::fetchChannelInfoAndVideoList()`
- Steps:
  1. Guard: channel exists, not deleting, no active video batch.
  2. Apply `markFetchingVideoList()`.
  3. Run Python channel fetch (`ChannelFetchRunner` -> `channel_fetch.py`).
  4. Read `channel.json` (if present), update `channel_name`, and resolve/store canonical ids:
     - store `youtube_channel_id` when metadata exposes `UC...`
     - resolve preferred handle in order: `uploader_id`, then `uploader_url`, then `channel_url`
     - keep `youtube_id` unchanged in this phase (`allowHandleUpdate=false`)
     - resolve handle/UC conflicts by keeping one canonical DB row and deleting duplicates
  5. Validate `videos.jsonl`.
  6. If this row was identified as a duplicate and removed, stop this sync (`false` return).
  7. On success, reset `channel_update` failure counter in `YtDlpAutoUpdateManager`.

### 4) Dispatch Video Phase
- Job: `DispatchYoutubeVideoSyncPhaseJob`
- Middleware:
  - `App\Jobs\Middleware\PreventOverlappingYoutubeChannel`
  - `App\Jobs\Middleware\RateLimitYoutubeSync`
  - `WithoutOverlapping('youtube-channel:{id}')`
  - `RateLimited('youtube-sync')`
- Action method: `RunYoutubeChannelSyncAction::dispatchVideoPhase()`
- Steps:
  1. Guard: channel exists, not deleting, no active video batch.
  2. Validate `videos.jsonl`.
  3. Build chunk plan (`VideoChunkPlanner`):
     - Include new videos.
     - Re-include existing videos only if DB says `is_upcoming=true`.
  4. If plan has jobs:
     - Dispatch bus batch via `DispatchYoutubeVideoChunkBatchAction`.
     - Set `active_video_batch_id`.
  5. If no jobs:
     - Optionally update `last_video_id`.
     - Re-read persisted `channel.json` and allow handle promotion (`allowHandleUpdate=true`) before feed decisions.
     - If feed file missing, apply `markBuildingFeed(true)` and dispatch `BuildYoutubeFeedJob`.
     - Else apply `markIdle()` via `FinalizeYoutubeChannelSyncWithoutBatchAction`.

### 5) Chunk Dispatch
- Action: `DispatchYoutubeVideoChunkBatchAction`
- Inside a DB transaction:
  - Reloads channel row and applies `markFetchingVideos($lastVideoId, $queuedVideoCount)`.
  - Dispatches batch of `FetchYoutubeVideoChunkJob` with `finally` callback to `FinalizeYoutubeVideoChunkBatchAction`.
  - Persists `active_video_batch_id`.

### 6) Chunk Processing
- Job: `FetchYoutubeVideoChunkJob`
- Middleware:
  - `App\Jobs\Middleware\SkipIfYoutubeBatchCancelled`
  - `App\Jobs\Middleware\RateLimitYoutubeVideoChunk`
  - `SkipIfBatchCancelled`
  - `RateLimited('youtube-video-chunk')`
- Runs Python `video_fetch_chunk.py` for one chunk.
- Python classifies each video fetch as `ok`, `restricted`, `upcoming`, `failed`, or `rate_limited`.
- `ok` rows use sanitized yt-dlp metadata and are written as compact payloads (only fields consumed by Laravel).
- `restricted` and `upcoming` statuses write fallback compact payloads and continue chunk processing.
- Exit code handling:
  - `29`: rate-limited -> requeue same chunk later with reduced threads.
  - `30`: partial failure -> throws runtime exception.
- Upserts rows to `youtube_videos`.
- Normalizes timestamps to UTC.
- Increments channel progress via `incrementVideoFetchProgress($chunkSize)`; counter is clamped to total.
- Timestamp resolution in `FetchYoutubeVideoChunkJob`:
  - `published_date`: prefers Unix `timestamp`, then `release_timestamp`, then date-only `release_date`, then `upload_date` (`Ymd`, midnight UTC).
  - `updated_date`: prefers `modified_timestamp` and falls back to `published_date`.

### 7) Batch Finalization
- Action: `FinalizeYoutubeVideoChunkBatchAction` (called from batch `finally`)
- Behavior:
  - Clear `active_video_batch_id`.
  - If deleting: stop.
  - If any failed jobs: apply `markFailed()` with error text.
  - Else apply `markBuildingFeed()` and dispatch `BuildYoutubeFeedJob`.

### 8) Feed Build
- Job: `BuildYoutubeFeedJob`
- Middleware:
  - `App\Jobs\Middleware\PreventOverlappingYoutubeFeedBuild`
  - `WithoutOverlapping('youtube-feed-build:{id}')`
- Before XML generation, re-syncs persisted metadata identifiers (`syncIdentifiersFromPersistedMetadata(..., true)`).
- Uses `YoutubeFeedXmlBuilder` to write Atom XML file to public disk.
- Success: apply `markIdle()`.
- Failure: apply `markFeedFailed()`.

### 9) Channel Delete
- Job: `DeleteYoutubeChannelJob`
- Middleware:
  - `App\Jobs\Middleware\PreventOverlappingYoutubeChannel`
  - `WithoutOverlapping('youtube-channel:{id}')`
- Steps:
  - Cancel active batch.
  - Delete feed xml.
  - Delete Python artifact directory for the current `youtube_id`.
  - Delete DB channel row (videos cascade by FK).

## Scheduler
- `routes/console.php` schedules:
  - `youtube:maintenance --scheduled` every thirty minutes.
  - `UpdateYtDlpJob('weekly-schedule')` weekly (`YOUTUBE_YT_DLP_WEEKLY_UPDATE_DAY` / `YOUTUBE_YT_DLP_WEEKLY_UPDATE_TIME`) when auto-update is enabled.
- Command: `YoutubeMaintenanceCommand`
  - Optional `--channel-id`.
  - Optional `--force` to bypass interval gate.
  - Cache gate interval controlled by `YOUTUBE_MAINTENANCE_INTERVAL_MINUTES`.
  - Uses `YoutubeChannel::eligibleForMaintenance()` when no specific channel is provided.
  - Per channel, skips when:
    - `YoutubeBatchManager::hasActiveVideoBatch()` is true.
    - `YoutubeChannel::isBusy()` is true.
    - dispatch action returns `false` because unique sync lock is already held.

## yt-dlp Update Automation
- Job: `UpdateYtDlpJob`
  - Runs `python -m pip install --upgrade yt-dlp[default,deno]`.
  - Uses queue overlap lock key `yt-dlp-update`.
  - Stores last successful update timestamp in cache to enforce minimum interval.
- Failure-triggered update path:
  - `SyncYoutubeChannelJob` centralizes channel-sync failure handling through `HandleYoutubeChannelSyncFailureAction`.
  - This captures failures from the chained channel phases (`FetchYoutubeChannelInfoAndVideoListJob`, `DispatchYoutubeVideoSyncPhaseJob`) and chain-dispatch failures.
  - `FetchYoutubeVideoChunkJob` reports `video_update` failures to `YtDlpAutoUpdateManager`.
  - Only non-rate-limit failures are counted.
  - When failure count exceeds configured threshold, update job is auto-dispatched (with cooldown gate).

## Python Boundary
- `python/yt-dlp/channel_fetch.py`
  - Produces `channel.json` and `videos.jsonl`.
- `python/yt-dlp/video_fetch_chunk.py`
  - Produces per-chunk JSONL with compact metadata payloads used by Laravel.
  - Handles rate limiting and restricted/upcoming fallback payloads.
  - Uses status-aware behavior so upcoming live events do not fail the entire chunk.
- Shared helpers:
  - `python/yt-dlp/lib/common.py`
  - `python/yt-dlp/lib/channel_list.py`
  - `python/yt-dlp/lib/video_detail.py`
    - Classifies yt-dlp errors (rate-limited/restricted/upcoming/failed).
    - Sanitizes metadata payloads before serialization.

## Feed Timestamp Output
- Atom `<published>` and `<updated>` values are emitted from DB datetimes via `Carbon::toAtomString()`.
- When Unix timestamps are available from yt-dlp, feed entries include full time (hour/minute/second) instead of midnight-only dates.

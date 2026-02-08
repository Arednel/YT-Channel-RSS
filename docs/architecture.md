# Architecture

## Goal
Generate and serve Atom feeds for YouTube channels identified by handle (`@channel`) by combining:
- Laravel 12 app/API/UI and queue orchestration.
- Python `yt-dlp` scripts for YouTube metadata collection.
- MySQL persistence for channels/videos.

## Stack
- PHP 8.2+
- Laravel 12
- Livewire (single-page table + modals)
- Queue: Laravel database queue + batching
- Python `yt-dlp` wrappers (`python/yt-dlp`)

## Main HTTP Endpoints
- `GET /`
  - Renders `resources/views/Index.blade.php`.
  - Mounts Livewire component `YoutubeRssChannelsTable`.
- `GET /feeds/{youtubeChannel:youtube_id}`
  - Serves prebuilt file: `storage/app/public/feeds/{youtube_id}.xml`.
  - Content type: `application/atom+xml; charset=UTF-8`.
  - Explicit no-cache headers, removes `ETag` and `Last-Modified`.

## UI Flow (Livewire)
- Add channel:
  - Validates `channelUrl`.
  - Extracts first `@handle` from URL.
  - Creates `youtube_channels` row.
  - Applies domain transition `YoutubeChannel::markQueuedForSync()`.
  - Dispatches `SyncYoutubeChannelJob`.
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
- `youtube_id` (unique, handle or channel id)
- `channel_name` (nullable)
- `status` (string, enum-cast via `YoutubeChannelStatus`)
- `last_sync_at` (nullable datetime)
- `last_video_id` (nullable)
- `last_error` (nullable text)
- `active_video_batch_id` (nullable, indexed)
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

### 1) Channel Sync Entry
- Job: `SyncYoutubeChannelJob`
- Middleware:
  - `App\Jobs\Middleware\PreventOverlappingYoutubeChannel`
  - `App\Jobs\Middleware\RateLimitYoutubeSync`
  - `WithoutOverlapping('youtube-channel:{id}')`
  - `RateLimited('youtube-sync')`
- Behavior:
  - Delegates to `RunYoutubeChannelSyncAction`.

### 2) Run Sync Orchestration
- Action: `RunYoutubeChannelSyncAction`
- Steps:
  1. Guard: channel exists, not deleting, no active video batch.
  2. Apply `markFetchingVideoList()`.
  3. Run Python channel fetch (`ChannelFetchRunner` -> `channel_fetch.py`).
  4. Read `channel.json` (if present) and update `channel_name`.
  5. Validate `videos.jsonl`.
  6. Build chunk plan (`VideoChunkPlanner`):
     - Include new videos.
     - Re-include existing videos only if DB says `is_upcoming=true`.
  7. If plan has jobs:
     - Dispatch bus batch via `DispatchYoutubeVideoChunkBatchAction`.
     - Set `active_video_batch_id`.
  8. If no jobs:
     - Optionally update `last_video_id`.
     - If feed file missing, apply `markBuildingFeed(true)` and dispatch `BuildYoutubeFeedJob`.
     - Else apply `markIdle()` via `FinalizeYoutubeChannelSyncWithoutBatchAction`.

### 3) Chunk Dispatch
- Action: `DispatchYoutubeVideoChunkBatchAction`
- Applies `markFetchingVideos()` and persists `last_video_id` when available.
- Dispatches batch of `FetchYoutubeVideoChunkJob` with `finally` callback to `SyncYoutubeChannelJob::handleVideoBatchFinally`.

### 4) Chunk Processing
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
- Timestamp resolution in `FetchYoutubeVideoChunkJob`:
  - `published_date`: prefers Unix `timestamp`, then `release_timestamp`, then date-only `upload_date` (`Ymd`, midnight UTC).
  - `updated_date`: prefers `modified_timestamp` and falls back to `published_date`.

### 5) Batch Finalization
- Action: `FinalizeYoutubeVideoChunkBatchAction` (called from batch `finally`)
- Behavior:
  - Clear `active_video_batch_id`.
  - If deleting: stop.
  - If any failed jobs: apply `markFailed()` with error text.
  - Else apply `markBuildingFeed()` and dispatch `BuildYoutubeFeedJob`.

### 6) Feed Build
- Job: `BuildYoutubeFeedJob`
- Middleware:
  - `App\Jobs\Middleware\PreventOverlappingYoutubeFeedBuild`
  - `WithoutOverlapping('youtube-feed-build:{id}')`
- Uses `YoutubeFeedXmlBuilder` to write Atom XML file to public disk.
- Success: apply `markIdle()`.
- Failure: apply `markFeedFailed()`.

### 7) Channel Delete
- Job: `DeleteYoutubeChannelJob`
- Middleware:
  - `App\Jobs\Middleware\PreventOverlappingYoutubeChannel`
  - `WithoutOverlapping('youtube-channel:{id}')`
- Steps:
  - Cancel active batch.
  - Delete feed xml.
  - Delete Python artifact directory for channel.
  - Delete DB channel row (videos cascade by FK).

## Scheduler
- `routes/console.php` schedules:
  - `youtube:maintenance --scheduled` every minute.
- Command: `YoutubeMaintenanceCommand`
  - Optional `--channel-id`.
  - Optional `--force` to bypass interval gate.
  - Cache gate interval controlled by `YOUTUBE_MAINTENANCE_INTERVAL_MINUTES`.
  - Uses `YoutubeChannel::eligibleForMaintenance()` when no specific channel is provided.
  - Still skips channels with active batch (checked via `YoutubeBatchManager`).

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

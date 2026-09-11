# Architecture

This document describes how YT-Channel RSS works at runtime and how its major application pieces fit together.

For installation, environment variables, Options settings, recovery commands, and other configuration, see [CONFIGURATION.md](CONFIGURATION.md).
For test commands and the current test inventory, see [TESTING.md](TESTING.md).

## Main Application Flows

### Channels

`GET /` is the main Channels page.

1. `YoutubeChannelController@index` renders `resources/views/Index.blade.php`.
2. `app/Livewire/YoutubeRssChannels.php` owns the shared channel search state.
3. `app/Livewire/YoutubeRssChannelsTable.php` owns:
   - channel creation
   - channel deletion
   - search
   - sorting
   - pagination
   - channel status display
   - RSS-link copy feedback
4. The table queries `youtube_channels` using the saved Channels pagination setting.
5. Search and sorting changes reset pagination to page 1.

The Channels table polls while open so queued/background status and progress changes are reflected without a manual page refresh.

The delete modal loads channel choices independently from the current table page so stored channels remain selectable even when they are not on the visible page.

### Add Channel and Synchronize

Adding a channel starts the background synchronization pipeline.

1. `YoutubeRssChannelsTable` validates the submitted YouTube channel URL.
2. `YoutubeChannelReference` parses and normalizes the channel reference.
3. Duplicate checks consider both the preferred `youtube_id` and stable `youtube_channel_id`.
4. A `youtube_channels` row is created.
5. `DispatchSyncYoutubeChannelJobAction` acquires the per-channel unique sync lock.
6. After lock acquisition, the channel is marked queued and `SyncYoutubeChannelJob` is dispatched.
7. `SyncYoutubeChannelJob` starts an ordered chain:
   - `FetchYoutubeChannelInfoAndVideoListJob`
   - `DispatchYoutubeVideoSyncPhaseJob`
8. The first phase invokes `python/yt-dlp/channel_fetch.py`, reads the persisted channel/list output, updates channel metadata, and resolves stable channel identity.
9. `VideoChunkPlanner` determines which videos need detail fetching:
   - newly discovered videos are fetched
   - stored videos marked `is_upcoming=true` are fetched again
   - stored normal videos are skipped
10. When detail work exists, `DispatchYoutubeVideoChunkBatchAction` creates a Laravel batch of `FetchYoutubeVideoChunkJob` jobs and stores its id/progress on the channel.
11. Each chunk job invokes `python/yt-dlp/video_fetch_chunk.py`, classifies the returned results, and upserts successful video metadata into `youtube_videos`.
12. `FinalizeYoutubeVideoChunkBatchAction` clears the active batch and dispatches `BuildYoutubeFeedJob` when the batch succeeds.
13. `BuildYoutubeFeedJob` rebuilds the Atom feed from persisted channel/video data.
14. A successful synchronization returns the channel to `idle`; failures are recorded on the channel.

The dispatch action marks the channel queued only after the unique lock is acquired. If dispatch itself fails, the previous channel state is restored and the acquired lock is released.

### Channel Identity Resolution

YT-Channel RSS treats these as two forms of the same YouTube channel identity:

- stable channel id: `UC...`
- preferred user-facing handle: `@handle`

The application stores:

- stable identity in `youtube_channel_id`
- preferred route/display identity in `youtube_id`

Synchronization resolves the stable channel id from fetched metadata and prefers the handle when one is available.

Handle discovery can use metadata such as:

1. `uploader_id`
2. `uploader_url`
3. `channel_url`

If separate local rows were created for a handle and its stable `UC...` id, synchronization resolves the duplicate identities into one canonical channel.

Handle promotion is delayed until a safe synchronization point so an in-progress fetch does not unexpectedly change its artifact/feed identity.

### Video Detail Fetching

Video-detail work is chunked so Laravel can queue/batch it independently from channel-list collection.

Python classifies each video result as:

- `ok`
- `restricted`
- `upcoming`
- `failed`
- `rate_limited`

Restricted/upcoming results can produce fallback metadata instead of failing the entire chunk.

Important process exit codes are:

- `29` - rate limited; Laravel redispatches the chunk after the configured cooldown with reduced Python concurrency
- `30` - partial hard failure; the chunk job fails

Successful rows are upserted into `youtube_videos`.

Published-date resolution prefers the most precise available yt-dlp timestamp and falls back to date-only metadata when needed. Persisted video timestamps are UTC.

### Feed

`GET /feeds/{youtubeChannel:youtube_id}.xml` serves the generated Atom feed.

Feed generation and HTTP serving are intentionally separate:

1. `BuildYoutubeFeedJob` loads persisted channel/video data.
2. `YoutubeFeedXmlBuilder` writes the new XML to a temporary file.
3. After generation succeeds, the temporary file replaces the previous feed.
4. `YoutubeChannelController@feed` serves the prebuilt file from the Laravel `public` disk.

The feed route does not call YouTube or rebuild XML during the request.

The response uses Atom XML content type and explicit no-cache headers. `ETag` and `Last-Modified` are removed so RSS readers parse the current generated file instead of receiving a conditional `304` response.

### Delete Channel

Deleting a channel is queued.

1. The UI cancels the active video batch when one is present.
2. The channel is marked `deleting`.
3. `DeleteYoutubeChannelJob` is dispatched.
4. The job:
   - cancels any remaining active video batch
   - deletes the generated feed
   - deletes the channel's Python artifacts
   - deletes the channel row
5. Related `youtube_videos` rows are removed through the database foreign-key cascade.

### Options

`GET /options` renders the Options page.

`ChannelPaginationSettings` owns the Channels pagination setting.

The current application option is:

- `channels_per_page`

The available values and defaults are documented in [CONFIGURATION.md](CONFIGURATION.md).

### Scheduled Maintenance and yt-dlp Updates

Scheduled channel maintenance uses the same synchronization dispatch path as manual/UI synchronization.

`YoutubeMaintenanceCommand` selects eligible channels and skips channels that are already busy, have an active video batch, or already hold the sync unique lock.

The scheduler also dispatches the configured periodic yt-dlp update work.

`YtDlpAutoUpdateManager` can trigger an update after qualifying yt-dlp failures while keeping rate-limit-like failures separate from normal failure counting.

How and when to run maintenance/update commands is documented in [CONFIGURATION.md](CONFIGURATION.md).

## Application Structure

### Stack

- Backend: Laravel 12 on PHP 8.3
- Frontend: Blade, Livewire 4, plain CSS, and plain JavaScript
- Database: MySQL 8
- Background work: Laravel database queues, job chains, job batches, and scheduler
- YouTube metadata: Python scripts using `yt-dlp`
- Feed output: generated Atom XML on Laravel storage

Laravel remains the application boundary. Python is invoked as a subprocess for YouTube metadata collection; it is not a second web service.

### Routes and Controllers

Routes are defined in `routes/web.php`.

Main controllers:

- `app/Http/Controllers/YoutubeChannelController.php`
  - Channels page
  - generated feed serving
- `app/Http/Controllers/OptionsController.php`
  - Options page

Main routes:

- `GET /`
- `GET /options`
- `GET /feeds/{youtubeChannel:youtube_id}.xml`

Scheduled application work is defined in `routes/console.php`.

### Livewire Components

Core application components include:

- `YoutubeRssChannels`
  - shared Channels search state
- `YoutubeRssChannelsTable`
  - add/delete channel actions
  - search
  - sorting
  - pagination
  - status/progress display
  - RSS-link copy feedback
- `ChannelPaginationSettings`
  - saved Channels page size

### Synchronization Actions and Jobs

Synchronization orchestration lives under:

```text
app/Actions/Youtube
```

Important actions include:

- `DispatchSyncYoutubeChannelJobAction`
- `RunYoutubeChannelSyncAction`
- `DispatchYoutubeVideoChunkBatchAction`
- `FinalizeYoutubeVideoChunkBatchAction`
- `HandleYoutubeChannelSyncFailureAction`

Queued work lives under:

```text
app/Jobs
```

Main synchronization jobs include:

- `SyncYoutubeChannelJob`
- `FetchYoutubeChannelInfoAndVideoListJob`
- `DispatchYoutubeVideoSyncPhaseJob`
- `FetchYoutubeVideoChunkJob`
- `BuildYoutubeFeedJob`
- `DeleteYoutubeChannelJob`
- `UpdateYtDlpJob`

Queue middleware under `app/Jobs/Middleware` provides synchronization overlap/rate-limit/batch-cancellation behavior.

### Support Classes

Important YouTube support boundaries include:

- `YoutubeChannelReference`
  - channel URL/reference parsing
  - handle/stable-id normalization
  - canonical YouTube URLs
- `ChannelFetchRunner`
  - Laravel-to-Python channel-fetch process boundary
- `VideoChunkPlanner`
  - selects video ids requiring detail refresh
- `YoutubeBatchManager`
  - active Laravel batch lookup and stale batch-state cleanup
- `YtDlpAutoUpdateManager`
  - failure counters and failure-triggered update decisions
- `PythonBinaryResolver`
  - project Python interpreter resolution
- `YoutubeFeedXmlBuilder`
  - generated Atom XML

Channel status values are defined by `YoutubeChannelStatus`, while `YoutubeChannel` owns status transitions and status helpers instead of allowing synchronization code to assign arbitrary state directly.

### Python

Python entry points are:

- `python/yt-dlp/channel_fetch.py`
- `python/yt-dlp/video_fetch_chunk.py`

Shared helpers live under:

```text
python/yt-dlp/lib
```

Important helpers include:

- `common.py`
- `channel_list.py`
- `video_detail.py`
- `weekly_logging.py`

`channel_list.py` owns channel/list extraction and video-id merging.

`video_detail.py` owns per-video yt-dlp fetching, error classification, and metadata sanitization.

## Data Model

### Channels

`youtube_channels` stores channel identity and synchronization state:

- `youtube_id`
  - unique
  - route/feed identifier
  - normally the preferred `@handle` when one is resolved
- `youtube_channel_id`
  - unique and nullable
  - stable `UC...` channel id
- `channel_name`
- `status`
- `last_sync_at`
- `last_video_id`
- `last_error`
- `active_video_batch_id`
- `video_fetch_progress_current`
- `video_fetch_progress_total`
- timestamps

`status` is cast through `YoutubeChannelStatus`.

Busy states cover queued/synchronization/fetch/build/delete work. RSS-link copy is allowed only while the channel is `idle`.

`active_video_batch_id` connects application state to Laravel batch state. Missing, finished, or cancelled batch references can be cleared by `YoutubeBatchManager`.

### Videos

`youtube_videos` stores persisted video metadata:

- `youtube_video_id`
  - unique
- `youtube_channel_id`
  - foreign key to local `youtube_channels.id`
  - cascade delete
- `video_title`
- `published_date`
- `updated_date`
- `is_upcoming`
- `scheduled_start_at`
- `media_title`
- `media_content_url`
- `media_thumbnail_url`
- `media_description`
- timestamps

Relevant indexes include:

- `(youtube_channel_id, published_date)`
- `is_upcoming`
- `scheduled_start_at`

`is_upcoming` keeps scheduled/live videos eligible for later detail refreshes while normal stored videos can be skipped.

### Options

`options` stores application settings as scalar values.

The current setting is:

- `channels_per_page`

### Queue and Batch State

Laravel queue infrastructure uses tables including:

- `jobs`
- `failed_jobs`
- `job_batches`

The channel row keeps its active video batch id and progress counters so UI/application state can be reconciled with Laravel batch state.

## Storage and External Process Boundary

Generated feeds are stored on Laravel's `public` disk:

```text
storage/app/public/feeds/{youtube_id}.xml
```

Per-channel Python artifacts are stored under:

```text
python/yt-dlp_jsons/{youtube_id}
```

Expected Python artifacts include:

```text
channel.json
videos.jsonl
video_id_chunks/*.jsonl
video_chunks/*.jsonl
```

`channel_fetch.py` produces the channel metadata and video-id list consumed by Laravel.

Channel-list collection combines relevant YouTube surfaces including:

- channel metadata/root extraction
- `/videos`
- `/streams`
- `/shorts`
- the uploads playlist when the stable channel id is known

Video ids are merged and deduplicated before Laravel plans detail work.

`video_fetch_chunk.py` writes compact JSONL payloads containing the fields Laravel consumes. Reducing the cross-process payload keeps chunk processing bounded and avoids retaining unnecessary yt-dlp metadata in PHP.

Normal Python execution resolves the project virtual environment first and can fall back to the system `python` executable. The yt-dlp update path requires the project virtual environment.

If `youtube_id` changes from a stable `UC...` id to an `@handle`, an older Python artifact directory is not automatically renamed.

## UI

The main application pages are:

- `resources/views/Index.blade.php`
- `resources/views/Options.blade.php`

Shared/page-specific assets include:

- `public/css/shared.css`
- `public/css/index.css`
- `public/css/options.css`
- `public/js/app-ui.js`
- `public/js/rss-copy-to-clipboard.js`
- `public/js/localize-datetime.js`

Blade-loaded local CSS/JS uses `filemtime()` query strings for cache busting.

The Channels page uses Livewire pagination/search/sorting and periodic polling for synchronization state.

Displayed channel timestamps are localized in the browser while persisted synchronization/video timestamps remain UTC.

## Logging

Laravel and the Python YouTube processes use matching weekly UTC log rotation.

- Laravel handler: `app/Logging/WeeklyRotatingFileHandler.php`
- Python handler: `python/yt-dlp/lib/weekly_logging.py`

The main log families are:

- Laravel
- Python process
- YouTube synchronization
- yt-dlp update

Configuration of retention belongs in [CONFIGURATION.md](CONFIGURATION.md).

## Maintenance Commands

Project-specific Artisan commands are implemented under `app/Console/Commands`.

Main commands are:

- `youtube:maintenance`
- `youtube:yt-dlp:update`

How and when to run them is documented in [CONFIGURATION.md](CONFIGURATION.md).

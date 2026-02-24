# Change Log

## Development

* 2026-02-24 -- 0.5.8 Simplified Livewire sync dispatch
  * Simplified Livewire sync dispatch usage by calling `DispatchSyncYoutubeChannelJobAction` directly in `save()`.
  * Updated tests accordingly

* 2026-02-24 -- 0.5.7 Channel Reference Simplification
  * Refactored `YoutubeChannelReference` internals and removed thin wrapper usage in call sites.
  * Simplified channel add/duplicate checks in Livewire + model scope + sync conflict query logic.
  * Aligned channel-id resolution in feed/sync flows with shared metadata normalization.
  * Updated docs, UI wording, and related feature/unit tests for URL-only input and metadata fallback coverage.

* 2026-02-18 -- 0.5.6 YouTube channel URL and Project name
  * Renamed project to "YT-Channel RSS"
  * Added support for UC* YouTube channel URLs and fix for unusual symbols
  * Updated docs
  * Project cleanup

* 2026-02-14 -- 0.5.5 Improved Job logic and Progress information
  * Improved job queue logic and 
  * Added fetching videos progress indication
  * Added more test cases
  * Updated docs
  
* 2026-02-14 -- 0.5.4 Python log timezone
  * Changed default python log timezone to UTC, same as Laravel

* 2026-02-13 -- 0.5.3 Table Search and Sort
  * Last updated column now shows time in User local time format
  * Added channels table search
  * Added channels table sort

* 2026-02-13 -- 0.5.2 Tests fix
  * Restored Unit "ExampleTest.php" to avoid phpunit.xml error

* 2026-02-13 -- 0.5.1 Fixes, Log refactoring and new Tests
  * Changed default table sort back to "id"
  * Added new "is_video_unavailable_error" possible text to python logic
  * Queue logic fix
  * Logging logic refactor
  * Added tests
  * Added YouTube Channel and YouTube Videos factories

* 2026-02-12 -- 0.5.0 PHPUnit tests
  * Added tests for channel fetch
  * ChannelFetchRunner, VideoChunkPlanner fixes

* 2026-02-12 -- 0.4.7 YoutubeBatchManager and Livewire
  * YoutubeBatchManager refactoring
  * Livewire logic cleanup
  
* 2026-02-12 -- 0.4.6 Modal window
  * Only one modal window at the same time can be opened now
  * Modal window can be closed by clicking anywhere

* 2026-02-12 -- 0.4.5 Fix date update
  * Fixed live video not updating date

* 2026-02-09 -- 0.4.4 Improved error handling
  * Added handling for "this video is unavailable" error
  * Added CSS for failed status

* 2026-02-09 -- 0.4.3 Live streams and Modal
  * Ongoing live streams marked as upcoming, so timestamp is updated later
  * Update modal to be in the center under card-header

* 2026-02-09 -- 0.4.2 yt-dlp auto-update
  * Implemented yt-dlp auto-update logic and job
  * Added favicon
  * Changed youtube:maintenance frequency
  
* 2026-02-09 -- 0.4.1 Chunk Fetch Stability Fixes
  * Fixed upcoming live-event handling in Python fetch so messages like "This live event will begin in X days" are treated as upcoming metadata, not hard failures
  * Added broader yt-dlp error classification fallback for non-`DownloadError` exceptions in `python/yt-dlp/lib/video_detail.py`
  * Sanitized yt-dlp info payloads before serialization to avoid `LazyList` JSON errors (`Object of type LazyList is not JSON serializable`)
  * Reduced per-video chunk payload size by writing only fields used by Laravel, preventing oversized JSONL rows and PHP memory exhaustion during `FetchYoutubeVideoChunkJob`
  * Full timestamps are saved (including hour/minute/second) and added to final XML

* 2026-02-09 -- 0.4.0 Refactoring and Documentation
  * Refactored sync orchestration: `SyncYoutubeChannelJob` now delegates to focused actions under `app/Actions/Youtube/`
  * Centralized channel state transitions in domain methods on `YoutubeChannel`
  * Added local scopes on `YoutubeChannel` for maintenance targeting
  * Updated Livewire, jobs, actions, and maintenance command to use centralized domain transitions/scopes/status helpers
  * Added project documentation set in `docs/`

* 2026-02-08 -- 0.3.0 Background sync
  * Added background maintenance sync logic
  * Added periodic maintenance command for background sync
  * Changed XML share logic to return request without cache
  * Added shared Python modules and refactored channel/video scripts to reuse common logic
  * Refactored/Simplified SyncYoutubeChannelJob.php Job

* 2026-02-08 -- 0.2.0 XML Feed and Fixes
  * Added XML Feed Builder
  * Updated Index.blade.php
  * Added "age-restricted" video fetch handling
  * Fixed channel name fetch if there is no videos on the channel
  * Fixed video fetch behavior in case of live event

* 2026-02-06 -- 0.1.0 Videos Fetch
  * Added video sync logic
  * Changed Index table poll frequency

* 2026-02-02 -- 0.0.5 yt-dlp Channel Fetch and Index page cleanup
  * Added yt-dlp Python wrapper for channel metadata and video list
  * Changed Livewire auto update from 5 to 1 second in Index.blade.php
  * Removed table animation in Index.blade.php
  * Updated CSS for Index.blade.php

* 2026-02-02 -- 0.0.4 Livewire table and jobs template
  * Added Livewire table with add/delete modal windows
  * Added sync/delete job templates
  * Updated README.md

* 2026-02-02 -- 0.0.3 Models and Channel Controller
  * Added YoutubeChannel and YoutubeVideo models
  * Added YoutubeChannelController for index

* 2026-31-01 -- 0.0.2 Index page and migrations
  * Added Index template
  * Added youtube channel migration
  * Added youtube video migration

* 2026-30-01 -- 0.0.1 Project start
  * Project start

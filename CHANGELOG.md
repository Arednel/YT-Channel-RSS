# Change Log

## Development

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

# Testing

## Scope
Current automated coverage is split between Laravel PHPUnit tests and Python pytest tests.

Laravel coverage includes:
- Livewire channel list behavior: add-channel validation, search/sort, copy RSS, delete modal, and configurable pagination
- Channels page wrapper behavior: visible Channels wording and normalized shared search state
- Options page behavior: channel pagination setting defaults, fixed/custom/unlimited persistence, and deferred save behavior
- YouTube sync orchestration: dispatch locking, queued chains, chunk batches, maintenance recovery, feed building, and delete jobs
- Feed XML contract and selected real-network integration paths gated by environment variables
- Smaller unit tests for channel references, status labels, sort/progress helpers, and yt-dlp auto-update failure handling

Python coverage includes:
- channel metadata normalization
- tab URL construction
- tab entry merge and dedupe behavior
- optional real-channel fetch integration when network tests are enabled

## Test Environment Setup

Create `.env.testing` from `.env.testing.example` and set test DB credentials (`DB_*`).

Keep these integration-test toggles in `phpunit.xml`:
- `YOUTUBE_TESTS_WITH_NETWORK` (`true` by default; set `false` to disable integration tests that call YouTube)
- `YOUTUBE_TEST_REAL_FEED_URL` (YouTube feed URL for feed-XML integration tests, e.g. `https://www.youtube.com/feeds/videos.xml?channel_id=UCxxxxxxxxxxxxxxxxxxxxxx`)
- `YOUTUBE_TEST_REAL_CHANNEL` (full YouTube channel URL, e.g. `https://youtube.com/@channel` or `https://youtube.com/channel/UC...`; `www.youtube.com` and `m.youtube.com` also supported)

The suite mixes:
- network-gated integration tests against real YouTube (`YOUTUBE_TESTS_WITH_NETWORK`)
- deterministic tests that fake external process boundaries (`Process::fake`) while still exercising real Laravel actions/jobs/DB state.

## Running Tests
Run all Laravel tests:

```bash
php artisan test
```

Run all Laravel tests inside Docker:

```bash
docker compose --env-file docker/.env.docker --profile test run --rm --build tests
```

Run a filtered Laravel subset:

```bash
php artisan test --filter=RunYoutubeChannelSyncActionTest
php artisan test --filter=YoutubeRssChannelsTableInputTest
php artisan test --filter=YoutubeRssChannelsTablePaginationTest
php artisan test --filter=YoutubeRssChannelsPageTest
php artisan test --filter=ChannelPaginationSettingsTest
php artisan test --filter=OptionsControllerTest
```

## Python Tests (pytest)
Install Python test dependencies:

```bash
python -m venv python/venv

# Windows
python\venv\Scripts\activate
# Linux/macOS
source python/venv/bin/activate

pip install -r python/requirements-dev.txt
```

Run all Python tests:

```bash
pytest
```

Run Python real channel integration test only:

```bash
pytest -m integration
```

Note: this test is skipped unless `YOUTUBE_TESTS_WITH_NETWORK=true`.

Run only channel-list tests:

```bash
pytest python/tests/test_channel_list.py
```

## Current Test Coverage
- PHP:
  - Feature: `RunYoutubeChannelSyncActionTest`:
    - runs `channel_fetch.py` end-to-end
    - verifies `channel.json` and `videos.jsonl` are produced
    - verifies batch/status behavior for non-empty vs empty fetched video set
  - Feature: `RunYoutubeChannelSyncActionTest` deterministic queue pipeline path (no network):
    - uses fixture `channel.json` / `videos.jsonl`
    - dispatches and executes chunk jobs through the database queue
    - verifies video upsert, batch finalization, and feed file creation
    - verifies resolved `youtube_channel_id` is used in feed identity when available
  - Feature: `RunYoutubeChannelSyncActionTest` identifier canonicalization path:
    - verifies metadata can promote `youtube_id` from `UC...` to `@handle` in a safe step
    - verifies duplicate handle-vs-UC records are collapsed to one canonical channel row
  - Feature: `RunYoutubeChannelSyncActionTest` idempotent path:
    - re-runs sync on same fixture dataset
    - verifies no duplicate `youtube_videos` rows
    - verifies stable `last_video_id`
  - Feature: `YoutubeRssChannelsTableInputTest`:
    - verifies component save flow accepts only YouTube channel URLs (`youtube.com`, `www.youtube.com`, `m.youtube.com`) in `/@handle...` and `/channel/UC...` forms and extracts normalized identifiers
    - verifies duplicate prevention for UC/handle-equivalent channels
  - Feature: `YoutubeRssChannelsTablePaginationTest`:
    - verifies default, fixed, custom, and unlimited channel table page sizes
    - verifies Livewire paginator navigation methods
    - verifies search/sort changes reset to page 1
    - verifies delete modal choices are not limited to the current page
  - Feature: `YoutubeRssChannelsPageTest`:
    - verifies the root page uses Channels wording and the shared search value is normalized
  - Feature: `ChannelPaginationSettingsTest`:
    - verifies Options pagination defaults, fixed/custom/unlimited persistence, deferred save behavior, and invalid custom-value validation
  - Feature: `OptionsControllerTest`:
    - verifies `/options` renders the real pagination setting and route-generated navigation links without template placeholder content
  - Feature: `SyncYoutubeChannelQueuedWorkflowTest`:
    - real queued orchestration from `DispatchSyncYoutubeChannelJobAction` through chain + chunk batch
    - restart simulation by forcing `jobs.reserved_at` and retry-after recovery
    - stale `active_video_batch_id` recovery through `youtube:maintenance`
  - Feature: `FetchYoutubeVideoChunkJobTest`:
    - upcoming/live to regular transition behavior (`is_upcoming`, `scheduled_start_at`)
    - prefers `release_date` over `upload_date` when Unix timestamps are missing
    - restricted-like rows without date fields are skipped while valid rows continue importing
    - rate-limit exit code (`29`) re-dispatches chunk with lower thread count
    - `failed()` hook reports `video_update` failure
  - Feature: `YoutubeMaintenanceCommandTest`:
    - dispatches only eligible channels
    - skips busy/deleting and active-batch channels
    - covers `--channel-id` busy skip branch
    - covers stale batch-id self-healing branch
  - Feature: `DispatchSyncYoutubeChannelJobActionTest`:
    - does not mark channel queued when sync unique lock is already held
    - restores previous channel state and releases lock if dispatch throws
  - Feature: `SyncYoutubeChannelJobTest`:
    - verifies centralized chain-failure callback state handling
    - verifies `failed()` callback state handling
  - Feature: `DispatchYoutubeVideoChunkBatchActionTest`:
    - verifies transaction rollback when batch dispatch throws
  - Feature: `FinalizeYoutubeVideoChunkBatchActionTest`:
    - clears active batch pointer and dispatches feed build on successful batch
    - marks channel failed and skips feed build when batch has failures
  - Feature: `YoutubeChannelVideoFetchProgressTest`:
    - increments and clamps `video_fetch_progress_current`
  - Feature: `BuildYoutubeFeedJobTest`:
    - verifies generated XML feed contract (channel identity, namespaces, entries)
    - compares generated XML against real YouTube feed XML for channel + up to first 15 entries (with yt-dlp check to ignore missing entries that are likely offline live streams)
    - verifies `failed()` hook marks `feed failed`
  - Feature: `DeleteYoutubeChannelJobTest`:
    - validates active batch cancellation and channel/video record deletion
    - verifies `failed()` hook marks `failed`
  - Unit: `SyncYoutubeChannelJobChainTest`:
    - dispatches ordered chain jobs (`FetchYoutubeChannelInfoAndVideoListJob` then `DispatchYoutubeVideoSyncPhaseJob`)
  - Unit: `YoutubeChannelStatusLabelTest`:
    - renders `fetching videos (x out of x)` only for `fetching videos` status
  - Unit: `YoutubeChannelReferenceTest`:
    - verifies input normalization for handle/UC/url variants and canonical YouTube URL generation
    - verifies metadata handle fallback from `uploader_url` and `channel_url` when `uploader_id` is missing
  - Unit: `YtDlpAutoUpdateManagerTest`:
    - validates failure-threshold trigger for `UpdateYtDlpJob`
    - ignores rate-limit-like errors for threshold counting
- Python:
  - `lib/channel_list.py`:
    - channel metadata normalization
    - tab URL construction
    - tab entry merge + dedupe behavior
    - latest-video selection logic
  - `channel_fetch.py` integration (optional, env-gated):
    - executes real fetch against `YOUTUBE_TEST_REAL_CHANNEL`
    - verifies output files and payload shape

# Testing

## Test Environment Configuration

Create `.env.testing` from `.env.testing.example` and set test DB credentials (`DB_*`).

Keep these integration-test toggles in `phpunit.xml`:
- `YOUTUBE_TESTS_WITH_NETWORK` (`true` by default; set `false` to disable integration tests that call YouTube)
- `YOUTUBE_TEST_REAL_FEED_URL` (YouTube feed URL for feed-XML integration tests, e.g. `https://www.youtube.com/feeds/videos.xml?channel_id=UCxxxxxxxxxxxxxxxxxxxxxx`)
- `YOUTUBE_TEST_REAL_CHANNEL` (full YouTube channel URL, e.g. `https://youtube.com/@channel` or `https://youtube.com/channel/UC...`; `www.youtube.com` and `m.youtube.com` also supported)

The suite mixes:
- network-gated integration tests against real YouTube (`YOUTUBE_TESTS_WITH_NETWORK`)
- deterministic tests that fake external process boundaries (`Process::fake`) while still exercising real Laravel actions/jobs/DB state.

## PHP Tests (Laravel / PHPUnit)
Run all Laravel tests:

```bash
php artisan test
```

Run channel sync feature tests:

```bash
php artisan test --filter=RunYoutubeChannelSyncActionTest
```

Run queued orchestration / restart simulation tests:

```bash
php artisan test --filter=SyncYoutubeChannelQueuedWorkflowTest
```

Run only the real-network channel-sync test method:

```bash
php artisan test --filter=test_it_runs_real_channel_sync_workflow_with_configured_channel
```

Note: the real-network test is skipped unless `YOUTUBE_TESTS_WITH_NETWORK=true`.

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

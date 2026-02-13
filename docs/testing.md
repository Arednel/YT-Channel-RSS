# Testing

## Test Environment Configuration

Create `.env.testing` from `.env.testing.example` and set test DB credentials (`DB_*`).

Keep these integration-test toggles in `phpunit.xml`:
- `YOUTUBE_TESTS_WITH_NETWORK` (`false` by default; set `true` to enable integration tests that call YouTube)
- `YOUTUBE_TEST_REAL_CHANNEL` (`@handle`, `channel/UC...`, or full YouTube URL)

The feature test intentionally uses a real channel fetch workflow (no mocked `ChannelFetchRunner` payloads).

## PHP Tests (Laravel / PHPUnit)
Run all Laravel tests:

```bash
php artisan test
```

Run only channel sync tests:

```bash
php artisan test --filter=RunYoutubeChannelSyncActionTest
```

Note: this test is skipped unless `YOUTUBE_TESTS_WITH_NETWORK=true`.

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

## Current Test Coverage (Initial)
- PHP:
  - `RunYoutubeChannelSyncAction` integration path using a real configured YouTube channel:
    - runs `channel_fetch.py` end-to-end
    - verifies `channel.json` and `videos.jsonl` are produced
    - verifies batch/status behavior for non-empty vs empty fetched video set
  - `RunYoutubeChannelSyncAction` deterministic queue pipeline path (no network):
    - uses fixture `channel.json` / `videos.jsonl`
    - dispatches and executes chunk jobs through the database queue
    - verifies video upsert, batch finalization, and feed file creation
  - `RunYoutubeChannelSyncAction` idempotent path:
    - re-runs sync on same fixture dataset
    - verifies no duplicate `youtube_videos` rows
    - verifies stable `last_video_id`
  - `FetchYoutubeVideoChunkJob`:
    - upcoming/live to regular transition behavior (`is_upcoming`, `scheduled_start_at`)
    - restricted-like rows without date fields are skipped while valid rows continue importing
    - rate-limit exit code (`29`) re-dispatches chunk with lower thread count
  - `YoutubeMaintenanceCommand`:
    - dispatches only eligible channels
    - skips busy/deleting and active-batch channels
  - `FinalizeYoutubeVideoChunkBatchAction`:
    - clears active batch pointer and dispatches feed build on successful batch
  - `BuildYoutubeFeedJob` / `YoutubeFeedXmlBuilder`:
    - verifies generated XML feed contract (channel identity, namespaces, entries)
  - `DeleteYoutubeChannelJob`:
    - validates active batch cancellation and channel/video record deletion
  - `YtDlpAutoUpdateManager`:
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

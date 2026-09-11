# Testing

This document describes how to run the project's existing tests and what each current test file covers.

For runtime architecture and data flow, see [ARCHITECTURE.md](ARCHITECTURE.md).
For installation, environment variables, Options settings, recovery commands, and other configuration, see [CONFIGURATION.md](CONFIGURATION.md).

## Run Tests

### Laravel Unit + Feature

From the project root:

```bash
php artisan test
```

This is the normal Laravel test command for the project's Unit and Feature coverage.

### Weekly Logging and Python Process Focused Run

Existing focused command:

```bash
php artisan test tests/Unit/Logging tests/Unit/Youtube/ChannelFetchRunnerTest.php tests/Feature/Youtube/FetchYoutubeVideoChunkJobTest.php
```

### Python Tests

Run the project-owned Python pytest suite:

```bash
pytest
```

Run only integration-marked Python tests:

```bash
pytest -m integration
```

### Run One Laravel Test/Class/Method

Use PHPUnit/Laravel filtering:

```bash
php artisan test --filter=<TestClassOrMethod>
```

Examples:

```bash
php artisan test --filter=RunYoutubeChannelSyncActionTest
php artisan test --filter=YoutubeRssChannelsTableInputTest
php artisan test --filter=YoutubeRssChannelsTablePaginationTest
php artisan test --filter=ChannelPaginationSettingsTest
php artisan test --filter=YtDlpAutoUpdateManagerTest
```

### Docker Unit + Feature

```bash
docker compose --env-file docker/.env.docker --profile test run --rm --build tests
```

The Docker `tests` service is one-off and does not start during normal application startup.

## Test Environment Setup

### Local

1. Copy:

```text
.env.testing.example
```

to:

```text
.env.testing
```

2. Configure the dedicated test database:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
```

3. Keep test settings separate from the normal application database.

`phpunit.xml` overrides several effective test settings, including:

```text
APP_ENV=testing
CACHE_STORE=array
MAIL_MAILER=array
QUEUE_CONNECTION=database
SESSION_DRIVER=array
BCRYPT_ROUNDS=4
YOUTUBE_VIDEO_CHUNK_SIZE=10
```

Feature tests use `RefreshDatabase`, so use a dedicated test database.

External Python process tests use Laravel `Process::fake()` and `Process::preventStrayProcesses()` where deterministic process assertions are required.

Livewire component tests use `Livewire::test()` without a browser.

### Network Tests

Real YouTube integration tests use:

```text
YOUTUBE_TESTS_WITH_NETWORK
YOUTUBE_TEST_REAL_CHANNEL
YOUTUBE_TEST_REAL_FEED_URL
```

Set:

```text
YOUTUBE_TESTS_WITH_NETWORK=false
```

to skip network-backed YouTube integration tests.

Deterministic tests continue to use faked process boundaries.

### Docker

Docker tests use:

- `docker/.env.testing.docker`
- MySQL host `database_test`
- separate `dbdata_test` volume
- the Compose `test` profile

The test database is separate from the normal Docker application database.

### Python

Create the project virtual environment if needed:

```bash
python -m venv python/venv
```

Activate it.

Windows:

```bat
python\venv\Scripts\activate
```

Linux/macOS:

```bash
source python/venv/bin/activate
```

Install Python test dependencies:

```bash
pip install -r python/requirements-dev.txt
```

`pytest.ini` points pytest at `python/tests`.

## Current Automated Test Inventory

### Feature Tests

#### `tests/Feature/ChannelPaginationSettingsTest.php`

Covers the Channels pagination Options setting:

- default value
- fixed choices
- custom positive values
- `unlimited`
- persistence
- validation

#### `tests/Feature/OptionsControllerTest.php`

Covers `/options` rendering, the real pagination settings component, and route-generated navigation.

#### `tests/Feature/YoutubeRssChannelsPageTest.php`

Covers the main Channels page and shared channel-search state.

### YouTube Feature Tests

#### `tests/Feature/Youtube/BuildYoutubeFeedJobTest.php`

Covers generated Atom feed behavior, feed identity/namespaces/entries, feed-build failure state, and optional comparison with the configured real YouTube feed.

#### `tests/Feature/Youtube/DeleteYoutubeChannelJobTest.php`

Covers queued channel deletion, active-batch cancellation, related video deletion, and delete failure handling.

#### `tests/Feature/Youtube/DispatchSyncYoutubeChannelJobActionTest.php`

Covers synchronization unique-lock handling, queued-state timing, dispatch failure rollback, and lock release.

#### `tests/Feature/Youtube/DispatchYoutubeVideoChunkBatchActionTest.php`

Covers video-batch creation and transaction rollback when batch dispatch fails.

#### `tests/Feature/Youtube/FetchYoutubeVideoChunkJobTest.php`

Covers video-detail chunk processing, upcoming/live transitions, date fallback behavior, restricted rows, rate-limit retries, reduced retry concurrency, failure handling, and Python process environment propagation.

#### `tests/Feature/Youtube/FinalizeYoutubeVideoChunkBatchActionTest.php`

Covers active-batch cleanup, successful feed-build dispatch, and failed-batch channel state.

#### `tests/Feature/Youtube/RunYoutubeChannelSyncActionTest.php`

Covers the main synchronization action, including:

- channel/list fetch output
- queued video synchronization
- video upserts
- feed generation
- stable channel identity
- `UC...` to `@handle` promotion
- handle/UC duplicate collapse
- repeated synchronization/idempotency

Network-backed channel fetch coverage runs only when network tests are enabled.

#### `tests/Feature/Youtube/SyncYoutubeChannelJobTest.php`

Covers centralized synchronization failure behavior for chain and job failure callbacks.

#### `tests/Feature/Youtube/SyncYoutubeChannelQueuedWorkflowTest.php`

Covers the queued synchronization workflow, ordered chain execution, video batch execution, restart/retry recovery, and stale active-batch cleanup.

#### `tests/Feature/Youtube/YoutubeChannelFeedRouteTest.php`

Covers generated feed routing, including handles containing dots and Atom content type.

#### `tests/Feature/Youtube/YoutubeChannelVideoFetchProgressTest.php`

Covers video-fetch progress increments and clamping to the configured total.

#### `tests/Feature/Youtube/YoutubeMaintenanceCommandTest.php`

Covers maintenance dispatch and skip behavior for busy/deleting channels, active batches, targeted channel ids, and stale batch-state cleanup.

#### `tests/Feature/Youtube/YoutubeRssChannelsTableInputTest.php`

Covers accepted YouTube channel URL forms, identifier normalization, and duplicate prevention across handle/UC-equivalent channels.

#### `tests/Feature/Youtube/YoutubeRssChannelsTablePaginationTest.php`

Covers fixed/custom/unlimited page sizes, Livewire navigation, page resets after search/sort changes, and delete-modal channel choices independent from the current page.

### Unit Tests

#### Logging

`tests/Unit/Logging/LoggingConfigurationTest.php`

- Covers configured weekly Laravel log channels, shared retention, handler wiring, locked writes, and weekly file creation.

`tests/Unit/Logging/WeeklyRotatingFileHandlerTest.php`

- Covers UTC Monday filenames/boundaries, append/week switching, retention cleanup, invalid-retention fallback, concurrent removal, and non-blocking cleanup failures.

#### YouTube

`tests/Unit/Youtube/ChannelFetchRunnerTest.php`

- Covers Laravel Process configuration for `channel_fetch.py`, including log-retention environment propagation.

`tests/Unit/Youtube/SyncYoutubeChannelJobChainTest.php`

- Covers the ordered synchronization chain:
  1. `FetchYoutubeChannelInfoAndVideoListJob`
  2. `DispatchYoutubeVideoSyncPhaseJob`

`tests/Unit/Youtube/YoutubeChannelReferenceTest.php`

- Covers channel reference parsing/normalization, supported URL forms, canonical YouTube URLs, and metadata handle fallbacks.

`tests/Unit/Youtube/YoutubeChannelStatusLabelTest.php`

- Covers channel status labels, including video-fetch progress text.

`tests/Unit/Youtube/YtDlpAutoUpdateManagerTest.php`

- Covers failure-threshold update dispatch and exclusion/reset behavior for rate-limit-like failures.

### Python

#### `python/tests/test_channel_fetch_integration.py`

Optional real-channel integration coverage for `channel_fetch.py`, including expected output files and payload shape.

The test is network-gated.

#### `python/tests/test_channel_list.py`

Covers channel metadata normalization, tab URL construction, tab-entry merging, video-id deduplication, and latest-video selection.

#### `python/tests/test_weekly_logging.py`

Covers Python weekly logging parity with Laravel:

- UTC Monday week calculation
- weekly filenames
- append/week switching
- retention cleanup
- concurrent removal
- invalid-retention fallback
- non-blocking cleanup failures

## Existing Test Implementation Boundaries

The current suite intentionally uses framework fakes around external/destructive boundaries:

- Feature tests use `RefreshDatabase`
- storage-sensitive tests use `Storage::fake()`
- deterministic Python process tests use `Process::fake()` and `Process::preventStrayProcesses()`
- queue/bus fakes are used where dispatch behavior is the subject of the test
- the queued workflow coverage executes database-backed queue state
- Livewire component tests use `Livewire::test()`

Real YouTube behavior that cannot be reproduced fully with fixtures is covered by explicitly network-gated integration tests.

The repository does not currently use a browser automation harness. JavaScript execution, clipboard behavior, native dialog interaction, browser polling behavior, and visual/responsive layout remain browser checks where the server-rendered contract is not enough.

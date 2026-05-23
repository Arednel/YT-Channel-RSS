<h2 align="center">YT-Channel RSS</h2>

Laravel application that creates per-channel RSS feeds from YouTube using [yt-dlp](https://github.com/yt-dlp/yt-dlp).

Recommended use case: creating RSS feed for [FreshRSS](https://github.com/FreshRSS/FreshRSS) with [Youlag](https://github.com/civilblur/youlag) extension.

## Quick Start (requires [Git](https://git-scm.com) and [Docker Compose](https://docs.docker.com/compose))

### 1) Run those commands

```bash
git clone https://github.com/Arednel/YT-Channel-RSS.git

cd YT-Channel-RSS

docker compose --env-file docker/.env.docker up --build -d
```

### 2) After startup
- YT-Channel RSS available at: `http://localhost:8080`
- phpMyAdmin available at: `http://localhost:8888`

## Manual installation process

### Requirements
- PHP 8.3
- Composer
- MySQL 8
- [Python 3.10.11](https://www.python.org/downloads/release/python-31011) (tested with this version) and [pip](https://pypi.org/project/pip)

### 1) Install PHP dependencies and migrate database
```bash
composer install
php artisan key:generate
php artisan migrate
```

### 2) Create and activate the venv:
```bash
python -m venv python/venv
```

Activate it with:
- Windows: `python\venv\Scripts\activate`
- Linux/macOS: `source python/venv/bin/activate`

### 3) Install Python packages:

```bash
pip install -r python/requirements.txt
```

### 4) Run workers
```bash
php artisan queue:work
php artisan schedule:work
```

## Manual maintenance:
```bash
php artisan youtube:maintenance
php artisan youtube:maintenance --channel-id=1
php artisan youtube:yt-dlp:update
php artisan youtube:yt-dlp:update --force
```

## yt-dlp Auto Update
- Weekly update job is scheduled by Laravel scheduler.
- Additional update job is auto-dispatched when `channel_update` or `video_update` failures exceed threshold without rate-limit errors.
- Update job always targets `python/venv` yt-dlp and fails if venv Python is missing.
- Configure behavior with `YOUTUBE_YT_DLP_*` variables in `.env`.

## Running tests

### Configure test environment
Create `.env.testing` from `.env.testing.example`, set test DB credentials

Then set integration-test toggles in `phpunit.xml`:
- `YOUTUBE_TESTS_WITH_NETWORK` (`true` by default, set `false` to disable real network tests)
- `YOUTUBE_TEST_REAL_FEED_URL` (YouTube feed URL for feed-XML integration tests, e.g. `https://www.youtube.com/feeds/videos.xml?channel_id=UCxxxxxxxxxxxxxxxxxxxxxx`)
- `YOUTUBE_TEST_REAL_CHANNEL` (full YouTube channel URL, e.g. `https://youtube.com/@channel` or `https://youtube.com/channel/UC...`; `www.youtube.com` and `m.youtube.com` also supported)

### Run Laravel / PHPUnit tests
```bash
php artisan test
```

Run only the real channel-sync integration test:
```bash
php artisan test --filter=RunYoutubeChannelSyncActionTest
```

To run the test suite inside Docker with a dedicated test database:

```bash
docker compose --env-file docker/.env.docker --profile test run --rm --build tests
```

### Run Python pytest suite

Activate venv with:
- Windows: `python\venv\Scripts\activate`
- Linux/macOS: `source python/venv/bin/activate`

Install Python test dependencies:
```bash
pip install -r python/requirements-dev.txt
```

Run tests:
```bash
pytest
```

Run only real-network Python integration tests:
```bash
pytest -m integration
```

## Documentation
- `docs/CONFIGURATION.md`
- `docs/ARCHITECTURE.md`
- `docs/TESTING.md`
- `docs/OPERATIONS.md`

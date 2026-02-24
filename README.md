<h2 align="center">YT-Channel RSS</h2>

Laravel application that creates per-channel RSS feeds from YouTube using [yt-dlp](https://github.com/yt-dlp/yt-dlp).

Recommended use case: creating RSS feed for [FreshRSS](https://github.com/FreshRSS/FreshRSS) with [Youlag](https://github.com/civilblur/youlag) extension.

## Quick Start

### 1) Install PHP dependencies and migrate database
```bash
composer install
php artisan key:generate
php artisan migrate
```

### 2) Install Python dependencies (venv)
```bash
python -m venv python/venv

# Windows
python\venv\Scripts\activate
# Linux/macOS
source python/venv/bin/activate

pip install -r python/requirements.txt
```

### 2.1) Install test dependencies (optional)
```bash
# Windows
python\venv\Scripts\activate 
# Linux/macOS
source python/venv/bin/activate 

pip install -r python/requirements-dev.txt
```

### 3) Run workers
```bash
php artisan queue:work
php artisan schedule:work
```

Manual maintenance:
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

## Tests

### Configure test environment
Create `.env.testing` from template and set DB credentials:

Then set integration-test toggles in `phpunit.xml`:
- `YOUTUBE_TESTS_WITH_NETWORK` (`true` by default, set `false` to disable real network tests)
- `YOUTUBE_TEST_REAL_CHANNEL` (full YouTube channel URL, e.g. `https://youtube.com/@channel` or `https://youtube.com/channel/UC...`; `www.youtube.com` and `m.youtube.com` also supported)

### Run Laravel / PHPUnit tests
```bash
php artisan test
```

Run only the real channel-sync integration test:
```bash
php artisan test --filter=RunYoutubeChannelSyncActionTest
```

### Run Python pytest suite
```bash
# Windows
python\venv\Scripts\activate 
# Linux/macOS
source python/venv/bin/activate 

pytest
```

Run only real-network Python integration tests:
```bash
pytest -m integration
```

## Documentation
- `docs/architecture.md`
- `docs/configuration.md`
- `docs/operations.md`
- `docs/testing.md`

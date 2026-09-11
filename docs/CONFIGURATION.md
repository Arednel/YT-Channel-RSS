# Configuration

This document explains how to run and configure YT-Channel RSS.

For runtime architecture and data flow, see [ARCHITECTURE.md](ARCHITECTURE.md).
For test setup, commands, and the current tests, see [TESTING.md](TESTING.md).

## Run the Application

### Docker

Docker is the recommended self-hosted setup.

From the project root run:

```bash
docker compose --env-file docker/.env.docker up --build -d
```

This starts:

- Laravel/PHP-FPM from `docker/app.dockerfile`
- Laravel queue worker
- Laravel scheduler worker
- Nginx from `docker/web.dockerfile`
- MySQL 8 database

The app container runs migrations during startup.

After that YT-Channel RSS is available at:

```text
http://localhost:8080
```

Docker serves `/storage/*` directly through Nginx, so `php artisan storage:link` is not required inside the Docker setup.

The test database/services are behind the Compose `test` profile and do not start with the normal application command.

phpMyAdmin is disabled/commented out by default. If enabled in `compose.yaml`, its configured access point is:

```text
http://localhost:8888
```

### Run Artisan Commands in Docker

To run an Artisan command inside the running Docker app container run:

```bash
docker compose --env-file docker/.env.docker exec app php artisan <command>
```

Examples:

```bash
docker compose --env-file docker/.env.docker exec app php artisan youtube:maintenance
docker compose --env-file docker/.env.docker exec app php artisan youtube:yt-dlp:update
```

### Local / Manual Setup

Local WAMP/manual runtime is the primary development environment.

Requirements:

- PHP 8.3
- Composer
- MySQL 8
- Python 3.14.6 (currently tested version) and pip

Setup:

1. Copy `.env.example` to `.env`.
2. Configure the database and application URL.
3. Install PHP dependencies:

```bash
composer install
```

4. Generate the application key:

```bash
php artisan key:generate
```

5. Run migrations:

```bash
php artisan migrate
```

6. Create the python virtual environment:

```bash
python -m venv python/venv
```

7. Activate the virtual environment.

Windows:

```bat
python\venv\Scripts\activate
```

Linux/macOS:

```bash
source python/venv/bin/activate
```

8. Install python dependencies:

```bash
pip install -r python/requirements.txt
```

9. Keep a Laravel queue worker running:

```bash
php artisan queue:work
```

10. Run the scheduler for automatic maintenance and yt-dlp updates:

```bash
php artisan schedule:work
```

## Recovery and Maintenance

### Channel Maintenance

Synchronize eligible channels:

```bash
php artisan youtube:maintenance
```

Synchronize one channel by local `youtube_channels.id`:

```bash
php artisan youtube:maintenance --channel-id=123
```

Busy channels, active video batches, and channels with an existing sync unique lock are skipped.

The scheduler runs the maintenance path every 30 minutes.

### yt-dlp Update

Run an update using the configured automatic-update and interval settings:

```bash
php artisan youtube:yt-dlp:update
```

Force an update regardless of the automatic-update and interval settings:

```bash
php artisan youtube:yt-dlp:update --force
```

Queue the update:

```bash
php artisan youtube:yt-dlp:update --queued
```

## Environment Files

Runtime files:

| File | Purpose |
| --- | --- |
| `.env` | Local/manual application runtime |
| `.env.example` | Local/manual environment template |
| `.env.testing` | Local test runtime |
| `.env.testing.example` | Local test environment template |
| `docker/.env.docker` | Docker application runtime |
| `docker/.env.testing.docker` | Docker test runtime |

Test-specific configuration is documented in [TESTING.md](TESTING.md).

### Application Environment

Important values include:

```dotenv
APP_NAME=
APP_ENV=
APP_KEY=
APP_DEBUG=
APP_URL=
```

Generate `APP_KEY` for a local/manual setup with:

```bash
php artisan key:generate
```

### Database

Relevant variables:

```dotenv
DB_CONNECTION=mysql
DB_HOST=
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
```

Docker uses `database` as the application database host.

### Queue and Scheduler

Channel synchronization uses Laravel's database queue and job batches.

Normal settings:

```dotenv
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
```

Keep the queue worker running for background work:

```bash
php artisan queue:work
```

Run the scheduler for automatic channel maintenance and yt-dlp updates:

```bash
php artisan schedule:work
```

### YouTube Settings

YouTube-specific settings are mapped in `config/youtube.php`.

Values from `.env.example`:

| Variable | Default | Purpose |
| --- | ---: | --- |
| `YOUTUBE_VIDEO_CHUNK_SIZE` | `50` | Video IDs per detail-fetch chunk |
| `YOUTUBE_VIDEO_FETCH_THREADS` | `8` | Python detail-fetch worker threads |
| `YOUTUBE_VIDEO_RATE_LIMIT_COOLDOWN` | `300` | Rate-limit redispatch delay in seconds |
| `YOUTUBE_VIDEO_RATE_LIMIT_MAX_RETRIES` | `5` | Maximum rate-limit redispatch retries |
| `YOUTUBE_VIDEO_RETRY_DELAY` | `60` | Python retry delay in seconds |
| `YOUTUBE_PYTHON_PROCESS_TIMEOUT_SECONDS` | `600` | Python process timeout |
| `YOUTUBE_SYNC_JOBS_PER_MINUTE` | `0` | Sync-job rate limit; `0` is unlimited |
| `YOUTUBE_VIDEO_CHUNK_JOBS_PER_MINUTE` | `0` | Chunk-job rate limit; `0` is unlimited |
| `YOUTUBE_MAINTENANCE_INTERVAL_MINUTES` | `30` | Scheduled maintenance interval gate |

Docker overrides:

```dotenv
YOUTUBE_VIDEO_FETCH_THREADS=16
```

### yt-dlp Auto-Update

Values from `.env.example`:

| Variable | Default | Purpose |
| --- | ---: | --- |
| `YOUTUBE_YT_DLP_AUTO_UPDATE_ENABLED` | `true` | Enables scheduled and failure-triggered updates |
| `YOUTUBE_YT_DLP_WEEKLY_UPDATE_DAY` | `1` | Weekly update day; `0` = Sunday, `1` = Monday |
| `YOUTUBE_YT_DLP_WEEKLY_UPDATE_TIME` | `05:00` | Weekly update time in UTC |
| `YOUTUBE_YT_DLP_UPDATE_TIMEOUT_SECONDS` | `1200` | Update process timeout |
| `YOUTUBE_YT_DLP_UPDATE_MIN_INTERVAL_HOURS` | `6` | Minimum interval between successful updates |
| `YOUTUBE_YT_DLP_FAILURE_THRESHOLD` | `3` | Qualifying failure threshold |
| `YOUTUBE_YT_DLP_FAILURE_COOLDOWN_MINUTES` | `240` | Failure-triggered update cooldown |

With the default failure threshold of `3`, the fourth consecutive qualifying failure can trigger an update.

Docker overrides:

```dotenv
YOUTUBE_YT_DLP_UPDATE_MIN_INTERVAL_HOURS=0
```

`0` disables the successful-update interval gate.

### Logs

Laravel and the YouTube synchronization processes write weekly logs under `storage/logs`:

```text
laravel-YYYY-MM-DD.log
python-YYYY-MM-DD.log
youtube-YYYY-MM-DD.log
yt-dlp-update-YYYY-MM-DD.log
```

The filename date is the UTC Monday starting that log week.

Retention is configured with:

```dotenv
LOG_RETENTION_DAYS=90
```
Rules:
- default: `90`
- value must be a positive integer
- missing/invalid/zero/negative values fall back to `90`
- cleanup happens lazily during log writes
- current week is not deleted
- retention is based on completed whole log weeks, so a 90-day setting effectively retains an archive for 90–96 days

## Options

Application UI settings are stored in the `options` table and configured from `/options`.

### General

#### Channels Pagination

Controls how many channels are shown per page on the Channels page.

Default: `100`

Built-in choices:

```text
10
25
50
100
250
500
1000
unlimited
```

A custom positive integer is also accepted.

`unlimited` shows all matching channels without pagination.

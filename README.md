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

### 1) Create `.env` from `.env.example` then run from the project root:

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

## Running tests
Create `.env.testing` from `.env.testing.example`, set test DB credentials, then run:

```bash
php artisan test
```

To run the test suite inside Docker with a dedicated test database:

```bash
docker compose --env-file docker/.env.docker --profile test run --rm --build tests
```

Run Python tests after installing dev dependencies:

```bash
pip install -r python/requirements-dev.txt
```

Run tests:
```bash
pytest
```

## Additional docs
- `docs/CONFIGURATION.md`
- `docs/ARCHITECTURE.md`
- `docs/TESTING.md`

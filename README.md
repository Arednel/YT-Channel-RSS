<h2 align="center">YT-Channel RSS</h2>

Laravel application that creates per-channel RSS feeds from YouTube using [yt-dlp](https://github.com/yt-dlp/yt-dlp).

Recommended use case: creating RSS feed for [FreshRSS](https://github.com/FreshRSS/FreshRSS) with [Youlag](https://github.com/civilblur/youlag) extension.

## Features

- Add YouTube channels using @handle or /channel/UC... URLs and create a separate RSS feed for each saved channel.
- Keep channel and video metadata updated using yt-dlp, including upcoming/live videos and automatic retry handling.
- Search, sort, and paginate saved channels, view synchronization status, and copy RSS feed links.

## Quick Start (requires [Git](https://git-scm.com) and [Docker Compose](https://docs.docker.com/compose))

### 1) Run these commands

```bash
git clone https://github.com/Arednel/YT-Channel-RSS.git

cd YT-Channel-RSS

docker compose --env-file docker/.env.docker up --build -d
```

### 2) After startup

- YT-Channel RSS available at: `http://localhost:8080`
- Optional phpMyAdmin: http://localhost:8888 after enabling it in compose.yaml (disabled by default).

## Manual installation process

### Requirements
- PHP 8.3
- Composer
- MySQL 8
- [Python 3.14.6](https://www.python.org/downloads/release/python-3146) (tested with this version) and [pip](https://pypi.org/project/pip)

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

### Python

Install Python dev dependencies:

```bash
pip install -r python/requirements-dev.txt
```

Run Python tests:
```bash
pytest
```

## Additional docs

- [Configuration](docs/CONFIGURATION.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Testing](docs/TESTING.md)

## Contributing
Contributions are very welcome.

## License

Distributed under the terms of the [MIT License](LICENSE), _YT-Channel RSS_ is free and open-source software.

## Issues
If you encounter any problems, please [file an issue](https://github.com/Arednel/YT-Channel-RSS/issues) along with a detailed description.

## Acknowledgements

- [yt-dlp](https://github.com/yt-dlp/yt-dlp) — used to retrieve YouTube channel and video metadata.

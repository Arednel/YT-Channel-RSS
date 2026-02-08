<h2 align="center">YouTube RSS</h2>

Laravel 12 + Python `yt-dlp` project for generating per-channel Atom feeds from YouTube.

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

### 3) Run workers
```bash
php artisan queue:work
php artisan schedule:work
```

Manual maintenance:
```bash
php artisan youtube:maintenance
php artisan youtube:maintenance --channel-id=1
```

## Documentation
- `docs/README.md`
- `docs/architecture.md`
- `docs/configuration.md`
- `docs/operations.md`

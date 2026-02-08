<h2 align="center">YouTube RSS</h2>

Project that creates RSS feed from YouTube channgel using [yt-dlp](https://github.com/yt-dlp/yt-dlp).

## Requirements
1. [Python 3.10.11](https://www.python.org/downloads/release/python-31011) (tested on this version) and [pip](https://pypi.org/project/pip) (for easier installation process) 

## Installation process
#### Run commands from project folder
1. composer install
2. php artisan key:generate
3. php artisan migrate

### Python modules/packages installation process (venv)
#### Run commands from project folder
1. python -m venv python/venv
2. source python/venv/bin/activate  
2.1 # On Windows use: python\venv\Scripts\activate
3. pip install -r python/requirements.txt

## Background jobs (queue)
This project uses Laravel's database queue.
1. php artisan queue:work --max-time=3600

Job logs are written per channel to `python/logs/{youtube_id}/` and `storage/logs/{youtube_id}/`.

## Periodic maintenance
The maintenance command dispatches sync jobs for channels that are not currently busy.
1. php artisan schedule:work

Run on demand:
1. php artisan youtube:maintenance
2. php artisan youtube:maintenance --channel-id=1

Relevant env settings:
1. `YOUTUBE_MAINTENANCE_INTERVAL_MINUTES` (default `30`)
2. `YOUTUBE_PYTHON_PROCESS_TIMEOUT_SECONDS` (default `600`)
3. `YOUTUBE_VIDEO_RATE_LIMIT_COOLDOWN` (default `300`)
4. `YOUTUBE_VIDEO_RATE_LIMIT_MAX_RETRIES` (default `5`)

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
This project uses Laravel's database queue. Ensure `QUEUE_CONNECTION=database` in `.env`, then run a worker:
1. php artisan queue:work

Job logs are written per channel to `python/logs/{youtube_id}/`.
import argparse
import json
import logging
import os

import yt_dlp
from yt_dlp.utils import DownloadError


def setup_logging(log_file: str) -> None:
    os.makedirs(os.path.dirname(log_file), exist_ok=True)
    logging.basicConfig(
        filename=log_file,
        level=logging.INFO,
        format="[%(asctime)s] %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )


def is_optional_tab_error(message: str) -> bool:
    lowered = message.lower()
    known_messages = [
        "does not have a shorts tab",
        "does not have a streams tab",
        "does not have a live tab",
        "does not have a videos tab",
        "the playlist does not exist",
        "http error 404",
        "page not found",
    ]
    return any(text in lowered for text in known_messages)


def build_ydl_opts() -> dict:
    return {
        "skip_download": True,
        "extract_flat": "in_playlist",
        "quiet": True,
        "no_warnings": True,
    }


def extract_flat(url: str, ydl_opts: dict) -> dict | None:
    with yt_dlp.YoutubeDL(ydl_opts) as ydl:
        try:
            return ydl.extract_info(url, download=False)
        except DownloadError as exc:
            message = str(exc)
            if is_optional_tab_error(message):
                logging.info("Skipping optional tab: %s", message)
                return None
            raise


def write_json(path: str, payload: dict) -> None:
    with open(path, "w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--channel-url", required=True)
    parser.add_argument("--out-dir", required=True)
    parser.add_argument("--log-file", required=True)
    args = parser.parse_args()

    os.makedirs(args.out_dir, exist_ok=True)
    setup_logging(args.log_file)

    channel_json_path = os.path.join(args.out_dir, "channel.json")
    videos_jsonl_path = os.path.join(args.out_dir, "videos.jsonl")

    ydl_opts = build_ydl_opts()

    logging.info("Fetching channel metadata: %s", args.channel_url)
    channel_info = extract_flat(args.channel_url, ydl_opts)
    if channel_info is None:
        logging.error("Channel metadata fetch returned empty result.")
        return 1

    write_json(channel_json_path, channel_info)

    channel_id = channel_info.get("channel_id") or channel_info.get("id")
    base_url = args.channel_url.rstrip("/")
    tabs = [
        f"{base_url}/videos",
        f"{base_url}/streams",
        f"{base_url}/shorts",
    ]

    if isinstance(channel_id, str) and channel_id.startswith("UC"):
        playlist_id = channel_id.replace("UC", "UUMO", 1)
        tabs.append(f"https://www.youtube.com/playlist?list={playlist_id}")

    seen: set[str] = set()
    with open(videos_jsonl_path, "w", encoding="utf-8") as handle:
        for url in dict.fromkeys(tabs):
            logging.info("Fetching tab: %s", url)
            tab_info = extract_flat(url, ydl_opts)
            entries = (tab_info or {}).get("entries") or []
            for entry in entries:
                if not isinstance(entry, dict):
                    continue
                video_id = entry.get("id")
                if not isinstance(video_id, str) or video_id in seen:
                    continue
                seen.add(video_id)
                handle.write(json.dumps(entry, ensure_ascii=False) + "\n")

    logging.info("Saved channel.json and videos.jsonl to %s", args.out_dir)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

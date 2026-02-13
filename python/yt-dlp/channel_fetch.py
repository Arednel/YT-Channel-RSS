import argparse
import json
import logging
import os

from lib.channel_list import fetch_channel_and_video_entries
from lib.common import setup_logging, write_json


def _channel_label(channel_url: str) -> str:
    normalized = channel_url.strip()
    if normalized == "":
        return "unknown"
    return normalized


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--channel-url", required=True)
    parser.add_argument("--out-dir", required=True)
    parser.add_argument("--log-file", required=True)
    parser.add_argument("--entries-per-tab", type=int, default=None)
    args = parser.parse_args()

    os.makedirs(args.out_dir, exist_ok=True)
    setup_logging(args.log_file)
    channel_label = _channel_label(args.channel_url)

    channel_json_path = os.path.join(args.out_dir, "channel.json")
    videos_jsonl_path = os.path.join(args.out_dir, "videos.jsonl")

    logging.info(
        "Fetching channel metadata. channel=%s url=%s",
        channel_label,
        args.channel_url,
    )
    channel_info, entries = fetch_channel_and_video_entries(
        args.channel_url,
        entries_per_tab=args.entries_per_tab,
        channel_context=channel_label,
    )
    if channel_info is None:
        logging.error(
            "Channel metadata fetch returned empty result. channel=%s",
            channel_label,
        )
        return 1

    write_json(channel_json_path, channel_info)
    with open(videos_jsonl_path, "w", encoding="utf-8") as handle:
        for entry in entries:
            handle.write(json.dumps(entry, ensure_ascii=False) + "\n")

    logging.info(
        "Saved channel.json and videos.jsonl. channel=%s out_dir=%s entries=%s",
        channel_label,
        args.out_dir,
        len(entries),
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

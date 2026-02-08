import argparse
import json
import logging
import os
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

import yt_dlp
from yt_dlp.utils import DownloadError

RATE_LIMIT_EXIT_CODE = 29
PARTIAL_FAILURE_EXIT_CODE = 30


def setup_logging(log_file: str) -> None:
    os.makedirs(os.path.dirname(log_file), exist_ok=True)
    logging.basicConfig(
        filename=log_file,
        level=logging.INFO,
        format="[%(asctime)s] %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )


def build_ydl_opts() -> dict:
    return {
        "skip_download": True,
        "quiet": True,
        "no_warnings": True,
    }


def build_members_probe_opts() -> dict:
    opts = build_ydl_opts()
    # Allow metadata extraction even when formats are unavailable.
    opts["ignore_no_formats_error"] = True
    return opts


def is_rate_limit_error(message: str) -> bool:
    lowered = message.lower()
    return (
        "too many requests" in lowered
        or "http error 429" in lowered
        or "sign in to confirm you're not a bot" in lowered
        or "rate limit" in lowered
    )


def is_members_only_error(message: str) -> bool:
    lowered = message.lower()
    return (
        "join this channel" in lowered
        or "members-only content" in lowered
        or "available to this channel's members" in lowered
    )


def is_age_restricted_error(message: str) -> bool:
    lowered = message.lower()
    return (
        "sign in to confirm your age" in lowered
        or "age-restricted" in lowered
        or "this video may be inappropriate for some users" in lowered
    )


def is_restricted_video_error(message: str) -> bool:
    return is_members_only_error(message) or is_age_restricted_error(message)


def is_upcoming_live_error(message: str) -> bool:
    lowered = message.lower()
    return "this live event will begin in" in lowered or "premieres in" in lowered


def load_source_entries(path: Path) -> list[dict]:
    if not path.exists():
        return []

    entries: list[dict] = []
    seen: set[str] = set()
    with open(path, "r", encoding="utf-8") as handle:
        for raw_line in handle:
            line = raw_line.strip()
            if not line:
                continue

            entry: dict | None = None

            if path.suffix.lower() == ".jsonl":
                try:
                    payload = json.loads(line)
                except json.JSONDecodeError:
                    continue
                if isinstance(payload, dict):
                    raw_id = payload.get("id")
                    if isinstance(raw_id, str):
                        entry = payload
            else:
                entry = {"id": line}

            if not isinstance(entry, dict):
                continue

            video_id = entry.get("id")
            if not isinstance(video_id, str) or video_id == "" or video_id in seen:
                continue

            seen.add(video_id)
            entries.append(entry)

    return entries


def fetch_video_info(
    video_id: str, ydl_opts: dict, retries: int, retry_delay: int
) -> tuple[str, dict | None]:
    url = f"https://www.youtube.com/watch?v={video_id}"
    for attempt in range(1, retries + 1):
        try:
            with yt_dlp.YoutubeDL(ydl_opts) as ydl:
                info = ydl.extract_info(url, download=False)
            if isinstance(info, dict):
                return "ok", info
            return "failed", None
        except DownloadError as exc:
            message = str(exc)
            if is_rate_limit_error(message):
                logging.error("Rate limit detected. id=%s error=%s", video_id, message)
                return "rate_limited", None
            if is_restricted_video_error(message):
                probe_status, members_info = try_fetch_members_only_metadata(video_id)
                if probe_status == "ok" and isinstance(members_info, dict):
                    logging.info(
                        "Restricted video metadata extracted. id=%s upload_date=%s timestamp=%s",
                        video_id,
                        members_info.get("upload_date"),
                        members_info.get("timestamp"),
                    )
                    return "ok", members_info
                if probe_status == "rate_limited":
                    return "rate_limited", None

                logging.warning(
                    "Restricted video, skipping retries. id=%s error=%s",
                    video_id,
                    message,
                )
                return "restricted", None
            if is_upcoming_live_error(message):
                logging.info(
                    "Upcoming live stream detected, skipping retries. id=%s error=%s",
                    video_id,
                    message,
                )
                return "upcoming", None

            logging.error(
                "Video fetch failed. id=%s attempt=%s/%s error=%s",
                video_id,
                attempt,
                retries,
                message,
            )
            if attempt < retries and retry_delay > 0:
                time.sleep(retry_delay)
    return "failed", None


def try_fetch_members_only_metadata(video_id: str) -> tuple[str, dict | None]:
    url = f"https://www.youtube.com/watch?v={video_id}"

    try:
        with yt_dlp.YoutubeDL(build_members_probe_opts()) as ydl:
            info = ydl.extract_info(url, download=False)
        if isinstance(info, dict):
            return "ok", info
    except DownloadError as exc:
        message = str(exc)
        if is_rate_limit_error(message):
            logging.error(
                "Rate limit detected during members-only metadata probe. id=%s error=%s",
                video_id,
                message,
            )
            return "rate_limited", None

        logging.warning(
            "Members-only metadata probe failed. id=%s error=%s",
            video_id,
            message,
        )
    except Exception as exc:
        logging.warning(
            "Members-only metadata probe exception. id=%s error=%s",
            video_id,
            str(exc),
        )

    return "failed", None


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--source-dir", required=True)
    parser.add_argument("--source-file", required=True)
    parser.add_argument("--out-dir", required=True)
    parser.add_argument("--jsonl-output", required=True)
    parser.add_argument("--log-file", required=True)
    parser.add_argument("--retries", type=int, default=3)
    parser.add_argument("--retry-delay", type=int, default=60)
    parser.add_argument("--max-workers", type=int, default=8)
    args = parser.parse_args()

    setup_logging(args.log_file)

    source_path = Path(args.source_dir) / args.source_file
    output_dir = Path(args.out_dir)
    output_dir.mkdir(parents=True, exist_ok=True)
    tmp_dir = output_dir / "tmp"
    tmp_dir.mkdir(parents=True, exist_ok=True)

    source_entries = load_source_entries(source_path)
    if not source_entries:
        logging.info("No video ids to process. source=%s", source_path)
        Path(args.jsonl_output).write_text("", encoding="utf-8")
        return 0

    video_ids = [
        entry["id"] for entry in source_entries if isinstance(entry.get("id"), str)
    ]
    source_entry_by_id = {
        entry["id"]: entry
        for entry in source_entries
        if isinstance(entry.get("id"), str)
    }

    ydl_opts = build_ydl_opts()
    workers = max(1, args.max_workers)
    successful = 0
    failed = 0
    restricted = 0
    rate_limited = False
    temp_files: list[Path] = []

    with ThreadPoolExecutor(max_workers=workers) as executor:
        futures = {
            executor.submit(
                fetch_video_info,
                video_id,
                ydl_opts,
                args.retries,
                args.retry_delay,
            ): video_id
            for video_id in video_ids
        }

        for future in as_completed(futures):
            video_id = futures[future]
            status, info = future.result()

            if status == "rate_limited":
                rate_limited = True
                for pending_future in futures:
                    if not pending_future.done():
                        pending_future.cancel()
                break

            if status == "ok" and isinstance(info, dict):
                json_path = tmp_dir / f"{video_id}.json"
                with open(json_path, "w", encoding="utf-8") as handle:
                    json.dump(info, handle, ensure_ascii=False)
                temp_files.append(json_path)
                successful += 1
            elif status == "restricted":
                fallback_entry = source_entry_by_id.get(video_id)
                if isinstance(fallback_entry, dict):
                    fallback_payload = dict(fallback_entry)
                    fallback_payload["restricted"] = True

                    json_path = tmp_dir / f"{video_id}.json"
                    with open(json_path, "w", encoding="utf-8") as handle:
                        json.dump(fallback_payload, handle, ensure_ascii=False)
                    temp_files.append(json_path)
                else:
                    failed += 1
                restricted += 1
            elif status == "upcoming":
                fallback_entry = source_entry_by_id.get(video_id)
                if isinstance(fallback_entry, dict):
                    fallback_payload = dict(fallback_entry)
                    fallback_payload["is_upcoming"] = True

                    json_path = tmp_dir / f"{video_id}.json"
                    with open(json_path, "w", encoding="utf-8") as handle:
                        json.dump(fallback_payload, handle, ensure_ascii=False)
                    temp_files.append(json_path)
                else:
                    failed += 1
            else:
                failed += 1

    if rate_limited:
        for json_path in temp_files:
            try:
                json_path.unlink()
            except OSError:
                pass
        logging.error(
            "Chunk stopped due to rate limit. workers=%s source=%s",
            workers,
            source_path,
        )
        return RATE_LIMIT_EXIT_CODE

    with open(args.jsonl_output, "w", encoding="utf-8") as out_handle:
        for json_path in temp_files:
            with open(json_path, "r", encoding="utf-8") as in_handle:
                out_handle.write(in_handle.read().strip() + "\n")

    for json_path in temp_files:
        try:
            json_path.unlink()
        except OSError:
            pass

    try:
        tmp_dir.rmdir()
    except OSError:
        pass

    logging.info(
        "Chunk completed. total=%s successful=%s restricted=%s failed=%s workers=%s source=%s output=%s",
        len(video_ids),
        successful,
        restricted,
        failed,
        workers,
        source_path,
        args.jsonl_output,
    )

    if failed > 0:
        return PARTIAL_FAILURE_EXIT_CODE

    return 0


if __name__ == "__main__":
    raise SystemExit(main())

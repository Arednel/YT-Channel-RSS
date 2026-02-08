import argparse
import json
import logging
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

from lib.common import setup_logging
from lib.video_detail import build_detail_ydl_opts, fetch_video_info

RATE_LIMIT_EXIT_CODE = 29
PARTIAL_FAILURE_EXIT_CODE = 30


def normalize_thumbnail(value: object) -> str:
    if isinstance(value, str) and value:
        return value

    if isinstance(value, list):
        for item in reversed(value):
            if isinstance(item, dict):
                url = item.get("url")
                if isinstance(url, str) and url:
                    return url

    return ""


def compact_video_payload(
    video_id: str,
    *,
    source_entry: dict | None = None,
    info: dict | None = None,
    restricted: bool = False,
    upcoming: bool = False,
) -> dict:
    payload: dict = {"id": video_id}
    keys = [
        "title",
        "description",
        "upload_date",
        "timestamp",
        "release_timestamp",
        "modified_date",
        "modified_timestamp",
        "live_status",
    ]

    for key in keys:
        value = None
        if isinstance(info, dict):
            value = info.get(key)
        if value is None and isinstance(source_entry, dict):
            value = source_entry.get(key)
        if value is not None:
            payload[key] = value

    is_upcoming_value = None
    if isinstance(info, dict):
        is_upcoming_value = info.get("is_upcoming")
    if is_upcoming_value is None and isinstance(source_entry, dict):
        is_upcoming_value = source_entry.get("is_upcoming")
    if is_upcoming_value is not None:
        payload["is_upcoming"] = bool(is_upcoming_value)

    thumbnail = ""
    if isinstance(info, dict):
        thumbnail = normalize_thumbnail(
            info.get("thumbnail")
            if isinstance(info.get("thumbnail"), str)
            else info.get("thumbnails")
        )
    if not thumbnail and isinstance(source_entry, dict):
        thumbnail = normalize_thumbnail(
            source_entry.get("thumbnail")
            if isinstance(source_entry.get("thumbnail"), str)
            else source_entry.get("thumbnails")
        )
    if thumbnail:
        payload["thumbnail"] = thumbnail

    if restricted:
        payload["restricted"] = True
    if upcoming:
        payload["is_upcoming"] = True

    return payload


def build_fallback_payload(
    video_id: str,
    source_entry: dict | None,
    *,
    restricted: bool = False,
    upcoming: bool = False,
) -> dict:
    return compact_video_payload(
        video_id,
        source_entry=source_entry,
        restricted=restricted,
        upcoming=upcoming,
    )


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

    ydl_opts = build_detail_ydl_opts()
    workers = max(1, args.max_workers)
    successful = 0
    failed = 0
    restricted = 0
    upcoming = 0
    rate_limited = False
    temp_files: list[Path] = []

    executor = ThreadPoolExecutor(max_workers=workers)
    try:
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
            try:
                status, info = future.result()
            except Exception as exc:
                logging.exception("Video worker crashed. id=%s error=%s", video_id, str(exc))
                failed += 1
                continue

            if status == "rate_limited":
                rate_limited = True
                for pending_future in futures:
                    if not pending_future.done():
                        pending_future.cancel()
                break

            if status == "ok" and isinstance(info, dict):
                fallback_entry = source_entry_by_id.get(video_id)
                compact_payload = compact_video_payload(
                    video_id,
                    source_entry=fallback_entry,
                    info=info,
                )
                json_path = tmp_dir / f"{video_id}.json"
                with open(json_path, "w", encoding="utf-8") as handle:
                    json.dump(compact_payload, handle, ensure_ascii=False)
                temp_files.append(json_path)
                successful += 1
            elif status == "restricted":
                fallback_entry = source_entry_by_id.get(video_id)
                if fallback_entry is None:
                    logging.warning(
                        "Restricted video fallback missing source entry. id=%s",
                        video_id,
                    )

                fallback_payload = build_fallback_payload(
                    video_id,
                    fallback_entry,
                    restricted=True,
                )
                json_path = tmp_dir / f"{video_id}.json"
                with open(json_path, "w", encoding="utf-8") as handle:
                    json.dump(fallback_payload, handle, ensure_ascii=False)
                temp_files.append(json_path)
                restricted += 1
            elif status == "upcoming":
                fallback_entry = source_entry_by_id.get(video_id)
                if fallback_entry is None:
                    logging.info(
                        "Upcoming video fallback missing source entry. id=%s",
                        video_id,
                    )

                fallback_payload = build_fallback_payload(
                    video_id,
                    fallback_entry,
                    upcoming=True,
                )
                json_path = tmp_dir / f"{video_id}.json"
                with open(json_path, "w", encoding="utf-8") as handle:
                    json.dump(fallback_payload, handle, ensure_ascii=False)
                temp_files.append(json_path)
                upcoming += 1
            else:
                failed += 1
    finally:
        executor.shutdown(wait=not rate_limited, cancel_futures=rate_limited)

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
        "Chunk completed. total=%s successful=%s restricted=%s upcoming=%s failed=%s workers=%s source=%s output=%s",
        len(video_ids),
        successful,
        restricted,
        upcoming,
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

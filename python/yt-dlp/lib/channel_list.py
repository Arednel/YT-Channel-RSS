import logging
from datetime import datetime, timezone

from .common import build_flat_ydl_opts, extract_flat, first_non_empty_string


def normalize_channel_info(channel_info: dict) -> dict:
    resolved = dict(channel_info)

    channel_name = first_non_empty_string(
        [resolved.get("channel"), resolved.get("uploader")]
    )
    channel_id = first_non_empty_string([resolved.get("channel_id")])
    uploader_id = first_non_empty_string([resolved.get("uploader_id")])
    channel_url = first_non_empty_string([resolved.get("channel_url")])
    uploader_url = first_non_empty_string([resolved.get("uploader_url")])

    first_level_entries = resolved.get("entries") or []
    if isinstance(first_level_entries, list):
        for entry in first_level_entries:
            if not isinstance(entry, dict):
                continue

            channel_name = channel_name or first_non_empty_string(
                [entry.get("channel"), entry.get("uploader")]
            )
            channel_id = channel_id or first_non_empty_string([entry.get("channel_id")])
            uploader_id = uploader_id or first_non_empty_string([entry.get("uploader_id")])
            channel_url = channel_url or first_non_empty_string([entry.get("channel_url")])
            uploader_url = uploader_url or first_non_empty_string([entry.get("uploader_url")])

            nested_entries = entry.get("entries") or []
            if not isinstance(nested_entries, list):
                continue

            for nested in nested_entries:
                if not isinstance(nested, dict):
                    continue
                channel_name = channel_name or first_non_empty_string(
                    [nested.get("channel"), nested.get("uploader")]
                )
                channel_id = channel_id or first_non_empty_string(
                    [nested.get("channel_id")]
                )
                uploader_id = uploader_id or first_non_empty_string(
                    [nested.get("uploader_id")]
                )
                channel_url = channel_url or first_non_empty_string(
                    [nested.get("channel_url")]
                )
                uploader_url = uploader_url or first_non_empty_string(
                    [nested.get("uploader_url")]
                )

                if (
                    channel_name
                    and channel_id
                    and uploader_id
                    and channel_url
                    and uploader_url
                ):
                    break

            if (
                channel_name
                and channel_id
                and uploader_id
                and channel_url
                and uploader_url
            ):
                break

    if channel_name is not None:
        resolved["channel"] = channel_name
        resolved["uploader"] = resolved.get("uploader") or channel_name

    if channel_id is not None:
        resolved["channel_id"] = channel_id

    if uploader_id is not None:
        resolved["uploader_id"] = uploader_id

    if channel_url is not None:
        resolved["channel_url"] = channel_url

    if uploader_url is not None:
        resolved["uploader_url"] = uploader_url

    return resolved


def resolve_channel_id(channel_payload: dict) -> str | None:
    channel_id = channel_payload.get("channel_id") or channel_payload.get("id")
    if isinstance(channel_id, str) and channel_id != "":
        return channel_id
    return None


def build_tab_urls(channel_url: str, channel_id: str | None) -> list[str]:
    base_url = channel_url.rstrip("/")
    tabs = [
        f"{base_url}/videos",
        f"{base_url}/streams",
        f"{base_url}/shorts",
    ]

    if isinstance(channel_id, str) and channel_id.startswith("UC"):
        playlist_id = channel_id.replace("UC", "UUMO", 1)
        tabs.append(f"https://www.youtube.com/playlist?list={playlist_id}")

    return list(dict.fromkeys(tabs))


def fetch_channel_and_video_entries(
    channel_url: str, entries_per_tab: int | None = None
) -> tuple[dict | None, list[dict]]:
    ydl_opts = build_flat_ydl_opts(entries_per_tab)

    channel_info = extract_flat(channel_url, ydl_opts)
    if not isinstance(channel_info, dict):
        return None, []

    normalized_channel = normalize_channel_info(channel_info)
    tabs = build_tab_urls(channel_url, resolve_channel_id(normalized_channel))

    entries: list[dict] = []
    seen: set[str] = set()

    for tab_url in tabs:
        logging.info("Fetching tab: %s", tab_url)
        tab_info = extract_flat(tab_url, ydl_opts, log_optional_tab=True)
        tab_entries = (tab_info or {}).get("entries") or []
        if not isinstance(tab_entries, list):
            continue

        for entry in tab_entries:
            if not isinstance(entry, dict):
                continue
            video_id = entry.get("id")
            if not isinstance(video_id, str) or video_id == "" or video_id in seen:
                continue

            seen.add(video_id)
            entries.append(entry)

    return normalized_channel, entries


def parse_upload_date(raw: object) -> int | None:
    if not isinstance(raw, str) or len(raw) != 8:
        return None

    try:
        parsed = datetime.strptime(raw, "%Y%m%d")
    except ValueError:
        return None

    return int(parsed.replace(tzinfo=timezone.utc).timestamp())


def entry_sort_key(entry: dict) -> int | None:
    for key in ("timestamp", "release_timestamp"):
        raw = entry.get(key)
        if isinstance(raw, (int, float)):
            return int(raw)
        if isinstance(raw, str) and raw.isdigit():
            return int(raw)

    parsed_upload_date = parse_upload_date(entry.get("upload_date"))
    if parsed_upload_date is not None:
        return parsed_upload_date

    return None


def resolve_latest_video_id(entries: list[dict]) -> str | None:
    candidates: list[tuple[int | None, str]] = []
    for entry in entries:
        if not isinstance(entry, dict):
            continue
        video_id = entry.get("id")
        if not isinstance(video_id, str) or video_id == "":
            continue
        candidates.append((entry_sort_key(entry), video_id))

    dated_candidates = [item for item in candidates if item[0] is not None]
    if dated_candidates:
        return max(dated_candidates, key=lambda item: int(item[0]))[1]

    if candidates:
        return candidates[0][1]

    return None

import json
import logging
import os
from collections.abc import Iterable

import yt_dlp
from yt_dlp.utils import DownloadError


OPTIONAL_TAB_ERROR_SUBSTRINGS = (
    "does not have a shorts tab",
    "does not have a streams tab",
    "does not have a live tab",
    "does not have a videos tab",
    "the playlist does not exist",
    "http error 404",
    "page not found",
)


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
    return any(text in lowered for text in OPTIONAL_TAB_ERROR_SUBSTRINGS)


def build_flat_ydl_opts(entries_per_tab: int | None = None) -> dict:
    options = {
        "skip_download": True,
        "extract_flat": "in_playlist",
        "quiet": True,
        "no_warnings": True,
    }
    if entries_per_tab is not None:
        options["playlistend"] = max(1, entries_per_tab)

    return options


def extract_flat(
    url: str, ydl_opts: dict, *, log_optional_tab: bool = False
) -> dict | None:
    with yt_dlp.YoutubeDL(ydl_opts) as ydl:
        try:
            return ydl.extract_info(url, download=False)
        except DownloadError as exc:
            message = str(exc)
            if is_optional_tab_error(message):
                if log_optional_tab:
                    logging.info("Skipping optional tab: %s", message)
                return None
            raise


def write_json(path: str, payload: dict) -> None:
    with open(path, "w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False)


def first_non_empty_string(values: Iterable[object]) -> str | None:
    for value in values:
        if isinstance(value, str) and value.strip() != "":
            return value
    return None

import logging
import time

import yt_dlp
from yt_dlp.utils import DownloadError


def _channel_label(channel_context: str | None) -> str:
    if isinstance(channel_context, str) and channel_context.strip() != "":
        return channel_context.strip()
    return "unknown"


def build_detail_ydl_opts() -> dict:
    return {
        "skip_download": True,
        "quiet": True,
        "no_warnings": True,
    }


def build_members_probe_opts() -> dict:
    options = build_detail_ydl_opts()
    # Allow metadata extraction even when formats are unavailable.
    options["ignore_no_formats_error"] = True
    return options


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


def is_video_unavailable_error(message: str) -> bool:
    lowered = message.lower()
    return (
        "video unavailable" in lowered
        or "this video is unavailable" in lowered
        or "this video is not available" in lowered
        or "private video" in lowered
        or "this video is private" in lowered
        or "video has been removed" in lowered
    )


def is_restricted_video_error(message: str) -> bool:
    return (
        is_members_only_error(message)
        or is_age_restricted_error(message)
        or is_video_unavailable_error(message)
    )


def restricted_video_type(message: str) -> str:
    if is_members_only_error(message):
        return "members_only"
    if is_age_restricted_error(message):
        return "age_restricted"
    if is_video_unavailable_error(message):
        return "video_unavailable"
    return "restricted"


def is_upcoming_live_error(message: str) -> bool:
    lowered = message.lower()
    return (
        "this live event will begin in" in lowered
        or "premieres in" in lowered
        or "this live event has not started yet" in lowered
        or "live event is offline" in lowered
    )


def classify_video_fetch_error(message: str) -> str:
    if is_rate_limit_error(message):
        return "rate_limited"
    if is_restricted_video_error(message):
        return "restricted"
    if is_upcoming_live_error(message):
        return "upcoming"
    return "failed"


def handle_restricted_video(
    video_id: str,
    message: str,
    channel_context: str | None = None,
) -> tuple[str, dict | None]:
    restriction_type = restricted_video_type(message)
    channel = _channel_label(channel_context)

    if restriction_type in {"members_only", "age_restricted"}:
        probe_status, members_info = try_fetch_members_only_metadata(
            video_id,
            channel_context=channel,
        )
        if probe_status == "ok" and isinstance(members_info, dict):
            logging.info(
                "Restricted video metadata extracted. channel=%s type=%s id=%s upload_date=%s timestamp=%s",
                channel,
                restriction_type,
                video_id,
                members_info.get("upload_date"),
                members_info.get("timestamp"),
            )
            return "ok", members_info
        if probe_status == "rate_limited":
            return "rate_limited", None

    logging.warning(
        "Restricted video detected. channel=%s type=%s id=%s error=%s. Skipping retries.",
        channel,
        restriction_type,
        video_id,
        message,
    )
    return "restricted", None


def try_fetch_members_only_metadata(
    video_id: str,
    channel_context: str | None = None,
) -> tuple[str, dict | None]:
    url = f"https://www.youtube.com/watch?v={video_id}"
    channel = _channel_label(channel_context)

    try:
        with yt_dlp.YoutubeDL(build_members_probe_opts()) as ydl:
            info = ydl.extract_info(url, download=False)
        if isinstance(info, dict):
            return "ok", ydl.sanitize_info(info)
    except DownloadError as exc:
        message = str(exc)
        if is_rate_limit_error(message):
            logging.error(
                "Rate limit detected during members-only metadata probe. channel=%s id=%s error=%s",
                channel,
                video_id,
                message,
            )
            return "rate_limited", None

        logging.warning(
            "Members-only metadata probe failed. channel=%s id=%s error=%s",
            channel,
            video_id,
            message,
        )
    except Exception as exc:
        logging.warning(
            "Members-only metadata probe exception. channel=%s id=%s error=%s",
            channel,
            video_id,
            str(exc),
        )

    return "failed", None


def fetch_video_info(
    video_id: str,
    ydl_opts: dict,
    retries: int,
    retry_delay: int,
    channel_context: str | None = None,
) -> tuple[str, dict | None]:
    url = f"https://www.youtube.com/watch?v={video_id}"
    channel = _channel_label(channel_context)
    for attempt in range(1, retries + 1):
        try:
            with yt_dlp.YoutubeDL(ydl_opts) as ydl:
                info = ydl.extract_info(url, download=False)
            if isinstance(info, dict):
                return "ok", ydl.sanitize_info(info)
            return "failed", None
        except DownloadError as exc:
            message = str(exc)
            classification = classify_video_fetch_error(message)
            if classification == "rate_limited":
                logging.error(
                    "Rate limit detected. channel=%s id=%s error=%s",
                    channel,
                    video_id,
                    message,
                )
                return "rate_limited", None
            if classification == "restricted":
                return handle_restricted_video(
                    video_id,
                    message,
                    channel_context=channel,
                )
            if classification == "upcoming":
                logging.info(
                    "Upcoming live stream detected, skipping retries. channel=%s id=%s error=%s",
                    channel,
                    video_id,
                    message,
                )
                return "upcoming", None

            logging.error(
                "Video fetch failed. channel=%s id=%s attempt=%s/%s error=%s",
                channel,
                video_id,
                attempt,
                retries,
                message,
            )
            if attempt < retries and retry_delay > 0:
                time.sleep(retry_delay)
        except Exception as exc:
            message = str(exc)
            classification = classify_video_fetch_error(message)

            if classification == "rate_limited":
                logging.error(
                    "Rate limit detected. channel=%s id=%s error=%s",
                    channel,
                    video_id,
                    message,
                )
                return "rate_limited", None

            if classification == "upcoming":
                logging.info(
                    "Upcoming live stream detected, skipping retries. channel=%s id=%s error=%s",
                    channel,
                    video_id,
                    message,
                )
                return "upcoming", None

            if classification == "restricted":
                return handle_restricted_video(
                    video_id,
                    message,
                    channel_context=channel,
                )

            logging.exception(
                "Unexpected video fetch exception. channel=%s id=%s attempt=%s/%s error=%s",
                channel,
                video_id,
                attempt,
                retries,
                message,
            )
            if attempt < retries and retry_delay > 0:
                time.sleep(retry_delay)

    return "failed", None

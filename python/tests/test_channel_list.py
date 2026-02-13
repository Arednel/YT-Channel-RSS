from pathlib import Path
import sys


PROJECT_ROOT = Path(__file__).resolve().parents[2]
YT_DLP_DIR = PROJECT_ROOT / "python" / "yt-dlp"
if str(YT_DLP_DIR) not in sys.path:
    sys.path.insert(0, str(YT_DLP_DIR))

from lib import channel_list  # noqa: E402


def test_normalize_channel_info_uses_nested_entries_when_top_level_is_missing():
    payload = {
        "id": "UC123",
        "entries": [
            {
                "entries": [
                    {
                        "channel": "Nested Channel",
                        "channel_id": "UC999",
                        "uploader_id": "@nested",
                        "channel_url": "https://www.youtube.com/channel/UC999",
                        "uploader_url": "https://www.youtube.com/@nested",
                    }
                ]
            }
        ],
    }

    normalized = channel_list.normalize_channel_info(payload)

    assert normalized["channel"] == "Nested Channel"
    assert normalized["uploader"] == "Nested Channel"
    assert normalized["channel_id"] == "UC999"
    assert normalized["uploader_id"] == "@nested"
    assert normalized["channel_url"] == "https://www.youtube.com/channel/UC999"
    assert normalized["uploader_url"] == "https://www.youtube.com/@nested"


def test_build_tab_urls_adds_uploads_playlist_for_channel_ids():
    urls = channel_list.build_tab_urls("https://www.youtube.com/@demo", "UCabc123")

    assert urls == [
        "https://www.youtube.com/@demo/videos",
        "https://www.youtube.com/@demo/streams",
        "https://www.youtube.com/@demo/shorts",
        "https://www.youtube.com/playlist?list=UUMOabc123",
    ]


def test_fetch_channel_and_video_entries_deduplicates_entries_across_tabs(monkeypatch):
    channel_url = "https://www.youtube.com/@demo"

    responses = {
        channel_url: {"id": "UC123", "channel_id": "UC123"},
        f"{channel_url}/videos": {"entries": [{"id": "v1"}, {"id": "v2"}]},
        f"{channel_url}/streams": {"entries": [{"id": "v2"}, {"id": "v3"}]},
        f"{channel_url}/shorts": None,
        "https://www.youtube.com/playlist?list=UUMO123": {"entries": [{"id": "v1"}]},
    }

    def fake_extract_flat(
        url: str,
        ydl_opts: dict,
        *,
        log_optional_tab: bool = False,
        channel_context: str | None = None,
    ):
        return responses.get(url)

    monkeypatch.setattr(channel_list, "extract_flat", fake_extract_flat)
    monkeypatch.setattr(channel_list, "build_flat_ydl_opts", lambda entries_per_tab: {})

    normalized, entries = channel_list.fetch_channel_and_video_entries(channel_url)

    assert normalized["channel_id"] == "UC123"
    assert [entry["id"] for entry in entries] == ["v1", "v2", "v3"]


def test_resolve_latest_video_id_prefers_best_available_timestamp():
    entries = [
        {"id": "upload-date-only", "upload_date": "20250101"},
        {"id": "timestamp", "timestamp": 1736467200},
        {"id": "release-string", "release_timestamp": "1736553600"},
    ]

    latest = channel_list.resolve_latest_video_id(entries)

    assert latest == "release-string"

import json
import os
import subprocess
import sys
import xml.etree.ElementTree as element_tree
from pathlib import Path

import pytest


PROJECT_ROOT = Path(__file__).resolve().parents[2]
CHANNEL_FETCH_SCRIPT = PROJECT_ROOT / "python" / "yt-dlp" / "channel_fetch.py"
VENV_PYTHON_WINDOWS = PROJECT_ROOT / "python" / "venv" / "Scripts" / "python.exe"
VENV_PYTHON_POSIX = PROJECT_ROOT / "python" / "venv" / "bin" / "python"


def _phpunit_env(name: str) -> str | None:
    phpunit_xml = PROJECT_ROOT / "phpunit.xml"
    if not phpunit_xml.exists():
        return None

    try:
        root = element_tree.parse(phpunit_xml).getroot()
    except element_tree.ParseError:
        return None

    for node in root.findall(".//php/env"):
        if node.get("name") == name:
            value = node.get("value")
            if isinstance(value, str):
                return value

    return None


def _network_tests_enabled() -> bool:
    raw = os.getenv("YOUTUBE_TESTS_WITH_NETWORK")
    if raw is None:
        raw = _phpunit_env("YOUTUBE_TESTS_WITH_NETWORK") or "false"

    return raw.strip().lower() in {"1", "true", "yes", "on"}


def _configured_channel_url() -> str | None:
    raw = os.getenv("YOUTUBE_TEST_REAL_CHANNEL")
    if raw is None:
        raw = _phpunit_env("YOUTUBE_TEST_REAL_CHANNEL") or ""

    raw = raw.strip()
    if raw == "":
        return None

    if raw.startswith("http://") or raw.startswith("https://"):
        return raw

    return f"https://www.youtube.com/{raw.lstrip('/')}"


def _python_binary() -> str:
    if VENV_PYTHON_WINDOWS.exists():
        return str(VENV_PYTHON_WINDOWS)
    if VENV_PYTHON_POSIX.exists():
        return str(VENV_PYTHON_POSIX)
    return sys.executable


@pytest.mark.integration
def test_channel_fetch_script_runs_against_configured_real_channel(tmp_path: Path) -> None:
    if not _network_tests_enabled():
        pytest.skip("Set YOUTUBE_TESTS_WITH_NETWORK=true to run network integration tests.")

    channel_url = _configured_channel_url()
    if channel_url is None:
        pytest.skip("Set YOUTUBE_TEST_REAL_CHANNEL to run channel_fetch integration test.")

    out_dir = tmp_path / "channel-fetch"
    out_dir.mkdir(parents=True, exist_ok=True)
    log_file = out_dir / "channel_fetch.log"
    channel_json = out_dir / "channel.json"
    videos_jsonl = out_dir / "videos.jsonl"

    command = [
        _python_binary(),
        str(CHANNEL_FETCH_SCRIPT),
        "--channel-url",
        channel_url,
        "--out-dir",
        str(out_dir),
        "--log-file",
        str(log_file),
    ]

    completed = subprocess.run(
        command,
        cwd=PROJECT_ROOT,
        capture_output=True,
        text=True,
        timeout=600,
        check=False,
    )

    assert completed.returncode == 0, (
        "channel_fetch.py failed.\n"
        f"stdout:\n{completed.stdout}\n"
        f"stderr:\n{completed.stderr}"
    )
    assert channel_json.exists(), "Expected channel.json output file."
    assert videos_jsonl.exists(), "Expected videos.jsonl output file."

    payload = json.loads(channel_json.read_text(encoding="utf-8"))
    assert isinstance(payload, dict)
    assert payload.get("channel_id") or payload.get("id")

    with videos_jsonl.open("r", encoding="utf-8") as handle:
        non_empty_lines = [line.strip() for line in handle if line.strip() != ""]

    if non_empty_lines:
        first_entry = json.loads(non_empty_lines[0])
        assert isinstance(first_entry, dict)
        assert first_entry.get("id")

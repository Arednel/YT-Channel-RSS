import logging
import sys
from datetime import datetime, timezone
from pathlib import Path

import pytest


PROJECT_ROOT = Path(__file__).resolve().parents[2]
YT_DLP_DIR = PROJECT_ROOT / "python" / "yt-dlp"
if str(YT_DLP_DIR) not in sys.path:
    sys.path.insert(0, str(YT_DLP_DIR))

from lib import common, weekly_logging  # noqa: E402


def record(timestamp: str, message: str) -> logging.LogRecord:
    moment = datetime.strptime(timestamp, "%Y-%m-%d %H:%M:%S").replace(
        tzinfo=timezone.utc
    )
    log_record = logging.LogRecord(
        name="testing",
        level=logging.INFO,
        pathname=__file__,
        lineno=1,
        msg=message,
        args=(),
        exc_info=None,
    )
    log_record.created = moment.timestamp()

    return log_record


def test_week_start_and_path_use_utc_monday_boundaries():
    sunday = datetime(2026, 7, 19, 23, 59, 59, tzinfo=timezone.utc)
    monday = datetime(2026, 7, 20, 0, 0, 0, tzinfo=timezone.utc)

    assert weekly_logging.week_start_utc(sunday) == datetime(
        2026, 7, 13, tzinfo=timezone.utc
    )
    assert weekly_logging.weekly_log_path(Path("logs"), "python", sunday) == Path(
        "logs/python-2026-07-13.log"
    )
    assert weekly_logging.weekly_log_path(Path("logs"), "python", monday) == Path(
        "logs/python-2026-07-20.log"
    )


def test_invalid_retention_values_fall_back_to_ninety_days():
    for value in (None, "", "invalid", 0, "0", -1, "-1"):
        assert weekly_logging.normalize_retention_days(value) == 90

    assert weekly_logging.normalize_retention_days("45") == 45


def test_handler_appends_within_a_week_and_switches_on_monday(tmp_path):
    handler = weekly_logging.WeeklyFileHandler(tmp_path, "python", retention_days=90)

    handler.handle(record("2026-07-13 00:00:00", "Monday entry"))
    handler.handle(record("2026-07-19 23:59:59", "Sunday entry"))
    handler.handle(record("2026-07-20 00:00:00", "Next Monday entry"))
    handler.close()

    first_week = (tmp_path / "python-2026-07-13.log").read_text(encoding="utf-8")
    second_week = (tmp_path / "python-2026-07-20.log").read_text(encoding="utf-8")

    assert "Monday entry" in first_week
    assert "Sunday entry" in first_week
    assert "Next Monday entry" not in first_week
    assert "Next Monday entry" in second_week


def test_handler_prunes_only_expired_matching_monday_archives(tmp_path):
    filenames = (
        "python-2026-04-06.log",
        "python-2026-04-13.log",
        "python-2026-04-07.log",
        "python-2026-02-30.log",
        "python-2026-07-13.log",
        "python-2026-07-20.log",
        "python-invalid.log",
        "python.log",
        "youtube-2026-04-06.log",
    )
    for filename in filenames:
        (tmp_path / filename).write_text(filename, encoding="utf-8")

    handler = weekly_logging.WeeklyFileHandler(tmp_path, "python", retention_days=90)
    handler.handle(record("2026-07-13 12:00:00", "Cleanup entry"))
    handler.close()

    assert not (tmp_path / "python-2026-04-06.log").exists()
    for filename in filenames[1:]:
        assert (tmp_path / filename).exists()


def test_handler_prunes_at_exact_expiry_on_first_same_week_write(tmp_path):
    archive = tmp_path / "python-2026-04-13.log"
    archive.write_text("Archive pending expiry", encoding="utf-8")
    handler = weekly_logging.WeeklyFileHandler(tmp_path, "python", retention_days=90)

    handler.handle(record("2026-07-18 23:59:59", "Before expiry"))
    assert archive.exists()

    handler.handle(record("2026-07-19 00:00:00", "At expiry"))
    handler.close()

    assert not archive.exists()
    assert "At expiry" in (tmp_path / "python-2026-07-13.log").read_text(
        encoding="utf-8"
    )


def test_oversized_retention_uses_dlsite_expiry_date_arithmetic():
    handler = weekly_logging.WeeklyFileHandler(
        PROJECT_ROOT / "storage/framework/testing",
        "python",
        retention_days=10**100,
    )

    class ArchiveDirectory:
        def glob(self, pattern):
            assert pattern == "python-*.log"

            return [Path("python-2026-04-06.log")]

    handler.directory = ArchiveDirectory()

    try:
        with pytest.raises(OverflowError):
            handler._prune_expired_archives(
                datetime(2026, 7, 13, 12, 0, 0, tzinfo=timezone.utc)
            )
    finally:
        handler.close()


def test_concurrent_removal_is_not_reported(monkeypatch, tmp_path, capsys):
    archive = tmp_path / "python-2026-04-06.log"
    archive.write_text("Expired archive", encoding="utf-8")
    handler = weekly_logging.WeeklyFileHandler(tmp_path, "python", retention_days=90)

    original_unlink = Path.unlink

    def remove_then_report_missing(path):
        original_unlink(path)
        raise FileNotFoundError

    monkeypatch.setattr(Path, "unlink", remove_then_report_missing)
    handler.handle(record("2026-07-13 12:00:00", "Concurrent cleanup write"))
    handler.close()

    assert capsys.readouterr().err == ""
    assert not archive.exists()
    assert "Concurrent cleanup write" in (
        tmp_path / "python-2026-07-13.log"
    ).read_text(encoding="utf-8")


def test_cleanup_failure_reports_to_stderr_without_blocking_write(
    monkeypatch, tmp_path, capsys
):
    archive = tmp_path / "python-2026-04-06.log"
    archive.write_text("Expired archive", encoding="utf-8")
    handler = weekly_logging.WeeklyFileHandler(tmp_path, "python", retention_days=90)

    def deny_unlink(_path):
        raise PermissionError("denied")

    monkeypatch.setattr(Path, "unlink", deny_unlink)
    handler.handle(record("2026-07-13 12:00:00", "Write survives cleanup"))
    handler.close()

    assert archive.exists()
    assert "Unable to delete expired weekly log archive" in capsys.readouterr().err
    assert "Write survives cleanup" in (
        tmp_path / "python-2026-07-13.log"
    ).read_text(encoding="utf-8")


def test_setup_logging_keeps_interface_and_format_while_installing_weekly_handler(
    monkeypatch, tmp_path
):
    captured = {}
    monkeypatch.setenv("LOG_RETENTION_DAYS", "45")
    monkeypatch.setattr(logging, "basicConfig", lambda **kwargs: captured.update(kwargs))

    result = common.setup_logging(str(tmp_path / "python.log"))

    assert result is None
    assert captured["level"] == logging.INFO
    assert captured["format"] == "[%(asctime)s UTC] %(message)s"
    assert captured["datefmt"] == "%Y-%m-%d %H:%M:%S"
    handler = captured["handlers"][0]
    assert isinstance(handler, weekly_logging.WeeklyFileHandler)
    assert handler.directory == tmp_path
    assert handler.stem == "python"
    assert handler.retention_days == 45
    handler.close()


def test_setup_logging_defaults_invalid_environment_retention(monkeypatch, tmp_path):
    captured = {}
    monkeypatch.setenv("LOG_RETENTION_DAYS", "invalid")
    monkeypatch.setattr(logging, "basicConfig", lambda **kwargs: captured.update(kwargs))

    common.setup_logging(str(tmp_path / "python.log"))

    handler = captured["handlers"][0]
    assert handler.retention_days == 90
    handler.close()

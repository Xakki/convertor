"""Unit tests for the KeyDB Streams metrics exporter.

All tests use mock Redis — no live KeyDB required.
Run with:  pytest workers/tests/test_metrics_exporter.py -v
"""

from __future__ import annotations

from unittest.mock import MagicMock, patch

import pytest
import redis as redis_lib

import workers.metrics_exporter.exporter as exp


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _make_redis_mock(
    *,
    scan_keys: list[str] | None = None,
    key_types: dict[str, str] | None = None,
    xlens: dict[str, int] | None = None,
    xinfo_groups: dict[str, list[dict]] | None = None,
    xpending_results: dict[str, dict] | None = None,
    xrange_results: dict[str, list] | None = None,
) -> MagicMock:
    """Build a configured Redis mock for exporter tests."""
    m = MagicMock()

    scan_keys = scan_keys or []
    key_types = key_types or {}
    xlens = xlens or {}
    xinfo_groups = xinfo_groups or {}
    xpending_results = xpending_results or {}
    xrange_results = xrange_results or {}

    # SCAN returns (cursor=0, keys) in one shot
    m.scan.return_value = (0, scan_keys)

    m.type.side_effect = lambda k: key_types.get(k, "stream")
    m.xlen.side_effect = lambda k: xlens.get(k, 0)
    m.xinfo_groups.side_effect = lambda k: xinfo_groups.get(k, [])
    m.xpending.side_effect = lambda stream, group: xpending_results.get(stream, {})
    m.xrange.side_effect = lambda stream, start, end, count=None: xrange_results.get(stream, [])

    return m


# ---------------------------------------------------------------------------
# _discover_streams
# ---------------------------------------------------------------------------

def test_discover_streams_filters_non_streams():
    m = _make_redis_mock(
        scan_keys=["conv.document", "conv.image", "conv.dead", "conv.other_type"],
        key_types={
            "conv.document": "stream",
            "conv.image": "stream",
            "conv.dead": "stream",
            "conv.other_type": "hash",
        },
    )
    result = exp._discover_streams(m)
    assert "conv.document" in result
    assert "conv.image" in result
    assert "conv.dead" in result
    assert "conv.other_type" not in result


def test_discover_streams_empty():
    m = _make_redis_mock(scan_keys=[])
    assert exp._discover_streams(m) == []


# ---------------------------------------------------------------------------
# _compute_lag — preferred field path
# ---------------------------------------------------------------------------

def test_compute_lag_uses_lag_field():
    r = MagicMock()
    group_info = {"lag": 42, "last-delivered-id": "0-0"}
    result = exp._compute_lag(r, "conv.document", group_info, xlen=100)
    assert result == 42.0
    r.xrange.assert_not_called()


def test_compute_lag_uses_entries_read_minus_xlen():
    """Redis 7.0 path: 'entries-read' present → lag = xlen - entries-read."""
    r = MagicMock()
    group_info = {"entries-read": 80}
    result = exp._compute_lag(r, "conv.document", group_info, xlen=100)
    assert result == 20.0
    r.xrange.assert_not_called()


def test_compute_lag_clamps_to_zero_when_entries_read_exceeds_xlen():
    """entries-read > xlen (e.g. after stream trim) → lag clamped to 0."""
    r = MagicMock()
    group_info = {"entries-read": 105}
    result = exp._compute_lag(r, "conv.document", group_info, xlen=50)
    assert result == 0.0


# ---------------------------------------------------------------------------
# _compute_lag — XRANGE fallback (KeyDB 6.x path)
# ---------------------------------------------------------------------------

def test_compute_lag_fallback_xrange():
    """KeyDB 6.x: no 'lag' / 'entries-added' → use XRANGE count after last-delivered-id."""
    r = MagicMock()
    fake_entries = [("4-0", {}), ("5-0", {}), ("6-0", {})]
    r.xrange.return_value = fake_entries

    group_info = {"last-delivered-id": "3-0"}  # non-zero → XRANGE path
    result = exp._compute_lag(r, "conv.document", group_info, xlen=10)
    assert result == 3.0
    r.xrange.assert_called_once_with(
        "conv.document", "(3-0", "+", count=exp._LAG_XRANGE_CAP
    )


def test_compute_lag_fallback_no_last_id_returns_xlen():
    """When no entry delivered yet (last-delivered-id='0-0'), lag = full xlen."""
    r = MagicMock()
    group_info = {}  # no lag, no entries-added, no last-delivered-id
    result = exp._compute_lag(r, "conv.audio", group_info, xlen=7)
    assert result == 7.0


def test_compute_lag_fallback_xrange_redis_error_returns_xlen():
    """XRANGE error → degrade gracefully to xlen."""
    r = MagicMock()
    r.xrange.side_effect = redis_lib.RedisError("connection lost")
    group_info = {"last-delivered-id": "123-4"}
    result = exp._compute_lag(r, "conv.audio", group_info, xlen=9)
    assert result == 9.0


# ---------------------------------------------------------------------------
# _collect_stream — gauge values
# ---------------------------------------------------------------------------

def test_collect_stream_sets_gauges():
    """_collect_stream must update length, pending, lag, consumers gauges."""
    fake_group = {
        "name": "convertor",
        "pending": 5,
        "consumers": 2,
        "lag": 10,
        "last-delivered-id": "100-0",
    }
    m = _make_redis_mock(
        xlens={"conv.document": 15},
        xinfo_groups={"conv.document": [fake_group]},
        xpending_results={"conv.document": {}},
    )
    m.xpending_range.return_value = [{"time_since_delivered": 50, "message_id": "99-0"}]

    with patch.object(exp._stream_length, "labels") as mock_len, \
         patch.object(exp._group_pending, "labels") as mock_pend, \
         patch.object(exp._group_lag, "labels") as mock_lag, \
         patch.object(exp._group_consumers, "labels") as mock_cons, \
         patch.object(exp._pending_max_idle, "labels") as mock_idle:

        mock_len.return_value = MagicMock()
        mock_pend.return_value = MagicMock()
        mock_lag.return_value = MagicMock()
        mock_cons.return_value = MagicMock()
        mock_idle.return_value = MagicMock()

        exp._collect_stream(m, "conv.document")

        mock_len.assert_called_with(stream="conv.document")
        mock_len.return_value.set.assert_called_with(15)

        mock_pend.assert_called_with(stream="conv.document", group="convertor")
        mock_pend.return_value.set.assert_called_with(5)

        mock_lag.assert_called_with(stream="conv.document", group="convertor")
        mock_lag.return_value.set.assert_called_with(10.0)

        mock_cons.assert_called_with(stream="conv.document", group="convertor")
        mock_cons.return_value.set.assert_called_with(2)

        mock_idle.return_value.set.assert_called_with(50.0)


def test_collect_stream_skips_other_groups():
    """Groups other than CONSUMER_GROUP must not update gauges."""
    fake_group = {"name": "other-group", "pending": 99, "consumers": 1, "lag": 50}
    m = _make_redis_mock(
        xlens={"conv.image": 99},
        xinfo_groups={"conv.image": [fake_group]},
    )

    with patch.object(exp._group_pending, "labels") as mock_pend:
        exp._collect_stream(m, "conv.image")
        mock_pend.assert_not_called()


def test_collect_stream_sets_pending_max_idle_via_xpending_range():
    """pending_max_idle_ms must use xpending_range, not xpending summary."""
    fake_group = {
        "name": "convertor",
        "pending": 3,
        "consumers": 1,
        "lag": 0,
        "last-delivered-id": "100-0",
    }
    m = _make_redis_mock(
        xlens={"conv.audio": 3},
        xinfo_groups={"conv.audio": [fake_group]},
    )
    m.xpending_range.return_value = [{"time_since_delivered": 12345, "message_id": "98-0"}]

    with patch.object(exp._pending_max_idle, "labels") as mock_idle:
        mock_idle.return_value = MagicMock()
        exp._collect_stream(m, "conv.audio")
        mock_idle.assert_called_with(stream="conv.audio", group="convertor")
        mock_idle.return_value.set.assert_called_with(12345.0)

    m.xpending_range.assert_called_once_with("conv.audio", "convertor", min="-", max="+", count=1)


def test_collect_stream_resets_pending_max_idle_when_pel_empty():
    """Empty PEL must set pending_max_idle_ms=0 (no stale high values)."""
    fake_group = {
        "name": "convertor",
        "pending": 0,
        "consumers": 2,
        "lag": 0,
        "last-delivered-id": "100-0",
    }
    m = _make_redis_mock(
        xlens={"conv.document": 1},
        xinfo_groups={"conv.document": [fake_group]},
    )

    with patch.object(exp._pending_max_idle, "labels") as mock_idle:
        mock_idle.return_value = MagicMock()
        exp._collect_stream(m, "conv.document")
        mock_idle.assert_called_with(stream="conv.document", group="convertor")
        mock_idle.return_value.set.assert_called_with(0.0)

    m.xpending_range.assert_not_called()


def test_collect_stream_xinfo_error_skips_gracefully():
    """xinfo_groups failure must not crash — just skip group metrics."""
    m = MagicMock()
    m.xlen.return_value = 5
    m.xinfo_groups.side_effect = redis_lib.RedisError("err")

    with patch.object(exp._stream_length, "labels") as mock_len:
        mock_len.return_value = MagicMock()
        exp._collect_stream(m, "conv.video")
        mock_len.return_value.set.assert_called_with(5)


# ---------------------------------------------------------------------------
# _scrape — dead-letter gauge
# ---------------------------------------------------------------------------

def test_scrape_sets_dead_letter_gauge():
    m = _make_redis_mock(
        scan_keys=["conv.document", "conv.dead"],
        key_types={"conv.document": "stream", "conv.dead": "stream"},
        xlens={"conv.document": 3, "conv.dead": 7},
        xinfo_groups={"conv.document": [], "conv.dead": []},
    )

    with patch.object(exp._dead_letter, "set") as mock_dl:
        exp._scrape(m)
        mock_dl.assert_called_with(7)


def test_scrape_dead_letter_zero_when_absent():
    """conv.dead not in SCAN results and not present → dead_letter=0."""
    m = _make_redis_mock(
        scan_keys=["conv.document"],
        key_types={"conv.document": "stream"},
        xlens={"conv.document": 2},
        xinfo_groups={"conv.document": []},
    )
    m.type.side_effect = lambda k: "none"  # conv.dead key missing

    with patch.object(exp._dead_letter, "set") as mock_dl:
        exp._scrape(m)
        mock_dl.assert_called_with(0)


# ---------------------------------------------------------------------------
# Scrape error handling — _poll_loop
# ---------------------------------------------------------------------------

def test_poll_loop_increments_error_counter_on_connect_failure():
    """Connection failure must bump scrape_errors and set exporter_up=0."""
    call_count = [0]

    def fake_connect():
        call_count[0] += 1
        if call_count[0] == 1:
            raise redis_lib.ConnectionError("down")
        raise SystemExit(0)

    with patch.object(exp, "_connect", side_effect=fake_connect), \
         patch.object(exp, "POLL_INTERVAL", 0), \
         patch.object(exp._scrape_errors, "inc") as mock_inc, \
         patch.object(exp._exporter_up, "set") as mock_up:
        try:
            exp._poll_loop()
        except SystemExit:
            pass

        mock_inc.assert_called_once()
        # First call must have set exporter_up=0
        assert any(c.args == (0,) for c in mock_up.call_args_list)

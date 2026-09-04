import json
from pathlib import Path

from workers.gateway import ws_server
from workers.host_telemetry.collector import HostTelemetryCollector, validate_host_name


def test_host_name_is_exact_and_strict():
    assert validate_host_name("edge-01.example.com") == "edge-01.example.com"
    assert validate_host_name("Edge-01") is None
    assert validate_host_name("") is None
    assert validate_host_name(None) is None


def test_snapshot_is_bounded_and_rate_limited(tmp_path: Path):
    root = tmp_path / "host"
    (root / "proc").mkdir(parents=True)
    (root / "proc/meminfo").write_text("MemTotal: 100 kB\nMemAvailable: 40 kB\n")
    (root / "proc/loadavg").write_text("2.0 1 1 1/1 1\n")
    (root / "sys/devices/system/cpu").mkdir(parents=True)
    (root / "sys/devices/system/cpu/possible").write_text("0-3\n")
    (root / "root-probe").mkdir()
    allowlist = tmp_path / "allowlist.json"
    allowlist.write_text(json.dumps({"version": 1, "provenance": {"source": "deployment"}, "workers": {"worker-data": "convertor-workers/worker-data"}}))
    clock = iter([1000.0, 1001.0, 1600.0]).__next__
    collector = HostTelemetryCollector("host.example", allowlist, root, clock)
    first = collector.collect()
    assert first["host"] == "host.example"
    assert first["memTotalBytes"] == 102400
    assert first["cpuCount"] == 4
    assert collector.collect() is None
    assert collector.collect() is not None


def test_allowlist_rejects_absolute_and_parent_paths(tmp_path: Path):
    p = tmp_path / "allowlist.json"
    p.write_text(json.dumps({"version": 1, "provenance": {"source": "deployment"}, "workers": {"x": "../proc"}}))
    collector = HostTelemetryCollector("host.example", p, tmp_path)
    try:
        collector.collect()
    except ValueError:
        pass
    else:
        raise AssertionError("unsafe allowlist accepted")


def test_gateway_accepts_asserted_host_shape_without_claiming_identity(monkeypatch):
    monkeypatch.setattr(ws_server.time, "time", lambda: 1000.0)
    snapshot = {
        "contractVersion": 1, "host": "another-known-host.example", "observedAt": 900.0, "freshUntil": 2100.0,
        "source": "host-collector", "scope": "host", "workers": {},
    }
    assert ws_server._validate_host_telemetry(snapshot) == snapshot
    # Shared WORKER_API_TOKEN authenticates the channel only; host is not bound to it.


def test_delivery_uses_gateway_channel_without_internal_token():
    source = Path(__file__).parents[1] / "host_telemetry" / "__main__.py"
    text = source.read_text()
    assert "GATEWAY_WS_URL" in text
    assert "WORKER_API_TOKEN" in text
    assert "GATEWAY_INTERNAL_TOKEN" not in text
    assert "TELEMETRY_URL" not in text


def test_worker_symlink_cannot_escape_collector_root(tmp_path: Path):
    outside = tmp_path / "outside"
    outside.mkdir()
    link = tmp_path / "worker"
    link.symlink_to(outside, target_is_directory=True)
    allowlist = tmp_path / "allowlist.json"
    allowlist.write_text(json.dumps({"version": 1, "provenance": {"source": "deployment"}, "workers": {"x": "worker"}}))

    result = HostTelemetryCollector("host.example", allowlist, tmp_path).collect()

    assert result["workers"]["x"] == {"cpuUsageUsec": None, "memoryBytes": None}


def test_gateway_telemetry_ingress_is_bounded_and_per_host():
    limiter = ws_server.TelemetryIngressLimiter(clock=lambda: 1000.0)
    assert limiter.allow_connection(1000.0)
    assert limiter.allow_frame("host.example", 1000.0)
    assert not limiter.allow_frame("host.example", 1001.0)
    assert limiter.allow_frame("other.example", 1001.0)
    limiter.release_connection()

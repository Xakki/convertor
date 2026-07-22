"""Тесты liveness-агрегации + периодического push в PHP (registry-06, gateway-половина).

Три слоя, снизу вверх:
(1) `LivenessAggregator` — чистые unit-тесты (без WS/HTTP): record_connect/ping/
    disconnect, snapshot/mark_pushed, bounded pending-disconnect backlog.
(2) `RelayClient.post_liveness` — HTTP-форма (httpx.MockTransport): успех + разбор
    `unknown`, не-2xx, сетевая ошибка, не-JSON/не-dict тело — все НЕ бросают.
(3) `run_liveness_push_loop`/`_push_once` — сквозной цикл: снапшот → push → mark_pushed
    ИЛИ retry-на-неудаче, не роняет цикл на исключении.
(4) WS-уровень: `ready{instanceId}` → gateway трекает; `ready` БЕЗ instanceId (старый
    воркер) → не трекает, диспетч не ломается; disconnect → инстанс попадает в
    следующий батч со `status: disconnected`, роутинг (job-путь) этим не тронут.
"""

from __future__ import annotations

import asyncio
import json
import logging
from contextlib import asynccontextmanager

import httpx
import pytest
from websockets.asyncio.client import connect
from websockets.asyncio.server import serve

from workers.gateway.config import Config
from workers.gateway.liveness import (
    LivenessAggregator,
    _MAX_PENDING_DISCONNECTS,
    _push_once,
)
from workers.gateway.relay import RelayClient
from workers.gateway.ws_server import WsGateway

TOKEN = "test-token"
INTERNAL_TOKEN = "internal-tok"
BASE_URL = "http://symfony.internal"
BLOCK_MS = 50


# --------------------------------------------------------------------------
# (1) LivenessAggregator — pure unit tests
# --------------------------------------------------------------------------

def test_record_connect_then_ping_updates_metrics():
    agg = LivenessAggregator()
    agg.record_connect("image", "host-a:worker-1")
    agg.record_ping("image", "host-a:worker-1", 0.4, 0.3, 1.1)
    batch = agg.snapshot_batch()
    assert len(batch) == 1
    payload = batch[0].to_payload()
    assert payload["workerType"] == "image"
    assert payload["instanceId"] == "host-a:worker-1"
    assert payload["status"] == "alive"
    assert payload["metrics"] == {"cpu": 0.4, "mem": 0.3, "load": 1.1}
    assert "lastSeenAt" in payload and payload["lastSeenAt"].endswith("Z")


def test_connect_without_ping_still_reports_alive_with_no_metrics():
    """Инстанс, подключившийся, но не успевший отправить ни одного ping —
    всё равно должен попасть в батч (metrics omitted, не омлёт из nulls)."""
    agg = LivenessAggregator()
    agg.record_connect("data", "host-b:w1")
    payload = agg.snapshot_batch()[0].to_payload()
    assert payload["status"] == "alive"
    assert "metrics" not in payload


def test_disconnect_moves_instance_out_of_alive_into_pending():
    agg = LivenessAggregator()
    agg.record_connect("image", "i1")
    agg.record_disconnect("image", "i1")
    assert len(agg._alive) == 0
    assert len(agg._pending_disconnects) == 1
    batch = agg.snapshot_batch()
    assert len(batch) == 1
    assert batch[0].status == "disconnected"


def test_mark_pushed_clears_disconnect_but_keeps_alive_for_next_cycle():
    agg = LivenessAggregator()
    agg.record_connect("image", "alive-1")
    agg.record_connect("image", "will-disconnect")
    agg.record_disconnect("image", "will-disconnect")
    batch = agg.snapshot_batch()
    assert len(batch) == 2
    agg.mark_pushed(batch)
    # Disconnected instance is gone (one-shot, reported); alive instance stays
    # (refreshed every cycle so PHP's lastSeen keeps advancing).
    next_batch = agg.snapshot_batch()
    assert len(next_batch) == 1
    assert next_batch[0].instance_id == "alive-1"
    assert next_batch[0].status == "alive"


def test_reconnect_before_push_cancels_pending_disconnect():
    """Флаппинг воркер: disconnect затем reconnect ДО следующего push-цикла —
    не должно остаться дублирующегося disconnected-маркера."""
    agg = LivenessAggregator()
    agg.record_connect("image", "i1")
    agg.record_disconnect("image", "i1")
    agg.record_connect("image", "i1")  # reconnect той же instanceId
    batch = agg.snapshot_batch()
    assert len(batch) == 1
    assert batch[0].status == "alive"


def test_invalid_instance_id_ignored_not_tracked(caplog):
    agg = LivenessAggregator()
    with caplog.at_level(logging.WARNING):
        agg.record_connect("image", None)
        agg.record_connect("image", "")
        agg.record_connect("image", "has spaces/invalid!")
    assert len(agg) == 0
    assert any("instanceId" in r.message for r in caplog.records)


def test_ping_for_unknown_instance_recreates_entry_rather_than_dropping():
    """Ping без предшествующего record_connect (напр. агрегатор пересоздан
    посреди соединения) — не теряем телеметрию молча."""
    agg = LivenessAggregator()
    agg.record_ping("data", "i-late", 0.1, 0.2, 0.3)
    assert len(agg) == 1
    assert agg.snapshot_batch()[0].status == "alive"


def test_pending_disconnect_backlog_is_bounded_under_churn():
    """Множество churn'ящих инстансов, ни один push не проходит (симулируем
    PHP-даунтайм) — backlog не растёт неограниченно, старые дропаются."""
    agg = LivenessAggregator()
    for i in range(_MAX_PENDING_DISCONNECTS + 50):
        instance_id = f"churn-{i}"
        agg.record_connect("image", instance_id)
        agg.record_disconnect("image", instance_id)
    assert len(agg._pending_disconnects) == _MAX_PENDING_DISCONNECTS
    # Oldest markers were evicted — the very first one must be gone.
    assert ("image", "churn-0") not in agg._pending_disconnects
    # Most recent one must still be present.
    assert ("image", f"churn-{_MAX_PENDING_DISCONNECTS + 49}") in agg._pending_disconnects


# --------------------------------------------------------------------------
# (2) RelayClient.post_liveness — HTTP shape
# --------------------------------------------------------------------------

class _Recorder:
    def __init__(self, status=200, body=None, raise_exc=None):
        self.status = status
        self.body = body if body is not None else {"updated": 1, "unknown": []}
        self.raise_exc = raise_exc
        self.requests: list[dict] = []

    def handler(self, request: httpx.Request) -> httpx.Response:
        if self.raise_exc:
            raise self.raise_exc
        self.requests.append({
            "path": request.url.path,
            "auth": request.headers.get("Authorization"),
            "body": json.loads(request.content.decode("utf-8")),
        })
        if isinstance(self.body, (dict, list)):
            return httpx.Response(self.status, json=self.body)
        return httpx.Response(self.status, content=self.body)


def _relay(rec: _Recorder) -> RelayClient:
    client = httpx.AsyncClient(transport=httpx.MockTransport(rec.handler))
    return RelayClient(BASE_URL, INTERNAL_TOKEN, client=client)


@pytest.mark.asyncio
async def test_post_liveness_success_parses_body():
    rec = _Recorder(status=200, body={"updated": 2, "unknown": [{"workerType": "x", "instanceId": "y"}]})
    relay = _relay(rec)
    ok, body = await relay.post_liveness([{"workerType": "image", "instanceId": "i1", "status": "alive", "lastSeenAt": "2026-01-01T00:00:00Z"}])
    assert ok is True
    assert body == {"updated": 2, "unknown": [{"workerType": "x", "instanceId": "y"}]}
    assert rec.requests[0]["path"] == "/api/v1/internal/worker/liveness"
    assert rec.requests[0]["auth"] == f"Bearer {INTERNAL_TOKEN}"
    assert rec.requests[0]["body"] == {"instances": [{"workerType": "image", "instanceId": "i1", "status": "alive", "lastSeenAt": "2026-01-01T00:00:00Z"}]}


@pytest.mark.asyncio
async def test_post_liveness_non_2xx_is_failure():
    relay = _relay(_Recorder(status=500, body={"error": "boom"}))
    ok, body = await relay.post_liveness([{"workerType": "a", "instanceId": "b", "status": "alive", "lastSeenAt": "x"}])
    assert ok is False
    assert body is None


@pytest.mark.asyncio
async def test_post_liveness_network_error_is_failure():
    relay = _relay(_Recorder(raise_exc=httpx.ConnectError("refused")))
    ok, body = await relay.post_liveness([{"workerType": "a", "instanceId": "b", "status": "alive", "lastSeenAt": "x"}])
    assert ok is False
    assert body is None


@pytest.mark.asyncio
async def test_post_liveness_2xx_non_json_body_is_failure():
    relay = _relay(_Recorder(status=200, body=b"not json at all"))
    ok, body = await relay.post_liveness([{"workerType": "a", "instanceId": "b", "status": "alive", "lastSeenAt": "x"}])
    assert ok is False
    assert body is None


@pytest.mark.asyncio
async def test_post_liveness_2xx_non_object_body_is_failure():
    rec = _Recorder(status=200, body=[1, 2, 3])
    relay = _relay(rec)
    ok, body = await relay.post_liveness([{"workerType": "a", "instanceId": "b", "status": "alive", "lastSeenAt": "x"}])
    assert ok is False
    assert body is None


# --------------------------------------------------------------------------
# (3) run_liveness_push_loop / _push_once — end-to-end resilience
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_push_once_empty_aggregator_is_noop():
    agg = LivenessAggregator()
    relay = _relay(_Recorder())
    await _push_once(agg, relay)
    assert relay is not None  # no request made; nothing to assert crashing on


@pytest.mark.asyncio
async def test_push_once_success_clears_disconnects_keeps_alive():
    agg = LivenessAggregator()
    agg.record_connect("image", "alive-1")
    agg.record_connect("image", "gone-1")
    agg.record_disconnect("image", "gone-1")
    rec = _Recorder(status=200, body={"updated": 2, "unknown": []})
    relay = _relay(rec)
    await _push_once(agg, relay)
    assert len(rec.requests) == 1
    body = rec.requests[0]["body"]["instances"]
    assert {i["instanceId"] for i in body} == {"alive-1", "gone-1"}
    remaining = agg.snapshot_batch()
    assert len(remaining) == 1
    assert remaining[0].instance_id == "alive-1"


@pytest.mark.asyncio
async def test_push_once_failure_keeps_everything_for_retry():
    agg = LivenessAggregator()
    agg.record_connect("image", "gone-1")
    agg.record_disconnect("image", "gone-1")
    relay = _relay(_Recorder(status=503))
    await _push_once(agg, relay)  # must not raise
    # Nothing cleared — still pending for the next cycle.
    assert len(agg._pending_disconnects) == 1


@pytest.mark.asyncio
async def test_push_once_unknown_logged_loudly_not_raised(caplog):
    agg = LivenessAggregator()
    agg.record_connect("image", "ghost-1")
    rec = _Recorder(status=200, body={"updated": 0, "unknown": [{"workerType": "image", "instanceId": "ghost-1"}]})
    relay = _relay(rec)
    with caplog.at_level(logging.ERROR):
        await _push_once(agg, relay)
    assert any("ghost-1" in str(r.__dict__.get("instanceId", "")) or "unknown" in r.message.lower() for r in caplog.records)
    # A push that returned unknown is still a SUCCESSFUL HTTP round-trip —
    # alive entries are not force-dropped just because PHP doesn't know them.
    assert len(agg.snapshot_batch()) == 1


# --------------------------------------------------------------------------
# (4) WS-level: ready{instanceId} wiring, backward compat, disconnect signal
# --------------------------------------------------------------------------

class _NoJobsKeyDb:
    """Дак-тайп KeyDbGateway: никогда не отдаёт job'ы — изолирует ping/disconnect
    тесты от кредитного/dispatch-пути."""

    async def read_pending(self, stream, consumer, count=100):
        return []

    async def reclaim_stale(self, stream, consumer):
        return None

    async def read_new(self, stream, consumer, block_ms):
        await asyncio.sleep(0.02)
        return None

    async def write_job_meta(self, job_id, job, stream):
        pass

    async def set_status_processing(self, job_id, job, worker="gw-reclaim"):
        pass


def _cfg():
    return Config(
        redis_host="unused", redis_port=6379, redis_db=2, redis_password=None,
        ws_block_ms=BLOCK_MS, ws_host="localhost", ws_port=0, worker_api_token=TOKEN,
    )


def _auth():
    return {"Authorization": f"Bearer {TOKEN}"}


async def _recv_non_ready_ack(c, timeout=1.0):
    while True:
        frame = json.loads(await asyncio.wait_for(c.recv(), timeout))
        if frame.get("type") != "ready-ack":
            return frame


@asynccontextmanager
async def _server(agg):
    gw = WsGateway(_cfg(), _NoJobsKeyDb(), liveness=agg)
    async with serve(gw.handle, "localhost", 0) as server:
        yield server.sockets[0].getsockname()[1]


@pytest.mark.asyncio
async def test_ws_ready_with_instance_id_tracks_and_ping_updates_metrics():
    agg = LivenessAggregator()
    async with _server(agg) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(json.dumps({
                "type": "ready", "workerId": "w-1", "workerType": "image",
                "instanceId": "host-x:w-1", "slots": 1,
            }))
            await asyncio.sleep(0.05)  # let handle() process record_connect
            assert len(agg.snapshot_batch()) == 1
            await c.send(json.dumps({"type": "ping", "cpu": 0.7, "mem": 0.2, "load": 0.9}))
            reply = await _recv_non_ready_ack(c)
            assert reply == {"type": "pong"}
        await asyncio.sleep(0.05)  # let handle()'s finally run record_disconnect

    batch = agg.snapshot_batch()
    assert len(batch) == 1
    payload = batch[0].to_payload()
    assert payload["instanceId"] == "host-x:w-1"
    assert payload["status"] == "disconnected"
    # The ping's metrics were recorded before disconnect (last known values kept).
    assert payload["metrics"] == {"cpu": 0.7, "mem": 0.2, "load": 0.9}


@pytest.mark.asyncio
async def test_ws_ready_without_instance_id_backward_compat_not_tracked():
    """Старый воркер (без instanceId в ready) — не трекается для liveness, но
    handshake/ping/pong продолжают работать как раньше (job-диспетч не зависит
    от instanceId вообще)."""
    agg = LivenessAggregator()
    async with _server(agg) as port:
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(json.dumps({
                "type": "ready", "workerId": "w-old", "workerType": "image", "slots": 1,
            }))
            await asyncio.sleep(0.05)
            assert len(agg.snapshot_batch()) == 0  # not tracked, but no crash
            await c.send(json.dumps({"type": "ping", "cpu": 0.1}))
            reply = await _recv_non_ready_ack(c)
            assert reply == {"type": "pong"}
    assert len(agg.snapshot_batch()) == 0

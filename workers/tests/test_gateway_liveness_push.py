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
(5) registry-09: конверт авторитетного снапшота (snapshot/authoritative/gatewayId),
    окно прогрева, пустой авторитетный снапшот, self-healing `re-register`
    (rate-limit, инстанс не на этом gateway, сбой доставки) и реестр соединений
    ws-сервера (identity-check при реконнекте).
"""

from __future__ import annotations

import asyncio
import json
import logging
from contextlib import asynccontextmanager, suppress

import httpx
import pytest
from websockets.asyncio.client import connect
from websockets.asyncio.server import serve

from workers.gateway.config import Config
from workers.gateway.liveness import (
    LivenessAggregator,
    _MAX_PENDING_DISCONNECTS,
    _push_once,
    run_liveness_push_loop,
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
    """Пустой батч БЕЗ авторитетного снапшота — по-прежнему ни одного POST
    (registry-06 «no empty POSTs»)."""
    agg = LivenessAggregator()
    rec = _Recorder()
    relay = _relay(rec)
    await _push_once(agg, relay)
    assert rec.requests == []


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
    """Без канала re-register (registry-09 `request_reregister=None` — старый
    билд/unit-контекст) `unknown` по-прежнему только логируется и не роняет
    цикл. Уровень WARNING, не ERROR: с появлением self-healing единственный
    оставшийся здесь сценарий — доброкачественная гонка (пуш обогнал
    собственный register воркера)."""
    agg = LivenessAggregator()
    agg.record_connect("image", "ghost-1")
    rec = _Recorder(status=200, body={"updated": 0, "unknown": [{"workerType": "image", "instanceId": "ghost-1"}]})
    relay = _relay(rec)
    with caplog.at_level(logging.WARNING):
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


# --------------------------------------------------------------------------
# (5) registry-09 — authoritative snapshot envelope + self-healing re-register
# --------------------------------------------------------------------------

def _cfg_push(**over):
    """Config с крошечным push-интервалом для тестов самого цикла."""
    base = dict(
        redis_host="unused", redis_port=6379, redis_db=2, redis_password=None,
        ws_block_ms=BLOCK_MS, ws_host="localhost", ws_port=0, worker_api_token=TOKEN,
        liveness_push_interval_s=0.01,
    )
    base.update(over)
    return Config(**base)


@pytest.mark.asyncio
async def test_push_carries_snapshot_envelope():
    """Пуш несёт полный alive-set + конверт snapshot/authoritative/gatewayId —
    именно он разрешает PHP запустить сверку."""
    agg = LivenessAggregator()
    agg.record_connect("image", "i-1")
    agg.record_connect("data", "i-2")
    rec = _Recorder()
    await _push_once(agg, _relay(rec), gateway_id="gw-a", authoritative=True)

    body = rec.requests[0]["body"]
    assert body["snapshot"] is True
    assert body["authoritative"] is True
    assert body["gatewayId"] == "gw-a"
    assert {i["instanceId"] for i in body["instances"]} == {"i-1", "i-2"}


@pytest.mark.asyncio
async def test_empty_authoritative_snapshot_is_still_pushed():
    """Пустой АВТОРИТЕТНЫЙ снапшот — осмысленное утверждение («этот gateway не
    держит ни одного соединения»), его надо доставить, чтобы PHP мог погасить
    молчащие строки. Безопасность пустого снапшота обеспечивает окно тишины на
    стороне PHP, а не отказ его отправлять."""
    agg = LivenessAggregator()
    rec = _Recorder()
    await _push_once(agg, _relay(rec), gateway_id="gw-a", authoritative=True)
    assert len(rec.requests) == 1
    assert rec.requests[0]["body"]["instances"] == []
    assert rec.requests[0]["body"]["authoritative"] is True


@pytest.mark.asyncio
async def test_loop_marks_pushes_non_authoritative_during_warmup():
    """Первые циклы после старта (окно прогрева) идут с authoritative=false —
    воркеры ещё переподключаются, снапшот заведомо неполный."""
    agg = LivenessAggregator()
    agg.record_connect("image", "i-1")
    rec = _Recorder()
    cfg = _cfg_push(liveness_snapshot_warmup_s=3600.0)
    task = asyncio.create_task(run_liveness_push_loop(agg, _relay(rec), cfg))
    await asyncio.sleep(0.08)
    task.cancel()
    with suppress(asyncio.CancelledError):
        await task
    assert rec.requests, "цикл обязан был отправить хотя бы один пуш"
    assert all(r["body"]["authoritative"] is False for r in rec.requests)


@pytest.mark.asyncio
async def test_loop_marks_pushes_authoritative_after_warmup():
    agg = LivenessAggregator()
    agg.record_connect("image", "i-1")
    rec = _Recorder()
    cfg = _cfg_push(liveness_snapshot_warmup_s=0.0)
    task = asyncio.create_task(run_liveness_push_loop(agg, _relay(rec), cfg))
    await asyncio.sleep(0.08)
    task.cancel()
    with suppress(asyncio.CancelledError):
        await task
    assert rec.requests
    assert all(r["body"]["authoritative"] is True for r in rec.requests)


class _ReRegisterSpy:
    def __init__(self, result=True, raise_exc=None):
        self.calls: list[tuple[str, str]] = []
        self._result = result
        self._raise = raise_exc

    async def __call__(self, worker_type: str, instance_id: str) -> bool:
        self.calls.append((worker_type, instance_id))
        if self._raise is not None:
            raise self._raise
        return self._result


@pytest.mark.asyncio
async def test_unknown_instance_triggers_reregister_then_respects_cooldown():
    """Ключевой self-healing сценарий: воркер проиграл гонку register при
    деплое → PHP не знает его строки → gateway адресно просит перерегиться.
    Повторный тот же ответ в пределах cooldown фрейм НЕ шлёт (иначе долбили бы
    воркера каждые 30 с всю жизнь соединения)."""
    agg = LivenessAggregator()
    agg.record_connect("image", "ghost-1")
    rec = _Recorder(body={"updated": 0, "unknown": [{"workerType": "image", "instanceId": "ghost-1"}]})
    relay = _relay(rec)
    spy = _ReRegisterSpy()

    for _ in range(3):
        await _push_once(agg, relay, authoritative=True,
                         request_reregister=spy, reregister_cooldown_s=3600.0)

    assert spy.calls == [("image", "ghost-1")]


@pytest.mark.asyncio
async def test_unknown_instance_reregistered_again_after_cooldown():
    agg = LivenessAggregator()
    agg.record_connect("image", "ghost-1")
    rec = _Recorder(body={"updated": 0, "unknown": [{"workerType": "image", "instanceId": "ghost-1"}]})
    relay = _relay(rec)
    spy = _ReRegisterSpy()

    await _push_once(agg, relay, authoritative=True, request_reregister=spy,
                     reregister_cooldown_s=0.0)
    await _push_once(agg, relay, authoritative=True, request_reregister=spy,
                     reregister_cooldown_s=0.0)

    assert len(spy.calls) == 2


@pytest.mark.asyncio
async def test_unknown_instance_not_connected_here_is_not_nudged(caplog):
    """`unknown` про инстанс, соединения с которым у ЭТОГО gateway нет (напр.
    он висит на другом gateway, либо только что отвалился) — фрейм слать
    некуда и незачем; деградируем до лога, не выдумываем канал."""
    agg = LivenessAggregator()
    rec = _Recorder(body={"updated": 0, "unknown": [{"workerType": "image", "instanceId": "elsewhere-1"}]})
    spy = _ReRegisterSpy()
    with caplog.at_level(logging.WARNING):
        await _push_once(agg, _relay(rec), authoritative=True, request_reregister=spy)
    assert spy.calls == []
    assert any("self-heal" in r.message for r in caplog.records)


@pytest.mark.asyncio
async def test_reregister_send_failure_does_not_break_push_cycle():
    """Сбой доставки контроль-фрейма — телеметрия, а не путь задачи: цикл не
    падает, батч всё равно считается запушенным."""
    agg = LivenessAggregator()
    agg.record_connect("image", "ghost-1")
    agg.record_connect("image", "gone-1")
    agg.record_disconnect("image", "gone-1")
    rec = _Recorder(body={"updated": 0, "unknown": [{"workerType": "image", "instanceId": "ghost-1"}]})
    spy = _ReRegisterSpy(raise_exc=RuntimeError("socket exploded"))

    await _push_once(agg, _relay(rec), authoritative=True, request_reregister=spy)

    assert spy.calls == [("image", "ghost-1")]
    assert agg._pending_disconnects == {}  # mark_pushed отработал


def test_should_request_reregister_gated_by_liveness_and_prunes_dead_keys():
    agg = LivenessAggregator()
    agg.record_connect("image", "i-1")
    assert agg.should_request_reregister("image", "i-1", 3600.0) is True
    assert agg.should_request_reregister("image", "i-1", 3600.0) is False
    # Инстанс, которого нет среди живых — nudge'ить нечем.
    assert agg.should_request_reregister("image", "nope", 3600.0) is False
    # Отключился → его rate-limit-запись становится мусором и вычищается.
    agg.record_disconnect("image", "i-1")
    agg.record_connect("data", "i-2")
    agg.should_request_reregister("data", "i-2", 3600.0)
    assert ("image", "i-1") not in agg._reregister_at


@pytest.mark.asyncio
async def test_ws_request_reregister_delivers_frame_to_that_connection():
    agg = LivenessAggregator()
    gw = WsGateway(_cfg(), _NoJobsKeyDb(), liveness=agg)
    async with serve(gw.handle, "localhost", 0) as server:
        port = server.sockets[0].getsockname()[1]
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as c:
            await c.send(json.dumps({
                "type": "ready", "workerId": "w-1", "workerType": "image",
                "instanceId": "host-x:w-1", "slots": 1,
            }))
            await asyncio.sleep(0.05)
            assert await gw.request_reregister("image", "host-x:w-1") is True
            frame = await _recv_non_ready_ack(c)
            assert frame["type"] == "re-register"
        await asyncio.sleep(0.05)
        # Соединение закрыто → реестр очищен, второй запрос честно False.
        assert await gw.request_reregister("image", "host-x:w-1") is False


@pytest.mark.asyncio
async def test_ws_request_reregister_unknown_instance_is_false():
    gw = WsGateway(_cfg(), _NoJobsKeyDb(), liveness=LivenessAggregator())
    assert await gw.request_reregister("image", "never-seen") is False


@pytest.mark.asyncio
async def test_ws_reconnect_does_not_lose_registry_entry():
    """Реконнект того же инстанса: finally старого соединения не должен снести
    запись НОВОГО (identity-check в реестре соединений)."""
    agg = LivenessAggregator()
    gw = WsGateway(_cfg(), _NoJobsKeyDb(), liveness=agg)
    ready = json.dumps({
        "type": "ready", "workerId": "w-1", "workerType": "image",
        "instanceId": "host-x:w-1", "slots": 1,
    })
    async with serve(gw.handle, "localhost", 0) as server:
        port = server.sockets[0].getsockname()[1]
        first = await connect(f"ws://localhost:{port}", additional_headers=_auth())
        await first.send(ready)
        await asyncio.sleep(0.05)
        async with connect(f"ws://localhost:{port}", additional_headers=_auth()) as second:
            await second.send(ready)
            await asyncio.sleep(0.05)
            await first.close()
            await asyncio.sleep(0.1)  # дать finally первого соединения отработать
            assert await gw.request_reregister("image", "host-x:w-1") is True
            frame = await _recv_non_ready_ack(second)
            assert frame["type"] == "re-register"

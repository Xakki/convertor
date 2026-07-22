"""Общий WS-клиент воркера — единая транспортная база для ВСЕХ воркеров (s1-08, §3/§4/§6.6).

Заменяет прежние транспорты (off-server poll-цикл / on-server прямое чтение Stream+S3)
ОДНИМ постоянным WS-соединением к gateway. Меняется только *транспорт вокруг* обработки
задачи; сама обработка переиспользуется через чистый seam `handle_job`.

Жизненный цикл соединения:
    connect + auth (Bearer WORKER_API_TOKEN в WS-upgrade, §7 a)
      → ready{workerId, workerType, slots, version, cpu, mem, load}
      → приём job → скачивание входа (GET /jobs/{id}/input через Symfony, НЕ прямой S3)
      → handle_job(job, progress) → ResultSignal
      → completion: inline ≤ порога по WS / large → POST /jobs/{id}/result + result{resultKey}
        / fail{error, permanent?}
      → progress ~1/сек ТОЛЬКО пока задача в работе
      → ping + детект N пропущенных pong → reconnect тем же workerId + backoff.

Инварианты (grep-ассертируемы):
- НЕ импортирует и НЕ использует S3 (boto3/botocore/minio) или KeyDB (redis/keydb).
  Вход — только через `GET /jobs/{id}/input`. Воркер не держит S3-креды и не ходит в KeyDB.
- Не блокирует event-loop: reader/ping/progress-циклы + обработка задачи — раздельные
  задачи; долгая (даже CPU-bound через asyncio.to_thread) конвертация не морит pong/progress.
- Временный файл входа чистится после каждой задачи (успех/фейл/отмена-на-дисконнекте).

`workerId` СТАБИЛЕН (config `WORKER_ID`), используется дословно как имя KeyDB-consumer'а —
НИКОГДА не включает PID. На reconnect gateway может передиспетчеризовать in-flight задачу
под тем же workerId (slots=1); дубликат job-фрейма в рамках одного соединения игнорируется,
но при НОВОМ соединении задача переобрабатывается (§6.6 путь «a») — учёт in-flight скоуплен
на соединение, не на клиент.
"""

from __future__ import annotations

import asyncio
import base64
import hashlib
import json
import logging
import os
import re
import shutil
import socket
import uuid
from collections.abc import Awaitable, Callable
from contextlib import suppress
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import urlparse

import httpx
from websockets.asyncio.client import connect as ws_connect
from websockets.exceptions import ConnectionClosed, InvalidStatus

from workers.common.env import getenv_float, getenv_int

logger = logging.getLogger(__name__)


def _compose_version() -> str:
    """Полная версия воркера = APP_VER (+ build-счётчик из gitignored `.i`, если есть).

    §4: базовая APP_VER (напр. "0.1") компонуется со счётчиком сборки `.i` в корне репо
    → "0.1.6". В S1 воркер лишь РЕПОРТИТ version (транспорт-only, gateway не потребляет),
    поэтому если `.i` нет — репортим просто APP_VER.
    """
    base = os.getenv("APP_VER", "0").strip() or "0"
    build = ""
    with suppress(OSError):
        counter = Path(__file__).resolve().parents[2] / ".i"
        if counter.is_file():
            build = counter.read_text(encoding="utf-8").strip()
    return f"{base}.{build}" if build else base


def _load_snapshot() -> tuple[float, float, float]:
    """cpu/mem/load снимок для ready/ping (S1: транспорт-only, сервер только логирует).

    Держим ТРИВИАЛЬНО: load = os.getloadavg()[0] нормирован на число ядер (зажат 0..1);
    cpu/mem = 0.0 best-effort (psutil ради данных, которые пока никто не потребляет, НЕ тянем).
    """
    load = 0.0
    with suppress(OSError, AttributeError):
        ncpu = os.cpu_count() or 1
        load = max(0.0, min(1.0, os.getloadavg()[0] / ncpu))
    return 0.0, 0.0, load


def _default_worker_id() -> str:
    """Фолбэк WORKER_ID, когда env не задан. Должен оставаться СТАБИЛЬНЫМ между
    реконнектами (§6.1 — используется дословно как имя KeyDB-consumer'а; свежий id
    на каждый старт плодит consumer'ов и тормозит резюме in-flight задач). Hostname
    контейнера стабилен на весь его жизненный цикл; случайный суффикс — только
    если hostname недоступен."""
    with suppress(OSError):
        host = socket.gethostname().strip()
        if host:
            return host
    return f"worker-{uuid.uuid4().hex[:12]}"


def _safe_dir_name(job_id: str) -> str:
    """Безопасное имя поддиректории из jobId.

    Оставляем только буквы, цифры, дефис, подчёркивание; ограничиваем длину.
    SHA1-суффикс устраняет коллизии: разные jobId могут дать одинаковое
    sanitized-имя (напр. «a/b» и «a_b» → «a_b»), но SHA1 от оригинала различается.
    """
    safe = re.sub(r"[^A-Za-z0-9_-]", "_", job_id)[:64]
    suffix = hashlib.sha1(job_id.encode()).hexdigest()[:8]
    return f"{safe}-{suffix}"


# --------------------------------------------------------------------------
# Конфиг (централизует env-чтения для ВСЕХ воркеров-WS-клиентов, §8)
# --------------------------------------------------------------------------

# Допустимые типы воркера — самостоятельный whitelist (§4/§6.2), валидируется ДО connect'а.
# NB: раньше комментарий утверждал, что список зеркалит `WorkerController::ALLOWED_TYPES` —
# та PHP-константа УДАЛЕНА 2026-07-03 (`17b1ac8`, вместе с claim-by-type action); сейчас
# у списка нет PHP-аналога. Вывод списка из реестра — отдельная grooming-карточка
# `worker-type-lists-hardcode`, не трогать здесь.
ALLOWED_WORKER_TYPES = ("ai", "document", "image", "audio", "video", "data")

# Контракт `instanceId` (registry-02): непустая строка, ≤128 символов, только
# `[A-Za-z0-9._:-]`. Сервер (PHP) 400-ит при нарушении формы — клиент обязан
# генерировать/санитизировать значение ДО отправки, а не полагаться на валидацию сервера.
_INSTANCE_ID_INVALID_RE = re.compile(r"[^A-Za-z0-9._:-]")
_INSTANCE_ID_MAX_LEN = 128


def _sanitize_instance_id(raw: str) -> str:
    """Свести произвольную строку к контракту `instanceId` register-payload'а."""
    cleaned = _INSTANCE_ID_INVALID_RE.sub("-", raw.strip())
    return (cleaned or "unknown")[:_INSTANCE_ID_MAX_LEN]


@dataclass(frozen=True)
class WsClientConfig:
    worker_id: str            # WORKER_ID — стабильно, дословно = имя KeyDB-consumer'а, без PID
    worker_type: str          # WORKER_TYPE — ai|document|image|audio|video|data
    gateway_ws_url: str       # GATEWAY_WS_URL — wss://…/ws/worker/
    api_base_url: str         # API_BASE_URL — Symfony (GET input / POST large result)
    worker_api_token: str     # WORKER_API_TOKEN — Bearer для WS-upgrade (a) + прямого HTTP (b)
    version: str              # APP_VER (+ .i) — только репортится
    work_dir: Path            # WORK_DIR — куда качаем вход временным файлом
    instance_id_override: str = ""  # WORKER_INSTANCE_ID — оператор пиннит instanceId явно
    slots: int = 1
    ws_result_inline_max: int = 262144
    ws_ping_interval_s: float = 20.0
    ws_progress_interval_s: float = 1.0
    ws_liveness_missed_pings: int = 3
    ws_reconnect_backoff_base_s: float = 1.0
    ws_reconnect_backoff_max_s: float = 30.0
    ws_reconnect_backoff_factor: float = 2.0

    @property
    def api_base(self) -> str:
        """API-корень без хвостового слэша; пути строятся как f'{api_base}/api/v1/...'."""
        return self.api_base_url.rstrip("/")

    def validate(self) -> None:
        """Проверить конфиг ПЕРЕД входом в reconnect-цикл. Мисконфиг фатален: без этих
        полей клиент крутил бы бесконечный reconnect-шторм в никуда (пустой/невалидный
        handshake сервер закрывает 1008, а мы бы бесконечно переподключались)."""
        if not self.worker_id:
            raise ValueError("WORKER_ID пуст — стабильное имя consumer'а обязательно (§6.1)")
        if self.worker_type not in ALLOWED_WORKER_TYPES:
            raise ValueError(
                f"WORKER_TYPE={self.worker_type!r} не из {ALLOWED_WORKER_TYPES} (§6.2)"
            )
        if not self.gateway_ws_url:
            raise ValueError("GATEWAY_WS_URL пуст — некуда подключаться")
        if not self.worker_api_token:
            raise ValueError("WORKER_API_TOKEN пуст — WS-upgrade не аутентифицируется (§7 a)")
        # #4: Предупреждение при наличии пути-компонента в API_BASE_URL — в этом случае
        # все /api/v1/... пути удвоятся (API_BASE_URL/<path>/api/v1/...). API_BASE_URL
        # должен содержать только схему + хост (без пути).
        _parsed = urlparse(self.api_base_url)
        if _parsed.path.strip("/"):
            logger.warning(
                "API_BASE_URL %r содержит path-компонент %r — все worker API пути"
                " начинаются с /api/v1/..., поэтому префикс удвоится. "
                "Установите API_BASE_URL = схема+хост без пути.",
                self.api_base_url,
                _parsed.path,
            )

    @classmethod
    def from_env(
        cls,
        *,
        work_dir: Path | None = None,
        default_gateway_ws_url: str = "",
        default_api_base_url: str = "http://localhost:8080",
    ) -> WsClientConfig:
        """Собрать конфиг из окружения. Идентичность/URL не валидирует (это делает
        `validate()` перед стартом), НО кривой числовой env fail-fast'ит: `getenv_int/
        float` поднимают ValueError на нечисловом значении прямо здесь, при load.

        `work_dir` — если передан явно, WORK_DIR env не читается (единственный источник).

        `default_gateway_ws_url`/`default_api_base_url` — ОПЦИОНАЛЬНЫЕ фолбэки транспорта,
        передаются вызывающим кодом (не хардкодятся здесь!). Причина: этот метод общий для
        ВСЕХ воркеров И devserver'а (`workers/ai/devserver/ws_runner.py`) — если бы прод-URL
        gateway жил тут как дефолт, devserver-контейнер на сервере (worker-режим=выключен,
        но с уже прописанным WORKER_API_TOKEN) начал бы САМ подключаться к прод-gateway и
        принимать боевые задачи — незапланированный второй прод-воркер. Поэтому пустой
        `GATEWAY_WS_URL` без явного дефолта остаётся пустым → `validate()` фейлит запуск
        (см. guard там же). Прод-дефолты передаёт ТОЛЬКО worker-режим (`workers/ai/worker.py`).
        """
        import tempfile

        resolved_work_dir = (
            work_dir
            if work_dir is not None
            else Path(os.getenv("WORK_DIR", tempfile.gettempdir())).resolve()
        )
        return cls(
            worker_id=os.getenv("WORKER_ID", "").strip() or _default_worker_id(),
            worker_type=os.getenv("WORKER_TYPE", ""),
            gateway_ws_url=os.getenv("GATEWAY_WS_URL", "").strip() or default_gateway_ws_url,
            api_base_url=os.getenv("API_BASE_URL", "").strip() or default_api_base_url,
            worker_api_token=os.getenv("WORKER_API_TOKEN", ""),
            version=_compose_version(),
            work_dir=resolved_work_dir,
            instance_id_override=os.getenv("WORKER_INSTANCE_ID", "").strip(),
            slots=getenv_int("WS_SLOTS", 1),
            ws_result_inline_max=getenv_int("WS_RESULT_INLINE_MAX", 262144),
            ws_ping_interval_s=getenv_float("WS_PING_INTERVAL_S", 20.0),
            ws_progress_interval_s=getenv_float("WS_PROGRESS_INTERVAL_S", 1.0),
            ws_liveness_missed_pings=getenv_int("WS_LIVENESS_MISSED_PINGS", 3),
            ws_reconnect_backoff_base_s=getenv_float("WS_RECONNECT_BACKOFF_BASE_S", 1.0),
            ws_reconnect_backoff_max_s=getenv_float("WS_RECONNECT_BACKOFF_MAX_S", 30.0),
            ws_reconnect_backoff_factor=getenv_float("WS_RECONNECT_BACKOFF_FACTOR", 2.0),
        )


# --------------------------------------------------------------------------
# Seam: progress-репортер + сигнал результата (воркер не знает про провод)
# --------------------------------------------------------------------------

class ProgressReporter:
    """Best-effort индикатор прогресса задачи. Обработчик зовёт `.report(percent, stage)`;
    клиент периодически (WS_PROGRESS_INTERVAL_S) шлёт progress-фрейм с ПОСЛЕДНИМ значением,
    ТОЛЬКО пока задача в работе. Если обработчик не репортит — уходит дефолт percent=0."""

    def __init__(self) -> None:
        self._percent = 0
        self._stage: str | None = None

    def report(self, percent: int, stage: str | None = None) -> None:
        try:
            n = int(percent)
        except (TypeError, ValueError):
            n = 0
        self._percent = max(0, min(100, n))
        if stage is not None:
            self._stage = str(stage)

    @property
    def snapshot(self) -> tuple[int, str | None]:
        return self._percent, self._stage


@dataclass(frozen=True)
class ResultSignal:
    """Результат обработки задачи в СЫРОЙ форме (не провод). Транспортный слой сам решает
    inline-vs-large по сырому размеру и кодирует. Конструкторы: `.completed()` / `.failed()`."""

    ok: bool
    path: str | None = None
    data: bytes | None = None
    mime: str | None = None
    ext: str | None = None
    processing_ms: int | None = None
    error: str = ""
    permanent: bool = False

    @classmethod
    def completed(
        cls,
        *,
        path: str | None = None,
        data: bytes | None = None,
        mime: str | None = None,
        ext: str | None = None,
        processing_ms: int | None = None,
    ) -> ResultSignal:
        if path is None and data is None:
            raise ValueError("ResultSignal.completed требует path или data")
        return cls(
            ok=True, path=path, data=data, mime=mime, ext=ext, processing_ms=processing_ms
        )

    @classmethod
    def failed(
        cls, *, error: str, permanent: bool = False, processing_ms: int | None = None
    ) -> ResultSignal:
        return cls(
            ok=False, error=str(error), permanent=bool(permanent), processing_ms=processing_ms
        )

    def raw_size(self) -> int:
        """Размер сырого выхода в байтах БЕЗ чтения файла в память (для выбора ветки
        inline-vs-large): для path — stat, для data — len. Не грузит большой файл в RAM."""
        if self.data is not None:
            return len(self.data)
        if self.path is not None:
            return Path(self.path).stat().st_size
        raise ValueError("ResultSignal без data и path")

    def read_bytes(self) -> bytes:
        """Сырые байты выхода (из data или из файла path). Только для inline-ветки
        (≤ порога) — крупный выход большим путём стримится с диска, не читается в RAM."""
        if self.data is not None:
            return self.data
        if self.path is not None:
            return Path(self.path).read_bytes()
        raise ValueError("ResultSignal без data и path")


# Seam-контракт: конкретный воркер поставляет корутину обработки одной задачи.
# job — dict фрейма `job` с добавленным клиентом `job["_localInput"]` (путь к временному
# файлу входа). Долгую/CPU-bound работу обработчик ОБЯЗАН уводить в asyncio.to_thread —
# иначе он заморозит loop (adapter будущей миграции; база лишь диспатчит его отдельной задачей).
HandleJob = Callable[[dict, ProgressReporter], Awaitable[ResultSignal]]


class _ConnState:
    """Per-connection состояние. Скоуплено на СОЕДИНЕНИЕ (не на клиент): на reconnect —
    новый пустой стейт, поэтому переотправленная под тем же workerId задача (§6.6 путь «a»)
    переобрабатывается, а не глохнет как «дубликат»."""

    def __init__(self, inline_max: int) -> None:
        self.inflight: dict[str, asyncio.Task] = {}       # jobId → задача обработки
        self.progress: dict[str, asyncio.Task] = {}       # jobId → progress-цикл
        self.awaiting_pong = False                        # ждём pong на последний ping?
        self.effective_inline_max: int = inline_max       # адоптируется из ready-ack gateway


class WsClient:
    """Постоянный WS-клиент воркера. `handle_job` — seam обработки одной задачи."""

    # Reason-строки, которые gateway кладёт в close(1008, reason) при auth/handshake-фейле
    # (workers/gateway/ws_server.py: CLOSE_POLICY_VIOLATION=1008, строки 194/238/242/248/251).
    # Держим В ОДНОМ месте — расшифровка причины для лога воркера, без дублирования логики.
    _CLOSE_CAUSE_HINTS: dict[str, str] = {
        "unauthorized": "неверный WORKER_API_TOKEN",
        "malformed ready": "битый ready-фрейм — баг клиента/протокола",
        "expected ready frame": "первый фрейм после connect был не ready — баг клиента/протокола",
        "missing workerId": "пустой WORKER_ID",
        "invalid workerType": "WORKER_TYPE не входит в допустимый список — см. ALLOWED_WORKER_TYPES",
    }

    def __init__(
        self,
        cfg: WsClientConfig,
        handle_job: HandleJob,
        *,
        http_client: httpx.AsyncClient | None = None,
        on_pong: Callable[[], None] | None = None,
        on_reconnect_start: Callable[[], None] | None = None,
        capabilities: dict | None = None,
    ) -> None:
        self._cfg = cfg
        self._handle_job = handle_job
        self._http = http_client          # инжектится в тестах; иначе lazy own (см. _get_http)
        self._own_http = http_client is None
        self._on_pong = on_pong           # необязательный наблюдатель pong-событий
        self._on_reconnect_start = on_reconnect_start  # вызывается ПОСЛЕ обрыва, перед backoff
        self._capabilities = capabilities
        self._stop = asyncio.Event()
        self._ready_ok = False            # был ли успешный connect+ready в текущей попытке
        # Диагностика обрыва: человекочитаемая причина конца ПОСЛЕДНЕЙ сессии —
        # WS close code+reason / HTTP upgrade rejection / exception. Выставляется в
        # `_run_connection`, читается ЕДИНЫМ reconnect-логом в `run()` (не завязано на
        # то, какая именно задача (reader/pinger/send_ready) заметила обрыв первой).
        self._last_close_desc: str | None = None

    # ------------------------------------------------------------------
    # Публичное
    # ------------------------------------------------------------------

    async def run(self) -> None:
        """Внешний цикл: connect → сессия → reconnect с экспоненциальным backoff.

        Любой обрыв/liveness-таймаут → пересоединение. Backoff растёт при подряд-неудачах
        (нет reconnect-шторма) и СБРАСЫВАЕТСЯ только после ПОДТВЕРЖДЁННОГО handshake
        (первый входящий фрейм от сервера, см. `_ready_ok`), а не сразу после send ready —
        иначе сервер, который upgrade-принимает и тут же закрывает, сбрасывал бы backoff."""
        cfg = self._cfg
        try:
            cfg.validate()
        except ValueError as exc:
            logger.critical(
                "ws-client misconfigured, refusing to start (no reconnect storm)",
                extra={"error": str(exc)},
            )
            return
        backoff = cfg.ws_reconnect_backoff_base_s
        try:
            while not self._stop.is_set():
                self._ready_ok = False
                self._last_close_desc = None
                try:
                    await self._run_connection()
                except asyncio.CancelledError:
                    raise
                except Exception as exc:  # noqa: BLE001 — любой сбой сессии → reconnect
                    # `_run_connection` уже сам логирует детально известные причины
                    # (close code/reason, HTTP upgrade reject) и, как правило, НЕ рейзит
                    # их наружу — сюда долетают только неожиданные (DNS/refused/баги).
                    # Фолбэк на случай, если причину распознать не успели.
                    if not self._last_close_desc:
                        self._last_close_desc = f"{type(exc).__name__}: {exc}"
                if self._stop.is_set():
                    break
                # Соединение упало — сразу сигналим наблюдателю (до backoff-сна), чтобы
                # внешний stats показывал connected=false на всё время ожидания переподключения.
                if self._on_reconnect_start is not None:
                    self._on_reconnect_start()
                if self._ready_ok:
                    backoff = cfg.ws_reconnect_backoff_base_s
                # Единая reconnect-строка: причина обрыва (код/reason WS-close, HTTP upgrade
                # reject или exception) + величина следующей паузы. Не vague — всегда с причиной.
                logger.warning(
                    "ws session ended (%s), reconnecting in %.1fs",
                    self._last_close_desc or "причина не распознана, см. предыдущие строки лога",
                    backoff,
                    extra={"workerId": cfg.worker_id},
                )
                # Спим backoff, но прерываемся немедленно на stop().
                with suppress(asyncio.TimeoutError):
                    await asyncio.wait_for(self._stop.wait(), timeout=backoff)
                backoff = min(
                    backoff * cfg.ws_reconnect_backoff_factor,
                    cfg.ws_reconnect_backoff_max_s,
                )
        finally:
            if self._own_http and self._http is not None:
                await self._http.aclose()
                self._http = None

    def stop(self) -> None:
        """Попросить клиент остановиться после текущей сессии (между reconnect'ами)."""
        self._stop.set()

    # ------------------------------------------------------------------
    # Одно соединение
    # ------------------------------------------------------------------

    async def _run_connection(self) -> None:
        """Одна WS-сессия: connect+auth → ready → concurrent reader/ping до обрыва.

        Диагностика (мотивация — WARNING «ws session ended, will reconnect» без ЕДИНОЙ
        детали делал auth/handshake-фейлы недиагностируемыми): на каждом известном пути
        обрыва выставляет `self._last_close_desc` — человекочитаемую причину для ЕДИНОГО
        reconnect-лога в `run()`. Управление реконнектом/backoff НЕ меняется — только what
        we log и когда.
        """
        headers = {"Authorization": f"Bearer {self._cfg.worker_api_token}"}
        try:
            async with ws_connect(
                self._cfg.gateway_ws_url, additional_headers=headers
            ) as ws:
                try:
                    await self._send_ready(ws)
                except ConnectionClosed as exc:
                    # Сервер закрывает соединение ДО чтения ready при auth-фейле (§7 a:
                    # ws_server.py проверяет Bearer СРАЗУ после upgrade, до _handshake) —
                    # это самый частый путь обрыва для неверного WORKER_API_TOKEN.
                    self._last_close_desc = self._note_session_close(ws, exc)
                    return
                # NB: _ready_ok выставляется НЕ здесь, а при первом входящем фрейме сервера
                # (reader) — доказательство, что handshake прошёл, а не upgrade-принят-и-закрыт.
                logger.info(
                    "ws connected, ready sent",
                    extra={
                        "workerId": self._cfg.worker_id,
                        "workerType": self._cfg.worker_type,
                        "slots": self._cfg.slots,
                    },
                )
                state = _ConnState(self._cfg.ws_result_inline_max)
                reader = asyncio.create_task(self._reader_loop(ws, state))
                pinger = asyncio.create_task(self._ping_loop(ws, state))
                register = asyncio.create_task(self._register())
                # stop-waiter: без него stop() не разбудил бы idle keep-alive сессию (ни reader,
                # ни pinger не завершаются) → graceful shutdown/SIGTERM висел бы до обрыва TCP.
                stopper = asyncio.create_task(self._stop.wait())
                try:
                    await asyncio.wait(
                        {reader, pinger, stopper}, return_when=asyncio.FIRST_COMPLETED
                    )
                finally:
                    stopper.cancel()
                    with suppress(asyncio.CancelledError):
                        await stopper
                    await self._teardown(state, reader, pinger, register)
                if not self._stop.is_set():
                    # Сессия завершилась НЕ по нашему stop() — reader/pinger заметили обрыв
                    # (сервер закрыл / TCP оборвался / liveness). ws.close_code/close_reason
                    # уже проставлены протоколом к этому моменту независимо от того, кто
                    # именно из reader/pinger первым это заметил.
                    self._last_close_desc = self._note_session_close(ws)
        except InvalidStatus as exc:
            # HTTP upgrade отклонён ДО WS-рукопожатия (напр. reverse-proxy/балансировщик
            # перед gateway вернул non-101). У websockets.exceptions.InvalidStatus есть
            # `.response.status_code` — используем его, а не голый str(exc).
            status = exc.response.status_code
            if status in (401, 403):
                logger.error(
                    "gateway отклонил upgrade (auth?): HTTP %s — вероятно неверный"
                    " WORKER_API_TOKEN (значение не логируется)",
                    status,
                    extra={"workerId": self._cfg.worker_id},
                )
                self._last_close_desc = f"HTTP upgrade rejected {status} (вероятно неверный WORKER_API_TOKEN)"
            else:
                self._last_close_desc = f"HTTP upgrade rejected {status}"

    def _note_session_close(self, ws, exc: ConnectionClosed | None = None) -> str:
        """Человекочитаемое описание причины конца WS-сессии + (для 1008 unauthorized)
        отдельная ERROR-строка про WORKER_API_TOKEN — самый частый реальный failure mode.

        Источник кода/reason — `ws.close_code`/`ws.close_reason` (websockets проставляет их
        в протокол, как только соединение реально закрылось, независимо от того, кто первым
        это заметил — reader/pinger/send). Фолбэк на `exc.rcvd` — на случай, если `ws`-объект
        по какой-то причине их ещё не выставил, а само исключение уже несёт close-фрейм.
        """
        code = ws.close_code
        reason = ws.close_reason or ""
        if code is None and exc is not None and exc.rcvd is not None:
            code = exc.rcvd.code
            reason = exc.rcvd.reason or reason
        if code is None:
            return "TCP-соединение оборвано без close-фрейма (сеть/таймаут/OOM на другой стороне)"
        if code == 1008:
            if reason == "unauthorized":
                logger.error(
                    "WS-handshake отклонён: close 1008 'unauthorized' — проверьте"
                    " WORKER_API_TOKEN (значение не логируется)",
                    extra={"workerId": self._cfg.worker_id},
                )
            hint = self._CLOSE_CAUSE_HINTS.get(reason, "нарушение протокола handshake")
            return f"gateway закрыл сессию: close 1008 policy violation {reason!r} — вероятно {hint}"
        if code == 1011:
            # 1011 в этом протоколе шлёт САМ клиент при liveness-таймауте (см. _ping_loop) —
            # уже залогировано там отдельной строкой; здесь просто отражаем факт для reconnect-лога.
            return f"close {code} {reason!r} (liveness: пропущены pong'и, инициатор — сам клиент)"
        if code in (1000, 1001):
            return f"gateway закрыл сессию штатно: close {code} {reason!r}"
        return f"close {code} {reason!r}"

    def _instance_id(self) -> str:
        """`instanceId` для register-payload'а (registry-02 контракт).

        Приоритет:
        1. `WORKER_INSTANCE_ID` env (`instance_id_override`) — оператор пиннит явно
           (напр. одинаковое значение по интенту деплоя); санитизируется так же, как
           автогенерируемое значение — оператор не обязан помнить charset контракта.
        2. `<hostname>:<worker_id>` — стабильно между реконнектами (worker_id и hostname
           не меняются в течение жизни процесса/контейнера); различает несколько
           WsClient-инстансов ОДНОГО процесса (ffmpeg `run_dual()` даёт им разные
           worker_id — `<base>-audio`/`<base>-video`, см. `build_dual_configs`); различает
           два ХОСТА с одинаковым `WORKER_ID`, если оператор его явно запиннил одинаково
           (иначе один hostname сам по себе не гарантирует разделение — `worker_id` без
           WORKER_ID env и так уже = hostname, см. `_default_worker_id`).
        """
        override = self._cfg.instance_id_override.strip()
        if override:
            return _sanitize_instance_id(override)
        host = "unknown"
        with suppress(OSError):
            host = socket.gethostname().strip() or "unknown"
        return _sanitize_instance_id(f"{host}:{self._cfg.worker_id}")

    def _build_register_body(self) -> dict:
        caps = self._capabilities or {}
        if "isAi" not in caps:
            # Best-effort register не должен падать на этом — но молчаливый False здесь
            # неотличим от осознанного `isAi: False` (та же хрупкость, что убрали у
            # `worker_type == "ai"`, просто переехавшая на "забыли объявить ключ").
            # WARNING делает пропуск видимым, не меняя эффективное поведение (см. review
            # registry-02: все 6 текущих CAPABILITIES ключ объявляют, это защита на будущее).
            logger.warning(
                "CAPABILITIES missing explicit 'isAi' key — defaulting to False",
                extra={"workerType": self._cfg.worker_type},
            )
        routing_keys: list[str] = list(caps.get("routing_keys", []))
        matrix_raw: dict = caps.get("matrix", {})
        matrix = {
            k: sorted(v) if isinstance(v, (set, frozenset)) else list(v)
            for k, v in matrix_raw.items()
        }
        # matrix_categories (from-формат → "audio"|"document") — читает PHP-реестр
        # (ConversionRegistry::buildMatrixFromCapabilities) для AI-воркеров, чтобы
        # выбрать FileCategory; без него AI-пары молча дропаются.
        matrix_categories: dict = dict(caps.get("matrix_categories", {}))
        return {
            "workerType": self._cfg.worker_type,
            "instanceId": self._instance_id(),
            # isAi — из явного CAPABILITIES["isAi"] воркера (registry-02: раньше выводился
            # из worker_type == "ai", что молча ломается при появлении второго AI-типа).
            # Каждый воркер ОБЯЗАН объявлять isAi явно (см. workers/*/worker.py CAPABILITIES).
            "isAi": bool(caps.get("isAi", False)),
            "streams": routing_keys,
            "routingKeys": routing_keys,
            "matrix": matrix,
            "matrix_categories": matrix_categories,
            "image": None,
            "version": self._cfg.version,
        }

    async def _register(self) -> None:
        """Best-effort self-register on connect. Failure is non-fatal: logged and ignored."""
        if self._capabilities is None:
            return
        try:
            http = await self._get_http()
            url = f"{self._cfg.api_base}/api/v1/worker/register"
            resp = await http.post(
                url, headers=self._auth_headers(),
                json=self._build_register_body(), timeout=5.0,
            )
            resp.raise_for_status()
            logger.info("worker registered", extra={"workerType": self._cfg.worker_type})
        except Exception as exc:  # noqa: BLE001 — non-fatal: any failure → log + continue
            logger.warning("register failed (non-fatal)", extra={"error": str(exc)})

    async def _send_ready(self, ws) -> None:
        """Handshake-фрейм ready (§4): идентичность + маршрутизация + версия + снимок."""
        cpu, mem, load = _load_snapshot()
        await ws.send(json.dumps({
            "type": "ready",
            "workerId": self._cfg.worker_id,
            "workerType": self._cfg.worker_type,
            "slots": self._cfg.slots,
            "version": self._cfg.version,
            "cpu": cpu,
            "mem": mem,
            "load": load,
        }))

    async def _teardown(self, state: _ConnState, *loops: asyncio.Task) -> None:
        """Отменить все задачи соединения (reader/ping + in-flight job + progress) и дождаться.

        Отмена job-задачи прокручивает её finally → чистка временного файла входа."""
        tasks = (
            list(state.inflight.values())
            + list(state.progress.values())
            + list(loops)
        )
        for task in tasks:
            task.cancel()
        for task in tasks:
            with suppress(asyncio.CancelledError, ConnectionClosed):
                await task

    # ------------------------------------------------------------------
    # Reader / ping / progress — раздельные конкурентные задачи (не блокируют друг друга)
    # ------------------------------------------------------------------

    async def _reader_loop(self, ws, state: _ConnState) -> None:
        """Читать входящие фреймы gateway. `job` → диспатч ОТДЕЛЬНОЙ задачей (reader не
        блокируется обработкой); `pong` → снять флаг liveness; прочее — лог."""
        with suppress(ConnectionClosed):
            async for raw in ws:
                try:
                    frame = json.loads(raw)
                except (json.JSONDecodeError, TypeError, ValueError):
                    logger.warning("malformed gateway frame ignored")
                    continue
                if not isinstance(frame, dict):
                    continue
                # Первый входящий фрейм = сервер принял handshake (1008-reject фреймов
                # не шлёт) → соединение реально живое, можно сбрасывать backoff.
                if not self._ready_ok:
                    # Ровно один раз за сессию: явное подтверждение здорового старта,
                    # чтобы в логах не приходилось гадать по отсутствию WARNING'ов.
                    logger.info(
                        "connected to gateway, registered as %s (type %s)",
                        self._cfg.worker_id,
                        self._cfg.worker_type,
                        extra={
                            "workerId": self._cfg.worker_id,
                            "workerType": self._cfg.worker_type,
                        },
                    )
                self._ready_ok = True
                ftype = frame.get("type")
                if ftype == "job":
                    self._on_job(ws, state, frame)
                elif ftype == "pong":
                    state.awaiting_pong = False
                    if self._on_pong is not None:
                        self._on_pong()
                elif ftype == "ready-ack":
                    val = frame.get("inlineMax")
                    if isinstance(val, int) and not isinstance(val, bool) and val > 0:
                        state.effective_inline_max = val
                        logger.debug("gateway inlineMax adopted", extra={"inlineMax": val})
                else:
                    logger.debug("gateway frame ignored", extra={"type": ftype})

    def _on_job(self, ws, state: _ConnState, frame: dict) -> None:
        """Принять фрейм `job`: запустить обработку ОТДЕЛЬНОЙ задачей. Дубликат jobId в
        рамках ЭТОГО соединения (slots=1, at-least-once) — игнор (не крашим, не дублируем)."""
        job_id = frame.get("jobId")
        if not isinstance(job_id, str) or not job_id:
            logger.warning("job frame without jobId ignored")
            return
        if job_id in state.inflight:
            logger.info("duplicate job frame ignored", extra={"jobId": job_id})
            return
        task = asyncio.create_task(self._run_job(ws, state, frame))
        task.add_done_callback(self._job_task_done)  # не терять исключение (fire-and-forget)
        state.inflight[job_id] = task

    @staticmethod
    def _job_task_done(task: asyncio.Task) -> None:
        """Забрать исключение job-задачи, если оно ускользнуло от её собственных обработчиков
        (напр. _send_fail поднял не-ConnectionClosed) — иначе «Task exception never retrieved»."""
        if task.cancelled():
            return
        exc = task.exception()
        if exc is not None:
            logger.error("job task crashed unexpectedly", exc_info=exc)

    async def _ping_loop(self, ws, state: _ConnState) -> None:
        """Слать ping{cpu,mem,load} каждые WS_PING_INTERVAL_S; считать пропущенные pong.

        N подряд пропущенных (WS_LIVENESS_MISSED_PINGS) → соединение мёртво → закрыть ws
        (reader завершится → сессия свернётся → reconnect тем же workerId). Критерий «N
        пропущенных», а не единичный дедлайн — устойчив к WAN-скачкам латентности (§6.6)."""
        missed = 0
        while True:
            cpu, mem, load = _load_snapshot()
            state.awaiting_pong = True
            try:
                await ws.send(json.dumps({
                    "type": "ping", "cpu": cpu, "mem": mem, "load": load,
                }))
            except ConnectionClosed:
                return
            await asyncio.sleep(self._cfg.ws_ping_interval_s)
            if state.awaiting_pong:
                missed += 1
                if missed >= self._cfg.ws_liveness_missed_pings:
                    logger.warning(
                        "liveness: N missed pongs → reconnect",
                        extra={"workerId": self._cfg.worker_id, "missed": missed},
                    )
                    with suppress(ConnectionClosed):
                        await ws.close(1011, "liveness: missed pongs")
                    return
            else:
                missed = 0

    async def _progress_loop(
        self, ws, job_id: str, reporter: ProgressReporter
    ) -> None:
        """Пока задача в работе — слать progress{jobId, percent, stage?} ~раз в интервал
        с ПОСЛЕДНИМ репортнутым значением. Отменяется в _run_job.finally → вне задачи
        (idle) progress НЕ шлётся."""
        while True:
            await asyncio.sleep(self._cfg.ws_progress_interval_s)
            percent, stage = reporter.snapshot
            frame = {"type": "progress", "jobId": job_id, "percent": percent}
            if stage is not None:
                frame["stage"] = stage
            try:
                await ws.send(json.dumps(frame))
            except ConnectionClosed:
                return

    # ------------------------------------------------------------------
    # Обработка одной задачи
    # ------------------------------------------------------------------

    async def _run_job(self, ws, state: _ConnState, frame: dict) -> None:
        """Скачать вход → handle_job → доставить результат/фейл.

        Job-scoped temp dir WORK_DIR/<jobId>/ изолирует вход + выход задачи:
        rmtree в finally убирает всё поддерево (вкл. частичный выход при сбое convert).
        Прочерк S3/KeyDB: вход только по HTTP.
        """
        job_id = frame["jobId"]

        # #2: Обязательные поля — проверить ДО скачивания (малформ → permanent fail, без IO)
        missing = next(
            (f for f in ("conversionId", "sourceFormat", "targetFormat") if not frame.get(f)),
            None,
        )
        if missing:
            logger.warning(
                "malformed job frame: missing %r", missing, extra={"jobId": job_id}
            )
            with suppress(ConnectionClosed):
                await self._send_fail(
                    ws, job_id, f"malformed job frame: missing {missing!r}", permanent=True
                )
            state.inflight.pop(job_id, None)
            return

        # #3: Job-scoped temp dir — путь строим ДО try (без IO), mkdir — ВНУТРИ try.
        # Если mkdir упадёт на кривом WORK_DIR, finally всё равно выполнится и вычистит
        # inflight; retryable fail уйдёт из except.
        job_dir = self._cfg.work_dir / _safe_dir_name(job_id)
        reporter = ProgressReporter()
        signal: ResultSignal | None = None
        ext = Path(str(frame.get("inputKey", ""))).suffix
        local_input = str(job_dir / f"in-{uuid.uuid4().hex}{ext}")
        prog = asyncio.create_task(self._progress_loop(ws, job_id, reporter))
        state.progress[job_id] = prog
        try:
            job_dir.mkdir(parents=True, exist_ok=True)
            frame["_jobDir"] = str(job_dir)  # после mkdir — dir реально существует
            await self._download_input(job_id, local_input)
            frame["_localInput"] = local_input
            signal = await self._handle_job(frame, reporter)
            with suppress(ConnectionClosed):
                await self._deliver(ws, job_id, signal, state.effective_inline_max)
        except asyncio.CancelledError:
            raise
        except Exception as exc:  # noqa: BLE001 — сбой скачивания/обработчика → retryable fail
            logger.exception("job processing failed", extra={"jobId": job_id})
            with suppress(ConnectionClosed):
                await self._send_fail(ws, job_id, f"worker error: {exc}", permanent=False)
        finally:
            prog.cancel()
            with suppress(asyncio.CancelledError, ConnectionClosed):
                await prog
            state.progress.pop(job_id, None)
            state.inflight.pop(job_id, None)
            shutil.rmtree(job_dir, ignore_errors=True)  # вход + выход + частичный output

    async def _deliver(self, ws, job_id: str, signal: ResultSignal, inline_max: int) -> None:
        """Отправить терминал. Ветку inline-vs-large решаем по СЫРОМУ размеру (до base64)
        против inline_max (порог gateway, принятый из ready-ack; до ack — env-дефолт)."""
        if not signal.ok:
            await self._send_fail(ws, job_id, signal.error, signal.permanent, signal.processing_ms)
            return

        # Размер выхода (stat, без чтения в RAM). Нечитаемый/пропавший произведённый выход —
        # вина воркера, НЕ транзиент → permanent fail (иначе idle-reclaim гоняет всю
        # конвертацию до MAX_RETRIES→DLQ впустую). httpx-сбои large-пути (сеть) остаются
        # retryable — они пробрасываются из _upload_large в общий except _run_job.
        try:
            size = signal.raw_size()
        except (OSError, ValueError) as exc:
            await self._send_fail(ws, job_id, f"output unreadable: {exc}", permanent=True)
            return

        if size <= inline_max:
            try:
                raw = signal.read_bytes()
            except (OSError, ValueError) as exc:
                await self._send_fail(ws, job_id, f"output unreadable: {exc}", permanent=True)
                return
            frame = {
                "type": "result",
                "jobId": job_id,
                "inline": base64.b64encode(raw).decode("ascii"),
            }
            if signal.mime:
                frame["mime"] = signal.mime
            if signal.processing_ms is not None:
                frame["processingMs"] = signal.processing_ms
            await ws.send(json.dumps(frame))
            logger.info("result sent inline", extra={"jobId": job_id, "bytes": size})
        else:
            result_key = await self._upload_large(job_id, signal)
            await ws.send(json.dumps({
                "type": "result", "jobId": job_id, "resultKey": result_key,
            }))
            logger.info(
                "result sent large (resultKey)",
                extra={"jobId": job_id, "bytes": size, "resultKey": result_key},
            )

    async def _send_fail(
        self, ws, job_id: str, error: str, permanent: bool, processing_ms: int | None = None
    ) -> None:
        frame = {"type": "fail", "jobId": job_id, "error": error}
        if permanent:
            frame["permanent"] = True
        if processing_ms is not None:
            frame["processingMs"] = processing_ms
        await ws.send(json.dumps(frame))
        logger.info(
            "fail sent", extra={"jobId": job_id, "permanent": permanent, "error": error}
        )

    # ------------------------------------------------------------------
    # HTTP к Symfony (граница b) — вход и large-результат. НИКАКОГО прямого S3.
    # ------------------------------------------------------------------

    async def _get_http(self) -> httpx.AsyncClient:
        """Ленивая инициализация собственного httpx-клиента (в тестах инжектится)."""
        if self._http is None:
            self._http = httpx.AsyncClient()
        return self._http

    def _auth_headers(self) -> dict[str, str]:
        return {"Authorization": f"Bearer {self._cfg.worker_api_token}"}

    async def _download_input(self, job_id: str, dest: str) -> None:
        """Скачать вход в `dest`: GET /api/v1/worker/jobs/{id}/input (Symfony стримит из S3).

        Единственный способ получить вход — воркер прямого доступа к S3 не имеет. Стримим
        чанками на диск (без слёрпа в память). `dest` уже несёт исходное расширение —
        suffix-логика воркера продолжает работать. Частичный файл при обрыве чистит
        вызывающий `_run_job.finally`."""
        http = await self._get_http()
        url = f"{self._cfg.api_base}/api/v1/worker/jobs/{job_id}/input"
        async with http.stream("GET", url, headers=self._auth_headers()) as resp:
            resp.raise_for_status()
            with open(dest, "wb") as fh:
                async for chunk in resp.aiter_bytes():
                    fh.write(chunk)

    async def _upload_large(self, job_id: str, signal: ResultSignal) -> str:
        """Large-путь: POST /api/v1/worker/jobs/{id}/result (multipart, Bearer worker_api).

        Крупный выход СТРИМИТСЯ с диска (file-handle в httpx `files=`), не читается в RAM —
        это и есть смысл большого пути (§3: тяжёлые payload'ы мимо памяти/сокета gateway).

        Symfony сохраняет S3+БД и строит ключ САМ (ResultKeyBuilder, серверная дата). Он
        возвращает {'ok': true} БЕЗ ключа → мы не можем воспроизвести реальный S3-ключ.
        Предпочитаем `outputKey` из ответа (появится — станем forward-compatible), иначе —
        truthy-референс на джобу: gateway на large-пути только truthy-чекает resultKey и
        делает trust-ack (§5), значение он не потребляет. См. отчёт-блокер s1-08.

        `processingMs` едет доп. form-полем (не файлом) — то же имя ключа, что и на
        inline-пути (`_deliver`/`_send_fail`), чтобы контракт с PHP-зоной был единым."""
        http = await self._get_http()
        url = f"{self._cfg.api_base}/api/v1/worker/jobs/{job_id}/result"
        # ext авторитетно на стороне сервера (выводится из targetFormat); имя файла здесь —
        # локальная деталь multipart-запроса, сервер его игнорирует при построении S3-ключа
        # (ResultKeyBuilder использует conversionId + targetFormat, а не filename).
        filename = f"{job_id}.{signal.ext or 'bin'}"
        mime = signal.mime or "application/octet-stream"
        timeout = httpx.Timeout(30.0, read=300.0, write=None)
        data = {"processingMs": str(signal.processing_ms)} if signal.processing_ms is not None else None
        if signal.path is not None:
            with open(signal.path, "rb") as fh:  # стрим с диска, без слёрпа в память
                resp = await http.post(
                    url, headers=self._auth_headers(),
                    files={"file": (filename, fh, mime)}, data=data, timeout=timeout,
                )
        else:
            resp = await http.post(
                url, headers=self._auth_headers(),
                files={"file": (filename, signal.data, mime)}, data=data, timeout=timeout,
            )
        resp.raise_for_status()
        key: str | None = None
        with suppress(ValueError, json.JSONDecodeError):
            body = resp.json()
            if isinstance(body, dict):
                key = body.get("outputKey") or body.get("resultKey") or body.get("key")
        return key or f"jobs/{job_id}/result"

    @staticmethod
    def _cleanup_tmp(path: str | None) -> None:
        if not path:
            return
        with suppress(OSError):
            Path(path).unlink(missing_ok=True)

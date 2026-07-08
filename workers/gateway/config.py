"""Единая точка конфигурации WS-Gateway.

ВСЕ чтения `os.getenv` живут здесь. Остальные модули импортируют `load_config()`
и читают типизированные поля с возвращённого dataclass — без разбросанного
доступа к env. Импорт без побочных эффектов: ничего не читается на уровне модуля,
`load_config()` зовётся из точки входа (по образцу `workers/ai/config.py`).

Секреты (`REDIS_PASSWORD`) в трекаемом env пустые; реальные — в `.env.local`.
"""

from __future__ import annotations

import os
from dataclasses import dataclass


def _getenv_int(name: str, default: int) -> int:
    raw = os.getenv(name)
    if raw is None or raw == "":
        return default
    try:
        return int(raw)
    except ValueError:
        raise ValueError(f"env {name}={raw!r} is not a valid integer")


def _getenv_float(name: str, default: float) -> float:
    raw = os.getenv(name)
    if raw is None or raw == "":
        return default
    try:
        return float(raw)
    except ValueError:
        raise ValueError(f"env {name}={raw!r} is not a valid float")


@dataclass(frozen=True)
class Config:
    # --- KeyDB (db2 — очереди/стримы, см. docs/queue-contract.md) ---
    redis_host: str
    redis_port: int
    redis_db: int
    # Пустой пароль → без AUTH; никогда не инжектим "default:@" с пустым паролем
    # (зеркалит workers/common/stream_consumer.py, строки 32-36).
    redis_password: str | None

    # --- Цикл чтения стрима ---
    # Block-таймаут (мс) для XREADGROUP ... BLOCK <ms>.
    ws_block_ms: int

    # --- WS-сервер (§4/§7) ---
    ws_host: str
    ws_port: int
    # Bearer для WS-handshake (граница a, §7) — ТОТ ЖЕ токен, что pull-API воркера.
    # Пустой → аутентификация невозможна, любое соединение отклоняется (close 1008).
    worker_api_token: str

    # --- Result-relay + порог inline (§5, s1-04) ---
    # Порог inline-результата (байты ДЕКОДИРОВАННОГО payload'а). Больше → воркер
    # обязан идти большим путём (сам POST /jobs/{id}/result); inline свыше порога
    # отклоняется без ack. Дефолт 256 KB.
    ws_result_inline_max: int = 262144
    # Bearer для internal-relay gateway→Symfony (`/api/v1/internal/worker/*`).
    # Секрет — пустой в трекаемом env, реальный в .env.local.
    gateway_internal_token: str = ""
    # База URL Symfony для internal-relay (внутренний сервис в docker-сети).
    symfony_internal_url: str = "http://nginx"

    # --- Idle-reclaim (s1-06, §6.3) ---
    # Интервал между прогонами reclaim-цикла (секунды).
    reclaim_interval_s: float = 60.0
    # Число записей за один XAUTOCLAIM-вызов на тип.
    reclaim_batch: int = 10
    # Idle-пороги (мс) на каждый conv.<type> — ОБЯЗАН превышать макс. время
    # обработки задачи этого типа, иначе медленная-но-живая задача будет
    # переклеймлена и обработана дважды.
    # document: 5 мин, image: 2 мин, audio: 5 мин, video: 10 мин, data: 3 мин, ai: 5 мин
    reclaim_idle_ms_document: int = 300_000
    reclaim_idle_ms_image: int = 120_000
    reclaim_idle_ms_audio: int = 300_000
    reclaim_idle_ms_video: int = 600_000
    reclaim_idle_ms_data: int = 180_000
    reclaim_idle_ms_ai: int = 300_000

    def reclaim_idle_ms_for(self, worker_type: str) -> int:
        """Idle-порог (мс) для данного типа воркера; 300 000 если тип не найден."""
        return getattr(self, f"reclaim_idle_ms_{worker_type}", 300_000)

    # --- Liveness ping/pong (§4/§6.6, s1-05 контракт) ---
    # ⚠ Эти knob'ы ПОТРЕБЛЯЕТ КЛИЕНТ (воркер, s1-08): период ping'а, критерий
    # reconnect и backoff. Сервер (gateway) их НЕ enforce'ит — он лишь отвечает
    # `pong` на `ping`; тайм-аут «N пропущенных ping'ов» детектит сам воркер и сам
    # переподключается под тем же workerId. Живут в общем config, чтобы s1-08 их
    # читал из одного места. Окно reconnect (секунды) держим ≪ idle-порога reclaim
    # (минуты, STALE_IDLE_MS=300000) — иначе reconnect гонялся бы с reclaim (§6.6).
    ws_ping_interval_s: float = 20.0
    # Reconnect по критерию «N пропущенных ping'ов» (не единичный жёсткий дедлайн —
    # WAN-скачки латентности удалённого AI-воркера иначе дают ложный reconnect).
    ws_liveness_missed_pings: int = 3
    # Экспоненциальный backoff reconnect'а (защита от reconnect-шторма).
    ws_reconnect_backoff_base_s: float = 1.0
    ws_reconnect_backoff_max_s: float = 30.0
    ws_reconnect_backoff_factor: float = 2.0


def load_config() -> Config:
    """Собрать Config из окружения. Чистое чтение — не валидирует и не бросает."""
    return Config(
        redis_host=os.getenv("REDIS_HOST", "keydb"),
        redis_port=_getenv_int("REDIS_PORT", 6379),
        redis_db=_getenv_int("REDIS_DB", 2),
        redis_password=os.getenv("REDIS_PASSWORD", "") or None,
        ws_block_ms=_getenv_int("WS_BLOCK_MS", 5000),
        ws_host=os.getenv("WS_HOST", "0.0.0.0"),
        ws_port=_getenv_int("WS_PORT", 8091),
        worker_api_token=os.getenv("WORKER_API_TOKEN", ""),
        ws_result_inline_max=_getenv_int("WS_RESULT_INLINE_MAX", 262144),
        gateway_internal_token=os.getenv("GATEWAY_INTERNAL_TOKEN", ""),
        symfony_internal_url=os.getenv("SYMFONY_INTERNAL_URL", "http://nginx"),
        ws_ping_interval_s=_getenv_float("WS_PING_INTERVAL_S", 20.0),
        ws_liveness_missed_pings=_getenv_int("WS_LIVENESS_MISSED_PINGS", 3),
        ws_reconnect_backoff_base_s=_getenv_float("WS_RECONNECT_BACKOFF_BASE_S", 1.0),
        ws_reconnect_backoff_max_s=_getenv_float("WS_RECONNECT_BACKOFF_MAX_S", 30.0),
        ws_reconnect_backoff_factor=_getenv_float("WS_RECONNECT_BACKOFF_FACTOR", 2.0),
        reclaim_interval_s=_getenv_float("RECLAIM_INTERVAL_S", 60.0),
        reclaim_batch=_getenv_int("RECLAIM_BATCH", 10),
        reclaim_idle_ms_document=_getenv_int("RECLAIM_IDLE_MS_DOCUMENT", 300_000),
        reclaim_idle_ms_image=_getenv_int("RECLAIM_IDLE_MS_IMAGE", 120_000),
        reclaim_idle_ms_audio=_getenv_int("RECLAIM_IDLE_MS_AUDIO", 300_000),
        reclaim_idle_ms_video=_getenv_int("RECLAIM_IDLE_MS_VIDEO", 600_000),
        reclaim_idle_ms_data=_getenv_int("RECLAIM_IDLE_MS_DATA", 180_000),
        reclaim_idle_ms_ai=_getenv_int("RECLAIM_IDLE_MS_AI", 300_000),
    )

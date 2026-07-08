"""Envelope decoder — чистый single-JSON wire-контракт (Option D, §2/§4 spec).

Поле стрима `message` несёт ОДИН чистый JSON плоского camelCase payload'а
задачи (§3) — без внешней обёртки `{body,headers}` стокового redis-messenger.
Разбор = одна `json.loads`.

`parse_message` — чистый декодер: при искажённом входе он ПОДНИМАЕТ исключение.
Poison-safe (XACK+drop) и дроп `conversionId <= 0` живут в ВЫЗЫВАЮЩЕМ коде
(`stream_consumer`), не здесь.
"""

from __future__ import annotations

import json
from typing import Any


def parse_message(fields: dict) -> dict:
    """Декодировать запись стрима `conv.<type>` в dict задачи (§3).

    Одна `json.loads` над полем `message`. Принимает и bytes, и str — как ключи,
    так и значения (redis-py без `decode_responses=True` отдаёт bytes).

    Raises:
        KeyError: если поля `message` нет.
        json.JSONDecodeError: если значение — не валидный JSON.
        TypeError: если декодированное — не JSON-объект.
    """
    if b"message" in fields:
        raw: Any = fields[b"message"]
    elif "message" in fields:
        raw = fields["message"]
    else:
        raise KeyError("stream entry missing 'message' field")

    if isinstance(raw, bytes):
        raw = raw.decode("utf-8")

    job = json.loads(raw)
    if not isinstance(job, dict):
        raise TypeError("stream 'message' is not a JSON object")

    return job

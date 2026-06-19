"""Shared structured-JSON logging for all Python workers.

One JSON object per line to stdout, aligned with the FluentLog fluent-bit
pipeline (parser `json_default` + `cleanup.lua`). Field names are chosen so the
pipeline enriches them without extra parsers:

  datetime   ISO 8601 (UTC)  -> event time (normalize_event_time)
  level_name Monolog name    -> syslog severity + level (infer_level)
  level      Monolog numeric -> becomes level_php
  channel    logger name     -> preserved (Monolog field, SKIP list)
  message    log text         -> short_message
  context    nested dict      -> flattened to context_* (flatten_nested)

Pure stdlib (no third-party deps) so the module can be copied into any worker
image, including the debian-based libreoffice proxy.
"""

from __future__ import annotations

import json
import logging
import re
import sys
from datetime import datetime, timezone

# Python level name -> Monolog level name (fluent-bit infer_level maps these to
# syslog severity). Python has no NOTICE/ALERT/EMERGENCY, so we map onto the
# nearest Monolog rung.
_MONOLOG_NAME = {
    "DEBUG": "DEBUG",
    "INFO": "INFO",
    "WARNING": "WARNING",
    "WARN": "WARNING",
    "ERROR": "ERROR",
    "CRITICAL": "CRITICAL",
    "FATAL": "CRITICAL",
}
_MONOLOG_NUM = {
    "DEBUG": 100,
    "INFO": 200,
    "WARNING": 300,
    "ERROR": 400,
    "CRITICAL": 500,
}

# Attributes present on a vanilla LogRecord — anything else came via ``extra=``
# and is surfaced under ``context``.
_RESERVED = set(vars(logging.makeLogRecord({}))) | {"message", "asctime", "taskName"}

# Telegram bot token: the numeric id is public, the secret half is not.
_TG_TOKEN = re.compile(r"(\d{6,}):[A-Za-z0-9_-]{35,}")


def _redact(text: str) -> str:
    if ":" in text and _TG_TOKEN.search(text):
        return _TG_TOKEN.sub(r"\1:<REDACTED>", text)
    return text


def _redact_obj(value):
    """Recursively mask secrets in extra/context values (str / dict / list)."""
    if isinstance(value, str):
        return _redact(value)
    if isinstance(value, dict):
        return {k: _redact_obj(v) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [_redact_obj(v) for v in value]
    return value


class _RedactSecrets(logging.Filter):
    """Mask known secrets on the root handler — catches propagated child records
    (requests/aiohttp/urllib3) too."""

    def filter(self, record: logging.LogRecord) -> bool:
        try:
            msg = record.getMessage()
        except Exception:
            return True
        if _TG_TOKEN.search(msg):
            record.msg = _redact(msg)
            record.args = ()
        return True


class JsonFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        name = _MONOLOG_NAME.get(record.levelname.upper(), "INFO")
        payload = {
            "datetime": datetime.fromtimestamp(
                record.created, tz=timezone.utc
            ).isoformat(),
            "level_name": name,
            "level": _MONOLOG_NUM[name],
            "channel": record.name,
            "message": _redact(record.getMessage()),
        }
        context = {
            k: _redact_obj(v)
            for k, v in record.__dict__.items()
            if k not in _RESERVED and not k.startswith("_")
        }
        if record.exc_info:
            context["exception"] = _redact(self.formatException(record.exc_info))
        if record.stack_info:
            context["stack"] = _redact(self.formatStack(record.stack_info))
        if context:
            payload["context"] = context
        return json.dumps(payload, ensure_ascii=False, default=str)


_configured = False


def configure_logging(level: int | str = logging.INFO) -> None:
    """Route the root logger to JSON-on-stdout. Idempotent — safe to call from
    both a worker's ``__main__`` and ``BaseWorker.run()``."""
    global _configured
    root = logging.getLogger()
    if _configured:
        root.setLevel(level)
        return
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(JsonFormatter())
    handler.addFilter(_RedactSecrets())
    root.handlers.clear()
    root.addHandler(handler)
    root.setLevel(level)
    _configured = True

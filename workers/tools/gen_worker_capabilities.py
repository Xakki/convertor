#!/usr/bin/env python3
"""Генератор машиночитаемого каталога форматов конвертации из CAPABILITIES воркеров.

Источник правды — захардкоженные CAPABILITIES-словари в `workers/*/worker.py`,
статически извлекаемые модулем `workers/tools/capabilities_ast.py` (тот же
AST-экстрактор, что использует `workers/tests/test_routing_drift.py`).

Выходной файл — `app-symfony/config/catalog/worker_capabilities.json`. Это
СГЕНЕРИРОВАННЫЙ артефакт, руками не редактировать: следующий запуск
`make formats-catalog` молча его перезапишет, а `test_catalog_drift.py`
(`make TEST=1 test-drift`) провалится, если закоммиченная версия разошлась
со свежим извлечением.

Канонические `workerType` берутся из PHP-enum `App\\Enum\\WorkerType`
(`document`/`image`/`audio`/`video`/`data`/`ai`/`api`, см.
`app-symfony/src/Enum/WorkerType.php` и `workers/common/ws_client.py`
`ALLOWED_WORKER_TYPES`), а не выводятся из количества директорий `workers/*`:
  - ffmpeg — единственный воркер, регистрирующий ДВА workerType из одного
    файла (module-level `AUDIO_CAPABILITIES`/`VIDEO_CAPABILITIES`, см.
    `workers/ffmpeg/__main__.py::run_dual` — два независимых WS-подключения).
    `FfmpegWorker.CAPABILITIES` (класс, комбинированный audio+video) НЕ
    регистрируется никаким живым путём — его читает только union-проверка
    routing_keys в `test_routing_drift.py`, в каталог он не попадает.
  - libreoffice регистрируется как workerType `"document"`, а не
    `"libreoffice"` (`WORKER_TYPE=document` в docker-compose.yml,
    `LibreOfficeWorker.CAPABILITIES["routing_keys"] == ["document"]`) — имя
    директории воркера и его workerType здесь расходятся.

`pages` (Apple Pages) — безусловная запись в `workers/libreoffice/worker.py`
`_MATRIX` (официальный document-worker образ всегда содержит libetonyek,
см. `docker/workers/libreoffice.Dockerfile`), поэтому попадает в каталог как
обычный источник. Проверка `_libetonyek_available()` осталась только
execution-time guard'ом внутри `convert()` (permanent-ошибка на job, если
библиотеки нет) — на статический каталог она не влияет.

Каждый блок в выходном массиве — урезанная версия register-payload'а,
который воркер реально шлёт в `POST /api/v1/worker/register`
(`workers/common/ws_client.py::_build_register_body`): обязательные ключи `workerType`,
`isAi`, `streams`, `routingKeys`, `matrix`, `matrix_categories`; декларативные
`executionKind` и public `settings` сохраняются, если воркер их объявил.
Инстанс-специфичные поля живого payload'а (`instanceId`, `image`, `version`,
`host`) в статическом каталоге отсутствуют намеренно — каталогу неоткуда
взять живой инстанс. `streams` и `routingKeys` всегда идентичны (тот же
список, что и в `_build_register_body`) — не два независимых источника.
`matrix_categories` — `{}` для всех неAI-воркеров (не опущено).

Запуск:
  make formats-catalog                                  # перезаписать файл
  PYTHONPATH=. python3 workers/tools/gen_worker_capabilities.py --check   # сравнить, exit 1 при расхождении
"""
from __future__ import annotations

import argparse
import difflib
import json
import sys
from pathlib import Path
from typing import Any

from workers.tools.capabilities_ast import (
    CapabilitiesExtractionError,
    extract_capabilities_ast,
    serialize_capabilities,
)

REPO_ROOT = Path(__file__).resolve().parents[2]
OUTPUT_PATH = REPO_ROOT / "app-symfony" / "config" / "catalog" / "worker_capabilities.json"

# (workerType, worker.py путь относительно workers/, имя CAPABILITIES-словаря в AST).
# Порядок и состав — см. докстринг модуля; не выводить их из числа файлов.
_SOURCES: tuple[tuple[str, str, str], ...] = (
    ("ai", "ai/worker.py", "CAPABILITIES"),
    ("api", "api/worker.py", "CAPABILITIES"),
    ("data", "data/worker.py", "CAPABILITIES"),
    ("image", "image/worker.py", "CAPABILITIES"),
    ("document", "libreoffice/worker.py", "CAPABILITIES"),
    ("audio", "ffmpeg/worker.py", "AUDIO_CAPABILITIES"),
    ("video", "ffmpeg/worker.py", "VIDEO_CAPABILITIES"),
)


def _build_blob(worker_type: str, rel_path: str, name: str) -> dict[str, Any]:
    worker_file = REPO_ROOT / "workers" / rel_path
    raw = extract_capabilities_ast(worker_file, name=name)
    if raw is None:
        raise CapabilitiesExtractionError(
            f"{rel_path}: no module/class-level {name!r} found — expected the "
            f"register-payload source for workerType={worker_type!r} (see "
            "gen_worker_capabilities.py _SOURCES / module docstring)"
        )
    caps = serialize_capabilities(raw, worker_label=f"{worker_type} ({rel_path}:{name})")

    declared_routing_keys = caps["routing_keys"]
    if declared_routing_keys != [worker_type]:
        raise CapabilitiesExtractionError(
            f"{rel_path}:{name} declares routing_keys={declared_routing_keys!r}, expected "
            f"exactly [{worker_type!r}] — either the _SOURCES mapping in "
            "gen_worker_capabilities.py is stale, or the worker's own routing_keys changed; "
            "fix whichever drifted, do not paper over the mismatch here"
        )

    blob = {
        "workerType": worker_type,
        "isAi": bool(raw.get("isAi", False)),
        "streams": declared_routing_keys,
        "routingKeys": declared_routing_keys,
        "matrix": caps["matrix"],
        "matrix_categories": dict(raw.get("matrix_categories", {})),
    }
    if raw.get("executionKind") is not None:
        blob["executionKind"] = raw["executionKind"]
    if raw.get("settings") is not None:
        blob["settings"] = raw["settings"]
    return blob


def generate_catalog() -> list[dict[str, Any]]:
    """Собрать register-payload блоки, отсортированные по workerType."""
    blobs = [_build_blob(worker_type, rel_path, name) for worker_type, rel_path, name in _SOURCES]
    blobs.sort(key=lambda b: b["workerType"])
    return blobs


def render_json(blobs: list[dict[str, Any]]) -> str:
    """Детерминированный JSON: sort_keys сортирует ключи объектов (в т.ч. вложенные
    matrix/matrix_categories), 2-space indent, ensure_ascii=False, trailing newline.
    Порядок элементов массива задаёт generate_catalog() (сортировка по workerType) —
    sort_keys на списки не действует.
    """
    return json.dumps(blobs, indent=2, sort_keys=True, ensure_ascii=False) + "\n"


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--check",
        action="store_true",
        help="сравнить со свежим извлечением, exit 1 при расхождении; ничего не писать",
    )
    args = parser.parse_args(argv)

    try:
        blobs = generate_catalog()
    except CapabilitiesExtractionError as exc:
        print(f"gen_worker_capabilities: {exc}", file=sys.stderr)
        return 1

    rendered = render_json(blobs)

    if args.check:
        if not OUTPUT_PATH.exists():
            print(f"gen_worker_capabilities --check: {OUTPUT_PATH} does not exist", file=sys.stderr)
            return 1
        committed = OUTPUT_PATH.read_text(encoding="utf-8")
        if committed != rendered:
            diff = "".join(
                difflib.unified_diff(
                    committed.splitlines(keepends=True),
                    rendered.splitlines(keepends=True),
                    fromfile=f"{OUTPUT_PATH} (committed)",
                    tofile=f"{OUTPUT_PATH} (fresh)",
                )
            )
            print(
                "gen_worker_capabilities --check: worker_capabilities.json is STALE vs. "
                f"current workers/*/worker.py CAPABILITIES:\n{diff}",
                file=sys.stderr,
            )
            return 1
        print(f"gen_worker_capabilities --check: {OUTPUT_PATH} is up to date")
        return 0

    OUTPUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT_PATH.write_text(rendered, encoding="utf-8")
    print(f"gen_worker_capabilities: wrote {OUTPUT_PATH} ({len(blobs)} workerType blobs)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

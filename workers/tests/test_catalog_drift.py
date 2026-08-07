"""Каталог форматов drift-guard (CNV-71-01): committed JSON vs live CAPABILITIES.

Оборачивает `workers/tools/gen_worker_capabilities.py --check` в pytest:
`app-symfony/config/catalog/worker_capabilities.json` должен ВСЕГДА совпадать
со свежим извлечением из `workers/*/worker.py` CAPABILITIES — тем же
AST-экстрактором, что использует `test_routing_drift.py`
(`workers/tools/capabilities_ast.py`). Расхождение = кто-то поправил матрицу
воркера и забыл `make formats-catalog`.

Тот же принцип, что и у `test_routing_drift.py`/`test_worker_type_drift.py`
(см. их докстринги, история registry-04: ~12 дней зелёного `pytest.skip()`
при полностью сломанной проверке): НИКОГДА `pytest.skip()` на ошибку
источника (файл отсутствует/пуст/невалидный AST) — это тоже провал дрейфа,
не повод молча зазеленить.

Run standalone: `make TEST=1 test-drift` (bare host — только stdlib + pytest,
без docker/php и без worker-рантайм зависимостей, в отличие от
`test_routing_drift.py`, которому нужен живой PHP-реестр).
"""
from __future__ import annotations

import difflib

import pytest

from workers.tools.capabilities_ast import CapabilitiesExtractionError
from workers.tools.gen_worker_capabilities import OUTPUT_PATH, generate_catalog, render_json


def test_catalog_matches_worker_capabilities() -> None:
    """Committed worker_capabilities.json == fresh extraction from workers/*/worker.py."""
    if not OUTPUT_PATH.exists():
        pytest.fail(
            f"{OUTPUT_PATH} does not exist — run `make formats-catalog` to generate it "
            "(it's a committed, generated artifact, never hand-edited)."
        )
    committed = OUTPUT_PATH.read_text(encoding="utf-8")
    if not committed.strip():
        pytest.fail(f"{OUTPUT_PATH} is EMPTY — refusing to compare against nothing.")

    try:
        blobs = generate_catalog()
    except CapabilitiesExtractionError as exc:
        pytest.fail(f"Fresh CAPABILITIES extraction failed: {exc}")

    fresh = render_json(blobs)
    if committed != fresh:
        diff = "".join(
            difflib.unified_diff(
                committed.splitlines(keepends=True),
                fresh.splitlines(keepends=True),
                fromfile=f"{OUTPUT_PATH} (committed)",
                tofile=f"{OUTPUT_PATH} (fresh)",
            )
        )
        pytest.fail(
            f"{OUTPUT_PATH} is STALE vs. current workers/*/worker.py CAPABILITIES — run "
            f"`make formats-catalog` and commit the result:\n{diff}"
        )

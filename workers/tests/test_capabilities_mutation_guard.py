"""Тесты пост-присваивание mutation guard в capabilities_ast.py (CNV-71 hardening).

Живой инцидент (CNV-71-01): `workers/libreoffice/worker.py` мутировал
`_MATRIX["pages"] = _OFFICE_TARGETS` внутри `if _PAGES_IMPORT_OK:` уже ПОСЛЕ
литерала `_MATRIX = {...}` — экстрактор молча терял 8 пар `pages→*` из
каталога, не поднимая ни единой ошибки. Файл с тех пор переписан на плоский
литерал, но сам класс бага (мутация имени, от которого зависит запрошенный
CAPABILITIES, невидимая для AST-эвалюатора) экстрактор раньше не ловил в
принципе — эти тесты фиксируют, что теперь ловит, и что делает это ТОЛЬКО
для имён, которые реально участвуют в вычислении запрошенного `name`
(без ложных срабатываний на постороннюю мутацию).

Bare host venv: только stdlib + pytest, без docker/worker-рантайм зависимостей
— тот же принцип, что и `test_catalog_drift.py`/`test_routing_drift.py`
(см. их докстринги). Часть `make TEST=1 test-drift`.
"""
from __future__ import annotations

from pathlib import Path
from textwrap import dedent

import pytest

from workers.tools.capabilities_ast import (
    CapabilitiesExtractionError,
    extract_capabilities_ast,
    extract_named_module_dict,
)


def _write_worker(tmp_path: Path, source: str) -> Path:
    worker_file = tmp_path / "worker.py"
    worker_file.write_text(dedent(source), encoding="utf-8")
    return worker_file


# ---------------------------------------------------------------------------
# Каждая форма мутации из ТЗ должна ловиться — module-level, module-level
# внутри `if` (guard не защищает), и class-level target.
# ---------------------------------------------------------------------------

def test_subscript_assignment_inside_if_raises(tmp_path: Path) -> None:
    """Ровно живой баг: `_MATRIX["pages"] = …` внутри `if` после литерала."""
    worker_file = _write_worker(tmp_path, """
        _MATRIX = {"docx": {"pdf"}}
        if True:
            _MATRIX["pages"] = {"pdf"}
        CAPABILITIES = {"matrix": _MATRIX}
        """)
    with pytest.raises(CapabilitiesExtractionError) as exc_info:
        extract_capabilities_ast(worker_file, name="CAPABILITIES")
    msg = str(exc_info.value)
    assert f"{worker_file}:4:" in msg  # номер строки самой мутации, не литерала
    assert "_MATRIX" in msg
    assert "subscript" in msg


def test_subscript_assignment_on_target_itself_raises(tmp_path: Path) -> None:
    """Мутация САМОГО запрошенного имени после его присваивания — тоже мутация."""
    worker_file = _write_worker(tmp_path, """
        CAPABILITIES = {"matrix": {"docx": {"pdf"}}}
        CAPABILITIES["extra"] = "x"
        """)
    with pytest.raises(CapabilitiesExtractionError) as exc_info:
        extract_capabilities_ast(worker_file, name="CAPABILITIES")
    assert "CAPABILITIES" in str(exc_info.value)


def test_nested_subscript_chain_resolves_to_base_name(tmp_path: Path) -> None:
    """`CAPABILITIES["matrix"]["pages"] = …` — база `CAPABILITIES`, не только 1 уровень."""
    worker_file = _write_worker(tmp_path, """
        CAPABILITIES = {"matrix": {"docx": {"pdf"}}}
        CAPABILITIES["matrix"]["pages"] = {"pdf"}
        """)
    with pytest.raises(CapabilitiesExtractionError) as exc_info:
        extract_capabilities_ast(worker_file, name="CAPABILITIES")
    assert "CAPABILITIES" in str(exc_info.value)


def test_augmented_assignment_raises(tmp_path: Path) -> None:
    worker_file = _write_worker(tmp_path, """
        _MATRIX = {"docx": {"pdf"}}
        _MATRIX |= {"pages": {"pdf"}}
        CAPABILITIES = {"matrix": _MATRIX}
        """)
    with pytest.raises(CapabilitiesExtractionError) as exc_info:
        extract_capabilities_ast(worker_file, name="CAPABILITIES")
    msg = str(exc_info.value)
    assert "_MATRIX" in msg
    assert "augmented assignment" in msg


@pytest.mark.parametrize(
    ("method", "call_args"),
    [
        ("update", '{"pages": {"pdf"}}'),
        ("setdefault", '"pages", {"pdf"}'),
        ("pop", '"docx"'),
        ("add", '"pages"'),
    ],
)
def test_mutating_method_call_raises(tmp_path: Path, method: str, call_args: str) -> None:
    worker_file = _write_worker(
        tmp_path,
        f"""
        _MATRIX = {{"docx": {{"pdf"}}}}
        _MATRIX.{method}({call_args})
        CAPABILITIES = {{"matrix": _MATRIX}}
        """,
    )
    with pytest.raises(CapabilitiesExtractionError) as exc_info:
        extract_capabilities_ast(worker_file, name="CAPABILITIES")
    msg = str(exc_info.value)
    assert "_MATRIX" in msg
    assert f".{method}(" in msg


def test_mutating_call_as_assignment_value_raises(tmp_path: Path) -> None:
    """`targets = _MATRIX.setdefault(...)` — мутирующий вызов как ЗНАЧЕНИЕ
    присваивания, не отдельным statement'ом. Реалистичный идиом
    dict-of-sets: `.setdefault(k, set()).add(v)` — цепочка, где base
    резолвится через ВНУТРЕННИЙ вызов `.setdefault(...)`, а не внешний.
    """
    worker_file = _write_worker(tmp_path, """
        _MATRIX = {"docx": {"pdf"}}
        _leftover = _MATRIX.setdefault("pages", set())
        _MATRIX.setdefault("epub", set()).add("pdf")
        CAPABILITIES = {"matrix": _MATRIX}
        """)
    with pytest.raises(CapabilitiesExtractionError) as exc_info:
        extract_capabilities_ast(worker_file, name="CAPABILITIES")
    msg = str(exc_info.value)
    assert "_MATRIX" in msg
    assert "setdefault" in msg


def test_dict_comp_dependency_shape_raises(tmp_path: Path) -> None:
    """Форма, реально используемая `workers/ffmpeg/worker.py` для
    AUDIO_CAPABILITIES/VIDEO_CAPABILITIES: `{k: v for k, v in SRC.items() if
    k in FILTER}`. Мутация `SUPPORTED` (источника comprehension'а) после его
    литерала должна флагаться так же, как и прямая ссылка по имени.
    """
    worker_file = _write_worker(tmp_path, """
        SUPPORTED = {"mp3": {"wav"}, "wav": {"mp3"}}
        if True:
            SUPPORTED["ogg"] = {"mp3"}
        _AUDIO_INPUTS = {"mp3", "wav", "ogg"}
        AUDIO_CAPABILITIES = {
            "matrix": {k: v for k, v in SUPPORTED.items() if k in _AUDIO_INPUTS},
        }
        """)
    with pytest.raises(CapabilitiesExtractionError) as exc_info:
        extract_capabilities_ast(worker_file, name="AUDIO_CAPABILITIES")
    assert "SUPPORTED" in str(exc_info.value)


def test_del_subscript_raises(tmp_path: Path) -> None:
    worker_file = _write_worker(tmp_path, """
        _MATRIX = {"docx": {"pdf"}, "extra": {"pdf"}}
        del _MATRIX["extra"]
        CAPABILITIES = {"matrix": _MATRIX}
        """)
    with pytest.raises(CapabilitiesExtractionError) as exc_info:
        extract_capabilities_ast(worker_file, name="CAPABILITIES")
    assert "_MATRIX" in str(exc_info.value)


def test_module_level_direct_extractor_also_raises(tmp_path: Path) -> None:
    """`extract_named_module_dict` — тот же guard, вызванный напрямую."""
    worker_file = _write_worker(tmp_path, """
        _MATRIX = {"docx": {"pdf"}}
        _MATRIX.update({"pages": {"pdf"}})
        CAPABILITIES = {"matrix": _MATRIX}
        """)
    with pytest.raises(CapabilitiesExtractionError) as exc_info:
        extract_named_module_dict(worker_file, "CAPABILITIES")
    assert "_MATRIX" in str(exc_info.value)


def test_class_level_target_flags_module_level_mutation(tmp_path: Path) -> None:
    """Class-level CAPABILITIES, ссылающийся на мутированное module-level имя."""
    worker_file = _write_worker(tmp_path, """
        _MATRIX = {"docx": {"pdf"}}
        if True:
            _MATRIX["pages"] = {"pdf"}


        class Worker:
            CAPABILITIES = {"matrix": _MATRIX}
        """)
    with pytest.raises(CapabilitiesExtractionError) as exc_info:
        extract_capabilities_ast(worker_file, name="CAPABILITIES")
    assert "_MATRIX" in str(exc_info.value)


def test_class_level_local_mutation_raises(tmp_path: Path) -> None:
    """Мутация класс-локального имени (а не только module-level) тоже ловится."""
    worker_file = _write_worker(tmp_path, """
        class Worker:
            _MATRIX = {"docx": {"pdf"}}
            _MATRIX["pages"] = {"pdf"}
            CAPABILITIES = {"matrix": _MATRIX}
        """)
    with pytest.raises(CapabilitiesExtractionError) as exc_info:
        extract_capabilities_ast(worker_file, name="CAPABILITIES")
    assert "_MATRIX" in str(exc_info.value)


# ---------------------------------------------------------------------------
# Отрицательные случаи — не должно быть ложных срабатываний.
# ---------------------------------------------------------------------------

def test_unrelated_module_level_mutation_does_not_raise(tmp_path: Path) -> None:
    """Мутация имени, НЕ участвующего в CAPABILITIES, — не повод падать."""
    worker_file = _write_worker(tmp_path, """
        _UNRELATED = {"foo": 1}
        _UNRELATED["bar"] = 2
        _UNRELATED.update({"baz": 3})
        _UNRELATED |= {"qux": 4}

        CAPABILITIES = {"matrix": {"docx": {"pdf"}}}
        """)
    result = extract_capabilities_ast(worker_file, name="CAPABILITIES")
    assert result == {"matrix": {"docx": {"pdf"}}}


def test_mutation_before_reassignment_of_target_name_does_not_raise(tmp_path: Path) -> None:
    """Мутация имени, которое ПОЗЖЕ переприсвоено литералом (не участвует в
    финальном значении, потому что финальный CAPABILITIES его больше не
    использует), не должна флагаться — только то, что реально входит в
    замыкание запрошенного имени.
    """
    worker_file = _write_worker(tmp_path, """
        _SCRATCH = {"a": 1}
        _SCRATCH["b"] = 2  # мутирует _SCRATCH, но CAPABILITIES его не использует

        CAPABILITIES = {"matrix": {"docx": {"pdf"}}}
        """)
    result = extract_capabilities_ast(worker_file, name="CAPABILITIES")
    assert result == {"matrix": {"docx": {"pdf"}}}

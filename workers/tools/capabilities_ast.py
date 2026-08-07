"""AST-извлечение CAPABILITIES-словарей воркеров без импорта их модулей.

Источник правды для формата пар (from→to) и isAi — захардкоженные матрицы в
`workers/*/worker.py`: module-level `CAPABILITIES` (ai), class-level
`CAPABILITIES` (data/image/libreoffice, и ffmpeg — но там это КОМБИНИРОВАННЫЙ
audio+video словарь, который никто не регистрирует напрямую, см. ниже), и
module-level `AUDIO_CAPABILITIES`/`VIDEO_CAPABILITIES` в ffmpeg — единственном
воркере, который регистрирует ДВА разных `workerType` ("audio"/"video") из
одного файла (`workers/ffmpeg/__main__.py::run_dual` шлёт их раздельными
WsClient-подключениями). `FfmpegWorker.CAPABILITIES` (класс) не используется
никаким register-путём в проде — его читает только union-проверка
routing_keys в `test_routing_drift.py`, каталогу (`gen_worker_capabilities.py`)
он не нужен.

Извлечение — статическое (модуль `ast`), НЕ import: воркеры тянут
httpx/websockets/Pillow/…, которых нет в голом host-venv CI (`.venv-ci` —
только pytest). Общий модуль для `workers/tests/test_routing_drift.py`
(drift-guard) и `workers/tools/gen_worker_capabilities.py` (генератор
каталога). Живёт в `workers/tools/`, а не `workers/common/` — `common/`
пакуется в образы воркеров рантайма (`context: .` в docker-compose.yml), это
же — build-time инструмент.

Строгость: узел AST, который парсер не умеет вычислить ВНУТРИ запрошенного
`name` (module-level или class-level), ВСЕГДА приводит к
`CapabilitiesExtractionError` с путём файла и именем неподдержанной
конструкции — никогда не к тихому пропуску записи (см. историю registry-04:
~12 дней зелёного `pytest.skip()` при полностью сломанной проверке — то же
самое семейство бага). Единственное намеренное исключение — НЕЦЕЛЕВЫЕ
module-level присваивания (имя ≠ запрошенное `name`): они молча
игнорируются, если невычислимы это ожидаемо, в файле воркера могут быть
любые другие вычисления (импорты, служебные константы и т.п.), которые не
входят в граф CAPABILITIES.

Пост-присваивание мутации (CNV-71 hardening): эвалюатор выше читает КАЖДОЕ
имя как значение на момент его `Name`-присваивания (`X = <литерал>`) — он не
умеет заметить последующие `X[k] = v`, `X |= …`, `X.update(...)` и т.п.,
особенно если они спрятаны внутри `if`/`for`/`try` (см. `_gather_scope`).
Раньше такая мутация просто ИСЧЕЗАЛА из графа CAPABILITIES без единой
ошибки (живой инцидент: `_MATRIX["pages"] = _OFFICE_TARGETS` в
`workers/libreoffice/worker.py` внутри `if _PAGES_IMPORT_OK:` — 8 пар
`pages→*` пропадали из каталога молча). Поэтому отдельный проход
(`_gather_scope` + `_closure` + `_raise_if_mutation_feeds_target`) строит
синтаксический граф зависимостей "имя → имена в его же RHS" для
module-level и (при class-level извлечении) для тела ЦЕЛЕВОГО класса,
берёт транзитивное замыкание от запрошенного `name` и его собственного
RHS, и падает `CapabilitiesExtractionError`, если хоть одна найденная
мутация целится в имя из этого замыкания — НЕЗАВИСИМО от того, обёрнута ли
она в `if` (guard не имеет значения: статический анализатор не умеет его
вычислить). Обнаруживаемые формы: subscript/attribute-присваивание
(`X[k]=v`, `X.attr=v`, ЛЮБАЯ глубина вложенности — `X[a][b]=v` тоже
резолвится к базовому `X`), augmented assignment (`X |= …`), `del X[...]`,
и вызов мутирующего метода (`update`/`setdefault`/`pop`/`popitem`/`clear`/
`add`/`discard`/`remove`/`append`/`extend`/`insert`/`__setitem__`/
`__delitem__`) — ЛЮБОЙ, а не только statement-level (`X.update(...)` как
отдельная строка): вызов ищется рекурсивно внутри любого выражения
(значение присваивания, `if`/`while`-условие, `for`-итератор и т.п.), так
что `targets = X.setdefault(...)` и цепочка `X.setdefault(k, set()).add(v)`
(где мутирующим оказывается именно вложенный `.setdefault`, а не внешний
`.add`, чей объект-приёмник уже не `X`) ловятся так же, как и отдельностоящий
вызов. Имена, НЕ входящие в замыкание (мутация посторонней, не участвующей
в `name` переменной), намеренно не флагуются — иначе легитимные воркеры,
мутирующие какое-то другое состояние вне графа CAPABILITIES, ловили бы
ложные срабатывания. Обход НЕ спускается внутрь `def`/`lambda`/вложенных
`class` — тело функции не исполняется на этапе статического разбора.
Известное ограничение (не покрыто намеренно): межпроцедурная мутация —
`def _augment(m): m["x"] = …` затем `_augment(X)` — не видна экстрактору,
это вне охвата статического AST-анализа без исполнения кода.
"""
from __future__ import annotations

import ast
from pathlib import Path
from typing import Any

REPO_ROOT = Path(__file__).resolve().parents[2]


class CapabilitiesExtractionError(Exception):
    """AST не смог статически вычислить запрошенный CAPABILITIES-граф."""


def _rel(path: Path) -> str:
    try:
        return str(path.relative_to(REPO_ROOT))
    except ValueError:
        return str(path)


# ---------------------------------------------------------------------------
# Node evaluator — narrow subset used by CAPABILITIES / matrices
# ---------------------------------------------------------------------------

def eval_caps_node(node: ast.AST, env: dict[str, Any]) -> Any:
    """Вычислить узкое подмножество AST-узлов, используемых в CAPABILITIES.

    Поддерживается: константы, `Name` (резолвится из *env*), list/tuple/set/
    dict литералы, set-union через `|` (BitOr), `set(...)`, унарные +/-, и
    ОГРАНИЧЕННАЯ dict-comprehension вида
    `{k: v for k, v in SRC.items() if k in FILTER}` (единственная конструкция
    ffmpeg AUDIO_CAPABILITIES/VIDEO_CAPABILITIES —
    `workers/ffmpeg/worker.py:117,122`). Всё прочее — `ValueError` с именем
    типа узла; вызывающий обязан довести это до `CapabilitiesExtractionError`
    с путём файла, а не проглотить (кроме best-effort резолва посторонних
    имён — см. `try_store_name`).
    """
    if isinstance(node, ast.Constant):
        return node.value
    if isinstance(node, ast.Name):
        if node.id not in env:
            raise ValueError(f"unresolved name {node.id!r}")
        return env[node.id]
    if isinstance(node, ast.Set):
        return {eval_caps_node(elt, env) for elt in node.elts}
    if isinstance(node, ast.List):
        return [eval_caps_node(elt, env) for elt in node.elts]
    if isinstance(node, ast.Tuple):
        return tuple(eval_caps_node(elt, env) for elt in node.elts)
    if isinstance(node, ast.Dict):
        out: dict[Any, Any] = {}
        for key_node, val_node in zip(node.keys, node.values, strict=True):
            if key_node is None:
                raise ValueError("dict **unpack not supported in CAPABILITIES graph")
            out[eval_caps_node(key_node, env)] = eval_caps_node(val_node, env)
        return out
    if isinstance(node, ast.BinOp) and isinstance(node.op, ast.BitOr):
        return eval_caps_node(node.left, env) | eval_caps_node(node.right, env)
    if isinstance(node, ast.UnaryOp) and isinstance(node.op, ast.UAdd):
        return +eval_caps_node(node.operand, env)
    if isinstance(node, ast.UnaryOp) and isinstance(node.op, ast.USub):
        return -eval_caps_node(node.operand, env)
    if (
        isinstance(node, ast.Call)
        and isinstance(node.func, ast.Name)
        and node.func.id == "set"
    ):
        if not node.args:
            return set()
        if len(node.args) == 1:
            return set(eval_caps_node(node.args[0], env))
        raise ValueError("set() call with >1 positional arg not supported")
    if isinstance(node, ast.DictComp):
        return _eval_dict_comp(node, env)
    raise ValueError(f"unsupported AST node {type(node).__name__}")


def _eval_dict_comp(node: ast.DictComp, env: dict[str, Any]) -> dict[Any, Any]:
    """Узкая поддержка `{k: v for k, v in SRC.items() if k in FILTER}`.

    Это ЕДИНСТВЕННАЯ форма dict-comprehension, которую использует
    `workers/ffmpeg/worker.py` для AUDIO_CAPABILITIES/VIDEO_CAPABILITIES
    (фильтрация общей матрицы SUPPORTED по набору входных форматов). Всё, что
    выходит за эту форму, — `ValueError` с описанием отклонения; не расширять
    молча, при появлении новой формы — сузить проверку под неё явно (см.
    докстринг модуля).
    """
    if len(node.generators) != 1:
        raise ValueError("dict comprehension with != 1 `for` clause not supported")
    gen = node.generators[0]
    if gen.is_async:
        raise ValueError("async `for` in dict comprehension not supported")

    it = gen.iter
    if not (
        isinstance(it, ast.Call)
        and not it.args
        and not it.keywords
        and isinstance(it.func, ast.Attribute)
        and it.func.attr == "items"
        and isinstance(it.func.value, ast.Name)
    ):
        raise ValueError(
            "dict comprehension source must be `NAME.items()` with no arguments"
        )
    source = eval_caps_node(it.func.value, env)
    if not isinstance(source, dict):
        raise ValueError(
            f"dict comprehension source {it.func.value.id!r} did not resolve to a dict"
        )

    target = gen.target
    if not (
        isinstance(target, ast.Tuple)
        and len(target.elts) == 2
        and all(isinstance(e, ast.Name) for e in target.elts)
    ):
        raise ValueError("dict comprehension target must be exactly `k, v`")
    key_name = target.elts[0].id  # type: ignore[union-attr]
    val_name = target.elts[1].id  # type: ignore[union-attr]

    if len(gen.ifs) > 1:
        raise ValueError("dict comprehension with >1 `if` clause not supported")
    filter_set: Any = None
    if gen.ifs:
        cond = gen.ifs[0]
        if not (
            isinstance(cond, ast.Compare)
            and isinstance(cond.left, ast.Name)
            and len(cond.ops) == 1
            and isinstance(cond.ops[0], ast.In)
            and len(cond.comparators) == 1
        ):
            raise ValueError(
                "dict comprehension `if` clause must be `NAME in NAME` membership test"
            )
        if cond.left.id != key_name:
            raise ValueError("dict comprehension `if` clause must test the key variable")
        filter_set = eval_caps_node(cond.comparators[0], env)

    if not (isinstance(node.key, ast.Name) and node.key.id == key_name):
        raise ValueError("dict comprehension key expression must be the bare key var")
    if not (isinstance(node.value, ast.Name) and node.value.id == val_name):
        raise ValueError("dict comprehension value expression must be the bare value var")

    result: dict[Any, Any] = {}
    for k, v in source.items():
        if filter_set is not None and k not in filter_set:
            continue
        result[k] = v
    return result


def try_store_name(target: ast.AST, value: ast.AST, env: dict[str, Any]) -> None:
    """Best-effort резолв module-level присваивания в *env*.

    Невычислимые значения (импорты, `**unpack`, вызовы вне поддерживаемого
    подмножества) молча игнорируются — посторонние module-level присваивания
    в файле воркера — обычное дело и не должны прерывать извлечение. Для
    ИМЕНИ, которое реально нужно извлечь (см. `extract_named_module_dict`),
    эта функция НЕ используется — там `eval_caps_node` вызывается напрямую,
    и `ValueError` пробрасывается наружу как явная ошибка, а не тихо исчезает
    (см. докстринг модуля / история registry-04).
    """
    if not isinstance(target, ast.Name):
        return
    try:
        env[target.id] = eval_caps_node(value, env)
    except ValueError:
        pass


# ---------------------------------------------------------------------------
# Post-assignment mutation guard — see module docstring for the full rule.
# ---------------------------------------------------------------------------

_MUTATING_METHODS = frozenset({
    "update", "setdefault", "pop", "popitem", "clear",
    "add", "discard", "remove",
    "append", "extend", "insert",
    "__setitem__", "__delitem__",
})


def _referenced_names(expr: ast.expr) -> set[str]:
    """Все читаемые (`Load`) `Name`-узлы внутри *expr* — синтаксически, без вычисления.

    Используется для построения графа "имя → имена в его собственном RHS", а
    не для резолва значений (этим занимается `eval_caps_node`).
    """
    return {
        node.id
        for node in ast.walk(expr)
        if isinstance(node, ast.Name) and isinstance(node.ctx, ast.Load)
    }


class _ScopeMutations:
    """Результат обхода одного плоского scope (module-level ИЛИ тело одного класса).

    `deps`      — граф зависимостей: имя → множество имён из его же (последнего
                  по тексту) RHS-выражения в этом scope.
    `mutations` — все найденные пост-присваивание мутации как
                  (мутируемое_имя, номер_строки, человекочитаемое описание конструкции).
    """

    __slots__ = ("deps", "mutations")

    def __init__(self) -> None:
        self.deps: dict[str, set[str]] = {}
        self.mutations: list[tuple[str, int, str]] = []


def _mutation_base_name(target: ast.expr) -> ast.Name | None:
    """Достать базовое `Name`, которое реально мутируется, из цели `X[...]`/`X.attr`.

    Снимает ЛЮБУЮ глубину вложенных `Subscript`/`Attribute` (напр.
    `CAPABILITIES["matrix"]["pages"] = v` → базовое имя `CAPABILITIES`), пока
    не останется `Name` или что-то ещё (тогда — не мутация простого имени,
    `None`).
    """
    while isinstance(target, (ast.Subscript, ast.Attribute)):
        target = target.value
    return target if isinstance(target, ast.Name) else None


def _iter_calls_within_scope(node: ast.AST):
    """Рекурсивно найти все `ast.Call` внутри *node*, не спускаясь в
    `def`/`lambda`/`class` — эти вложенные scope'ы не исполняются во время
    статического разбора (тело функции — на этапе ВЫЗОВА, не определения).
    """
    if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef, ast.Lambda, ast.ClassDef)):
        return
    if isinstance(node, ast.Call):
        yield node
    for child in ast.iter_child_nodes(node):
        yield from _iter_calls_within_scope(child)


def _record_mutating_calls(expr: ast.expr | None, scope: _ScopeMutations, lineno: int) -> None:
    """Найти внутри *expr* ЛЮБОЙ вызов `X.<mutating-method>(...)` — в т.ч. как
    значение присваивания (`targets = _MATRIX.setdefault(...)`) или во
    вложенном/цепочечном вызове (`_MATRIX.setdefault(k, set()).add(v)` — сам
    `.setdefault(...)` находится и флагуется независимо от того, что делает
    внешний `.add(...)` с его результатом) — не только statement-level
    `X.update(...)`.
    """
    if expr is None:
        return
    for call in _iter_calls_within_scope(expr):
        if not (isinstance(call.func, ast.Attribute) and call.func.attr in _MUTATING_METHODS):
            continue
        base = _mutation_base_name(call.func.value)
        if base is not None:
            scope.mutations.append((
                base.id, lineno,
                f"mutating method call `{base.id}.{call.func.attr}(...)`",
            ))


def _gather_scope(stmts: list[ast.stmt], scope: _ScopeMutations) -> None:
    """Рекурсивно обойти *stmts*, наполняя *scope* зависимостями и мутациями.

    Спускается внутрь `if`/`for`/`while`/`try`/`with` — условность и циклы НЕ
    создают новый scope и НЕ защищают мутацию от обнаружения (см. докстринг
    модуля: guard не имеет значения для статического анализатора). НЕ
    спускается внутрь `def`/`class` — тело функции не исполняется во время
    статического разбора, а вложенный класс — отдельный scope. Мутация,
    видимая только ЧЕРЕЗ ВЫЗОВ функции (`def _augment(m): m["x"]=…` затем
    `_augment(_MATRIX)`), намеренно НЕ обнаруживается — межпроцедурный анализ
    вне охвата этого guard'а.
    """
    for node in stmts:
        if isinstance(node, ast.Assign):
            for target in node.targets:
                if isinstance(target, ast.Name):
                    scope.deps.setdefault(target.id, set()).update(_referenced_names(node.value))
                else:
                    base = _mutation_base_name(target)
                    if base is not None:
                        scope.mutations.append((
                            base.id, node.lineno,
                            f"subscript/attribute assignment `{base.id}[...] = ...`",
                        ))
            _record_mutating_calls(node.value, scope, node.lineno)
        elif isinstance(node, ast.AnnAssign) and node.value is not None:
            if isinstance(node.target, ast.Name):
                scope.deps.setdefault(node.target.id, set()).update(_referenced_names(node.value))
            else:
                base = _mutation_base_name(node.target)
                if base is not None:
                    scope.mutations.append((
                        base.id, node.lineno,
                        f"subscript/attribute assignment `{base.id}[...] = ...`",
                    ))
            _record_mutating_calls(node.value, scope, node.lineno)
        elif isinstance(node, ast.AugAssign):
            base = node.target if isinstance(node.target, ast.Name) else _mutation_base_name(node.target)
            if base is not None:
                scope.mutations.append((
                    base.id, node.lineno,
                    f"augmented assignment (`{type(node.op).__name__}`) on `{base.id}`",
                ))
            _record_mutating_calls(node.value, scope, node.lineno)
        elif isinstance(node, ast.Delete):
            for target in node.targets:
                base = _mutation_base_name(target)
                if base is not None:
                    scope.mutations.append((
                        base.id, node.lineno, f"`del {base.id}[...]`",
                    ))
        elif isinstance(node, ast.Expr):
            _record_mutating_calls(node.value, scope, node.lineno)
        elif isinstance(node, ast.Assert):
            _record_mutating_calls(node.test, scope, node.lineno)
            _record_mutating_calls(node.msg, scope, node.lineno)
        elif isinstance(node, ast.Raise):
            _record_mutating_calls(node.exc, scope, node.lineno)
            _record_mutating_calls(node.cause, scope, node.lineno)

        # Control-flow: спуститься внутрь без создания нового scope. Заголовочные
        # выражения (test/iter/context_expr) сканируются здесь же на мутирующие
        # вызовы — тела вложенных statement'ов получат свой собственный проход
        # через рекурсию, без повторного сканирования одного и того же узла.
        if isinstance(node, ast.If):
            _record_mutating_calls(node.test, scope, node.lineno)
            _gather_scope(node.body, scope)
            _gather_scope(node.orelse, scope)
        elif isinstance(node, (ast.For, ast.AsyncFor)):
            _record_mutating_calls(node.iter, scope, node.lineno)
            _gather_scope(node.body, scope)
            _gather_scope(node.orelse, scope)
        elif isinstance(node, ast.While):
            _record_mutating_calls(node.test, scope, node.lineno)
            _gather_scope(node.body, scope)
            _gather_scope(node.orelse, scope)
        elif isinstance(node, ast.Try):
            _gather_scope(node.body, scope)
            for handler in node.handlers:
                _record_mutating_calls(handler.type, scope, handler.lineno)
                _gather_scope(handler.body, scope)
            _gather_scope(node.orelse, scope)
            _gather_scope(node.finalbody, scope)
        elif isinstance(node, (ast.With, ast.AsyncWith)):
            for item in node.items:
                _record_mutating_calls(item.context_expr, scope, node.lineno)
            _gather_scope(node.body, scope)
        # ast.FunctionDef / ast.AsyncFunctionDef / ast.ClassDef: намеренно не трогаем.


def _closure(start: set[str], deps: dict[str, set[str]]) -> set[str]:
    """Транзитивное замыкание по графу зависимостей *deps*, начиная с *start*."""
    seen = set(start)
    stack = list(start)
    while stack:
        current = stack.pop()
        for dep in deps.get(current, ()):
            if dep not in seen:
                seen.add(dep)
                stack.append(dep)
    return seen


def _raise_if_mutation_feeds_target(
    worker_file: Path,
    *,
    target_name: str,
    target_value_node: ast.expr,
    scopes: tuple[_ScopeMutations, ...],
) -> None:
    """`CapabilitiesExtractionError`, если найдена мутация имени, которое
    (транзитивно, через `deps` из тех же *scopes*) участвует в вычислении
    *target_name*. Имена вне этого замыкания намеренно не проверяются — см.
    докстринг модуля.
    """
    combined_deps: dict[str, set[str]] = {}
    for scope in scopes:
        for k, v in scope.deps.items():
            combined_deps.setdefault(k, set()).update(v)

    feeding = _closure({target_name} | _referenced_names(target_value_node), combined_deps)

    for scope in scopes:
        for mutated_name, lineno, construct in scope.mutations:
            if mutated_name in feeding:
                raise CapabilitiesExtractionError(
                    f"{_rel(worker_file)}:{lineno}: {construct} mutates {mutated_name!r} "
                    f"AFTER its assignment — this feeds {target_name!r} but is invisible "
                    "to the static extractor and would silently disappear from the "
                    "generated catalog. Put the entry directly in the dict/set LITERAL "
                    "instead (or compute it in a single expression) so it is statically "
                    "visible."
                )


def extract_named_module_dict(worker_file: Path, name: str) -> dict[str, Any] | None:
    """Вернуть module-level dict-литерал, присвоенный имени *name* в *worker_file*.

    Строго по *name*: если `name = <expr>` есть на уровне модуля, но `<expr>`
    невозможно статически вычислить, — `CapabilitiesExtractionError` с путём
    файла и конструкцией, НИКОГДА не тихий `None` (иначе граф капабилити
    воркера пропадает без следа, см. докстринг модуля). Любое ДРУГОЕ
    module-level присваивание остаётся best-effort (`try_store_name`) — оно
    нужно только как резолвер имён для выражения самого *name*.

    Возвращает `None`, если *name* вообще не присваивается на уровне модуля
    — это легитимный "не найдено", вызывающий сам решает, ошибка это или нет.
    """
    try:
        source = worker_file.read_text(encoding="utf-8")
        tree = ast.parse(source, filename=str(worker_file))
    except (OSError, SyntaxError) as exc:
        raise CapabilitiesExtractionError(f"Could not parse {_rel(worker_file)}: {exc}") from exc

    env: dict[str, Any] = {}
    found: dict[str, Any] | None = None
    found_value_node: ast.expr | None = None

    for node in tree.body:
        if isinstance(node, ast.Assign):
            targets: list[ast.expr] = node.targets
            value_node = node.value
        elif isinstance(node, ast.AnnAssign) and node.value is not None:
            targets = [node.target]
            value_node = node.value
        else:
            continue

        for target in targets:
            if isinstance(target, ast.Name) and target.id == name:
                try:
                    value = eval_caps_node(value_node, env)
                except ValueError as exc:
                    raise CapabilitiesExtractionError(
                        f"Could not statically evaluate module-level {name!r} in "
                        f"{_rel(worker_file)}: {exc}"
                    ) from exc
                if not isinstance(value, dict):
                    raise CapabilitiesExtractionError(
                        f"Module-level {name!r} in {_rel(worker_file)} did not evaluate "
                        f"to a dict (got {type(value).__name__})"
                    )
                env[target.id] = value
                found = value
                found_value_node = value_node
            else:
                try_store_name(target, value_node, env)

    if found is not None:
        assert found_value_node is not None  # found is only ever set alongside it
        module_scope = _ScopeMutations()
        _gather_scope(tree.body, module_scope)
        _raise_if_mutation_feeds_target(
            worker_file,
            target_name=name,
            target_value_node=found_value_node,
            scopes=(module_scope,),
        )

    return found


def extract_capabilities_ast(
    worker_file: Path, *, name: str = "CAPABILITIES"
) -> dict[str, Any] | None:
    """Вернуть словарь *name* из *worker_file* через AST, или `None`, если его нет.

    Приоритет module-level *name* (ai worker's `CAPABILITIES`; ffmpeg's
    `AUDIO_CAPABILITIES`/`VIDEO_CAPABILITIES`) над class-level атрибутом
    (`CAPABILITIES` у data/image/libreoffice, и у ffmpeg — комбинированный
    audio+video, который никто не регистрирует напрямую) — тот же приоритет,
    что был у старого exec-based пути. Строго по *name* на ОБОИХ уровнях:
    невычислимое выражение, присвоенное *name*, — `CapabilitiesExtractionError`
    с путём файла и конструкцией, никогда не тихий пропуск.
    """
    module_found = extract_named_module_dict(worker_file, name)
    if module_found is not None:
        return module_found

    try:
        source = worker_file.read_text(encoding="utf-8")
        tree = ast.parse(source, filename=str(worker_file))
    except (OSError, SyntaxError) as exc:
        raise CapabilitiesExtractionError(f"Could not parse {_rel(worker_file)}: {exc}") from exc

    # Восстановить module-level env (нужен для class-level выражений,
    # ссылающихся на module-level имена, напр. `CAPABILITIES = {"matrix": SUPPORTED}`).
    env: dict[str, Any] = {}
    for node in tree.body:
        if isinstance(node, ast.Assign):
            for target in node.targets:
                try_store_name(target, node.value, env)
        elif isinstance(node, ast.AnnAssign) and node.value is not None:
            try_store_name(node.target, node.value, env)

    class_found: dict[str, Any] | None = None
    class_found_value_node: ast.expr | None = None
    class_found_body: list[ast.stmt] | None = None
    for node in tree.body:
        if not isinstance(node, ast.ClassDef):
            continue
        for item in node.body:
            caps_value: ast.expr | None = None
            if isinstance(item, ast.Assign):
                for target in item.targets:
                    if isinstance(target, ast.Name) and target.id == name:
                        caps_value = item.value
                        break
            elif (
                isinstance(item, ast.AnnAssign)
                and item.value is not None
                and isinstance(item.target, ast.Name)
                and item.target.id == name
            ):
                caps_value = item.value
            if caps_value is None:
                continue
            try:
                evaluated = eval_caps_node(caps_value, env)
            except ValueError as exc:
                raise CapabilitiesExtractionError(
                    f"Could not statically evaluate class {name!r} in "
                    f"{_rel(worker_file)}: {exc}"
                ) from exc
            if not isinstance(evaluated, dict):
                raise CapabilitiesExtractionError(
                    f"Class {name!r} in {_rel(worker_file)} did not evaluate to a dict "
                    f"(got {type(evaluated).__name__})"
                )
            class_found = evaluated
            class_found_value_node = caps_value
            class_found_body = node.body

    if class_found is not None:
        assert class_found_value_node is not None and class_found_body is not None
        module_scope = _ScopeMutations()
        _gather_scope(tree.body, module_scope)
        class_scope = _ScopeMutations()
        _gather_scope(class_found_body, class_scope)
        _raise_if_mutation_feeds_target(
            worker_file,
            target_name=name,
            target_value_node=class_found_value_node,
            scopes=(module_scope, class_scope),
        )

    return class_found


def serialize_capabilities(
    caps: dict[str, Any], *, worker_label: str = ""
) -> dict[str, Any]:
    """Нормализовать CAPABILITIES для стабильного JSON-сравнения (sets → sorted lists)."""
    missing_keys = [k for k in ("routing_keys", "matrix") if k not in caps]
    if missing_keys:
        label = f" ({worker_label})" if worker_label else ""
        raise CapabilitiesExtractionError(
            f"CAPABILITIES{label} missing required key(s): {missing_keys}"
        )

    def ser(v: Any) -> list[Any]:
        if isinstance(v, (set, frozenset, list)):
            return sorted(v)
        return sorted(str(x) for x in v)

    return {
        "routing_keys": list(caps["routing_keys"]),
        "matrix": {k: ser(v) for k, v in caps["matrix"].items()},
    }


def load_worker_capabilities(workers_dir: Path) -> list[tuple[str, dict[str, Any]]]:
    """Вернуть `[(имя_директории, capabilities), …]` для каждого `workers/*/worker.py`.

    Падает громко, а не тихо ужимает свой же вывод (родословная registry-04):
    и `worker.py` без разрешимого `CAPABILITIES`, и скан директории, не
    нашедший ни одного `worker.py`, — обе ветки `CapabilitiesExtractionError`,
    а не тихое исчезновение.
    """
    results: list[tuple[str, dict[str, Any]]] = []
    found_any_worker_file = False
    for worker_dir in sorted(workers_dir.iterdir()):
        if not worker_dir.is_dir():
            continue
        worker_file = worker_dir / "worker.py"
        if not worker_file.exists():
            # Не каждая workers/* директория — пакет воркера: common/, gateway/,
            # metrics_exporter/, tests/, tools/ не содержат worker.py by design.
            continue
        found_any_worker_file = True
        raw = extract_capabilities_ast(worker_file)
        if raw is None:
            raise CapabilitiesExtractionError(
                f"workers/{worker_dir.name}/worker.py has no CAPABILITIES (neither a "
                "module-level constant nor a class attribute) — silently dropping this "
                "worker from the comparison is exactly the failure mode this module "
                "exists to prevent. If this worker genuinely must not declare "
                "capabilities, exclude it here explicitly with a comment explaining why."
            )
        results.append(
            (worker_dir.name, serialize_capabilities(raw, worker_label=worker_dir.name))
        )
    if not found_any_worker_file:
        raise CapabilitiesExtractionError(
            f"Found ZERO workers/*/worker.py files under {workers_dir} — the worker scan "
            "came back empty. Both drift assertions would otherwise pass vacuously while "
            "comparing against nothing."
        )
    return results

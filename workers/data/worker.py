"""Data format conversion worker: csv ↔ json ↔ xml ↔ yaml ↔ toml.

Транспорт: WS-клиент (StreamConsumerBase.run(), s1-10).
Задача доставляется через process_job; job['_localInput'] уже заполнен WsClient'ом.
convert() возвращает (out_path, mime, target_ext) → ResultSignal → gateway.
"""

from __future__ import annotations

import json
import logging
import uuid
from pathlib import Path
from typing import Any

from workers.common.stream_consumer import WORK_DIR, StreamConsumerBase

logger = logging.getLogger(__name__)

SUPPORTED: dict[str, set[str]] = {
    "csv":  {"json", "xml", "yaml", "yml", "toml"},
    "json": {"csv", "xml", "yaml", "yml", "toml"},
    "xml":  {"csv", "json", "yaml", "yml", "toml"},
    "yaml": {"csv", "json", "xml", "toml"},
    "yml":  {"csv", "json", "xml", "toml"},
    "toml": {"csv", "json", "xml", "yaml", "yml"},
}

# MIME types for output formats
_MIME: dict[str, str] = {
    "json": "application/json",
    "csv":  "text/csv",
    "xml":  "application/xml",
    "yaml": "application/x-yaml",
    "yml":  "application/x-yaml",
    "toml": "application/toml",
}


def _coerce_leaf(text: Any) -> Any:
    """Coerce XML leaf text to native int/float/bool — lossless round-trips only.

    Format-sensitive strings (leading zeros, "1e3", ".5") and non-finite tokens
    (nan/inf/-inf) fail the round-trip / finiteness check and stay strings;
    empty/whitespace-only text is returned unchanged.
    """
    import math

    if not isinstance(text, str):
        return text
    s = text.strip()
    if not s:
        return text
    try:
        if str(int(s)) == s:
            return int(s)
    except ValueError:
        pass
    try:
        f = float(s)
        if str(f) == s and math.isfinite(f):
            return f
    except ValueError:
        pass
    if s.lower() in ("true", "false"):
        return s.lower() == "true"
    return text


def _records_for_csv(data: Any) -> list:
    """Normalise *data* to a list of records (rows) for the CSV writer.

    - list                -> used as-is (header = union of record keys).
    - dict, single key:
        - value is a list -> unwrap to rows         ({"rows": [...]})
        - value is a dict -> descend recursively    (XML {"root": {"item": [...]}})
        - value is scalar -> one row (the dict)
    - dict, multiple keys -> ONE row preserving ALL top-level fields (lossless).
    """
    if isinstance(data, list):
        return data
    if isinstance(data, dict):
        if len(data) == 1:
            value = next(iter(data.values()))
            if isinstance(value, list):
                return value
            if isinstance(value, dict):
                return _records_for_csv(value)
            return [data]
        return [data]
    raise ValueError("cannot convert to CSV: unexpected data shape")


def _read_data(src: Path) -> Any:
    """Read data from *src* into a Python object (list/dict)."""
    ext = src.suffix.lower().lstrip(".")

    if ext == "csv":
        import pandas as pd
        df = pd.read_csv(src)
        return df.to_dict(orient="records")

    if ext == "json":
        return json.loads(src.read_text(encoding="utf-8"))

    if ext in ("yaml", "yml"):
        import yaml

        # CNV-131: yaml.YAMLError (и все её сабклассы — ScannerError/ParserError/
        # ReaderError/ConstructorError) НЕ наследует ValueError — тот же класс
        # дефекта, что CNV-128 закрыл для XML (ET.ParseError). Без перехвата
        # уходит в generic except StreamConsumerBase.process_job() →
        # permanent=False → бесконечный ретрай постоянно-битого YAML.
        # Перехватываем и перевыбрасываем как ValueError, сохраняя исходное
        # сообщение (mark/line/column у YAMLError) — это то, что делает ошибку
        # читаемой. Аномально глубоко вложенный (но well-formed) YAML —
        # тот же приём, что и RecursionError у CNV-128 для XML: рекурсивный
        # конструктор PyYAML переполняет стек на тысячах уровней вложенности.
        try:
            return yaml.safe_load(src.read_text(encoding="utf-8"))
        except yaml.YAMLError as exc:
            raise ValueError(f"malformed YAML: {exc}") from exc
        except RecursionError as exc:
            raise ValueError(f"malformed YAML: nesting too deep ({exc})") from exc

    if ext == "toml":
        import tomllib
        return tomllib.loads(src.read_text(encoding="utf-8"))

    if ext == "xml":
        import xml.etree.ElementTree as ET

        def _elem_to_dict(elem: ET.Element) -> Any:
            children = list(elem)
            if not children and not elem.attrib:
                return _coerce_leaf(elem.text)
            result: dict[str, Any] = {}
            if elem.attrib:
                result.update(elem.attrib)
            for child in children:
                child_data = _elem_to_dict(child)
                if child.tag in result:
                    existing = result[child.tag]
                    if not isinstance(existing, list):
                        result[child.tag] = [existing]
                    result[child.tag].append(child_data)
                else:
                    result[child.tag] = child_data
            return result

        # CNV-128: xml.etree.ElementTree.ParseError НЕ наследует ValueError
        # (наследует SyntaxError), а неизвестная кодировка в XML-декларации
        # (<?xml ... encoding="bogus"?>) даёт LookupError — тоже не ValueError.
        # Оба случая означают permanently-битый вход (повтор не поможет), но
        # без перехвата уходят в generic except StreamConsumerBase.process_job()
        # → permanent=False → бесконечный ретрай. Перехватываем и перевыбрасываем
        # как ValueError, сохраняя исходное сообщение (line/column у ParseError,
        # имя кодировки у LookupError) — это то, что делает ошибку читаемой.
        try:
            tree = ET.parse(src)
        except (ET.ParseError, LookupError) as exc:
            raise ValueError(f"malformed XML: {exc}") from exc
        root = tree.getroot()
        # Well-formed XML, wrong shape для нашего обхода: _elem_to_dict()
        # рекурсивен (1 фрейм на уровень вложенности), и на аномально глубоко
        # вложенном документе (тысячи уровней) упирается в RecursionError —
        # подкласс RuntimeError, тоже не ValueError. Вход постоянно
        # непроходим этим обходчиком (повтор не поможет) — тот же класс
        # дефекта, что и ParseError/LookupError выше.
        try:
            return {root.tag: _elem_to_dict(root)}
        except RecursionError as exc:
            raise ValueError(f"malformed XML: nesting too deep ({exc})") from exc

    raise ValueError(f"unsupported input format: {ext}")


def _toml_safe(value: Any) -> Any:
    """Drop None/NaN recursively: TOML has no null and csv/pandas yields NaN."""
    if isinstance(value, dict):
        return {k: _toml_safe(v) for k, v in value.items() if not _is_null(v)}
    if isinstance(value, list):
        return [_toml_safe(v) for v in value if not _is_null(v)]
    return value


def _is_null(value: Any) -> bool:
    import math

    return value is None or (isinstance(value, float) and math.isnan(value))


def _write_data(data: Any, out_path: Path, options: dict[str, Any] | None = None) -> None:
    """Write Python object *data* to *out_path* in the appropriate format.

    *options* — normalized job options (CNV-103/CNV-104). Only the CSV and JSON
    branches read it (data.csv/data.json profiles); YAML/TOML/XML never receive
    a profile server-side and this function never looks at *options* for those
    branches — their output is byte-identical regardless of what is passed in.
    """
    ext = out_path.suffix.lower().lstrip(".")
    opts = options or {}

    if ext == "json":
        # pretty (bool) / indent (1-8, only meaningful when pretty) — CNV-104.
        # No options at all ⇒ same call as before this card: indent=2 (already
        # pretty), so "no options" stays byte-identical to pre-CNV-104 output.
        pretty = opts.get("pretty")
        pretty_effective = True if pretty is None else bool(pretty)
        # default=str: TOML/YAML yield native date/datetime objects json can't serialize.
        if pretty_effective:
            indent_val = opts.get("indent")
            indent = int(indent_val) if indent_val is not None else 2
            text = json.dumps(data, ensure_ascii=False, indent=indent, default=str)
        else:
            # indent has no effect when pretty is explicitly false (CNV-103/104).
            text = json.dumps(data, ensure_ascii=False, separators=(",", ":"), default=str)
        out_path.write_text(text, encoding="utf-8")
        return

    if ext in ("yaml", "yml"):
        import yaml
        out_path.write_text(
            yaml.dump(data, allow_unicode=True, default_flow_style=False),
            encoding="utf-8",
        )
        return

    if ext == "csv":
        # delimiter/quote (literal single characters) / encoding (utf-8 only,
        # already the fixed behaviour below) — CNV-104. Reading the *source*
        # file bytes with strict UTF-8 decoding (below, and via read_text for
        # the other readers) is what gives the "no replacement fallback"
        # guarantee for invalid UTF-8 input: Python/pandas both raise
        # UnicodeDecodeError (a ValueError subclass -> permanent worker error)
        # instead of substituting U+FFFD.
        import pandas as pd
        delimiter = str(opts.get("delimiter") or ",")
        quotechar = str(opts.get("quote") or '"')
        df = pd.DataFrame(_records_for_csv(data))
        df.to_csv(
            out_path, index=False, encoding="utf-8", sep=delimiter, quotechar=quotechar
        )
        return

    if ext == "toml":
        import tomli_w

        safe = _toml_safe(data)
        # TOML root must be a table; wrap a top-level list (csv/json array) under "rows".
        doc = {"rows": safe} if isinstance(safe, list) else safe
        if not isinstance(doc, dict):
            raise ValueError("cannot convert to TOML: unexpected data shape")
        out_path.write_text(tomli_w.dumps(doc), encoding="utf-8")
        return

    if ext == "xml":
        import xml.etree.ElementTree as ET

        def _dict_to_elem(tag: str, value: Any) -> ET.Element:
            elem = ET.Element(tag)
            if isinstance(value, dict):
                for k, v in value.items():
                    child = _dict_to_elem(k, v)
                    elem.append(child)
            elif isinstance(value, list):
                for item in value:
                    child = _dict_to_elem("item", item)
                    elem.append(child)
            elif value is not None:
                elem.text = str(value)
            return elem

        if isinstance(data, dict) and len(data) == 1:
            root_tag, root_val = next(iter(data.items()))
            root_elem = _dict_to_elem(root_tag, root_val)
        else:
            root_elem = _dict_to_elem("root", data)

        tree = ET.ElementTree(root_elem)
        ET.indent(tree, space="  ")
        tree.write(out_path, encoding="unicode", xml_declaration=True)
        return

    raise ValueError(f"unsupported output format: {ext}")


class DataWorker(StreamConsumerBase):
    """Stream worker for structured data format conversions (csv/json/xml/yaml)."""

    CAPABILITIES: dict[str, Any] = {
        "routing_keys": ["data"],
        "isAi": False,
        "matrix": SUPPORTED,
    }

    def convert(self, job: dict[str, Any]) -> tuple[str, str, str]:
        conv_id: int = job["conversionId"]
        src = Path(job["_localInput"])
        target_fmt: str = job["targetFormat"].lower().lstrip(".")
        src_fmt = str(job["sourceFormat"]).lower().lstrip(".")

        # Normalized job options (CNV-103): [] when empty (PHP empty array ->
        # JSON []), a {} map otherwise. Workers are flag-agnostic — the catalog
        # already validated/whitelisted these; only data.csv/data.json ever
        # carry any (YAML/TOML/XML pairs get no profile and options stays {}).
        options = job.get("options") or {}
        if not isinstance(options, dict):
            raise ValueError("invalid data options")

        if not src.is_file():
            raise FileNotFoundError(f"input file not found: {src}")

        if src_fmt not in SUPPORTED:
            raise ValueError(f"unsupported input format: {src_fmt}")

        # Normalise yml → yaml for the conversion-pair check.
        canon_out = "yaml" if target_fmt == "yml" else target_fmt
        allowed = {("yaml" if f == "yml" else f) for f in SUPPORTED[src_fmt]}
        if canon_out not in allowed:
            raise ValueError(f"unsupported conversion: {src_fmt} -> {target_fmt}")

        out_dir = Path(job.get("_jobDir") or str(WORK_DIR))
        out_dir.mkdir(parents=True, exist_ok=True)
        out_path = out_dir / f"out-{conv_id}-{uuid.uuid4().hex}.{target_fmt}"

        data = _read_data(src)
        _write_data(data, out_path, options)

        if not out_path.exists():
            raise RuntimeError("data conversion produced no output file")

        mime = _MIME.get(target_fmt, "application/octet-stream")
        logger.info(
            "converted %s -> %s (conversionId=%s)", src.name, out_path.name, conv_id
        )
        return str(out_path), mime, target_fmt


if __name__ == "__main__":
    DataWorker().run()

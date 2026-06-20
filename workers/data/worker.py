"""Data format conversion worker: csv ↔ json ↔ xml ↔ yaml ↔ toml.

Phase 1, XREADGROUP-based: consumes stream conv.data (consumer group
convertor), reads the local input the base class downloaded from S3
(job['_localInput']), and returns (out_path, mime, target_ext) for the base
class to upload to the results bucket.
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
        return yaml.safe_load(src.read_text(encoding="utf-8"))

    if ext == "toml":
        import tomllib
        return tomllib.loads(src.read_text(encoding="utf-8"))

    if ext == "xml":
        import xml.etree.ElementTree as ET

        def _elem_to_dict(elem: ET.Element) -> Any:
            children = list(elem)
            if not children and not elem.attrib:
                return elem.text
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

        tree = ET.parse(src)
        root = tree.getroot()
        return {root.tag: _elem_to_dict(root)}

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


def _write_data(data: Any, out_path: Path) -> None:
    """Write Python object *data* to *out_path* in the appropriate format."""
    ext = out_path.suffix.lower().lstrip(".")

    if ext == "json":
        # default=str: TOML/YAML yield native date/datetime objects json can't serialize.
        out_path.write_text(
            json.dumps(data, ensure_ascii=False, indent=2, default=str),
            encoding="utf-8",
        )
        return

    if ext in ("yaml", "yml"):
        import yaml
        out_path.write_text(
            yaml.dump(data, allow_unicode=True, default_flow_style=False),
            encoding="utf-8",
        )
        return

    if ext == "csv":
        import pandas as pd
        if isinstance(data, list):
            df = pd.DataFrame(data)
        elif isinstance(data, dict):
            # Try to get the first list-valued key as records
            for v in data.values():
                if isinstance(v, list):
                    df = pd.DataFrame(v)
                    break
            else:
                df = pd.DataFrame([data])
        else:
            raise ValueError("cannot convert to CSV: unexpected data shape")
        df.to_csv(out_path, index=False, encoding="utf-8")
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
        "matrix": SUPPORTED,
    }

    def convert(self, job: dict[str, Any]) -> tuple[str, str, str]:
        conv_id: int = job["conversionId"]
        src = Path(job["_localInput"])
        target_fmt: str = job["targetFormat"].lower().lstrip(".")
        src_fmt = str(job["sourceFormat"]).lower().lstrip(".")

        if not src.is_file():
            raise FileNotFoundError(f"input file not found: {src}")

        if src_fmt not in SUPPORTED:
            raise ValueError(f"unsupported input format: {src_fmt}")

        # Normalise yml → yaml for the conversion-pair check.
        canon_out = "yaml" if target_fmt == "yml" else target_fmt
        allowed = {("yaml" if f == "yml" else f) for f in SUPPORTED[src_fmt]}
        if canon_out not in allowed:
            raise ValueError(f"unsupported conversion: {src_fmt} -> {target_fmt}")

        WORK_DIR.mkdir(parents=True, exist_ok=True)
        out_path = WORK_DIR / f"out-{conv_id}-{uuid.uuid4().hex}.{target_fmt}"

        data = _read_data(src)
        _write_data(data, out_path)

        if not out_path.exists():
            raise RuntimeError("data conversion produced no output file")

        mime = _MIME.get(target_fmt, "application/octet-stream")
        logger.info(
            "converted %s -> %s (conversionId=%s)", src.name, out_path.name, conv_id
        )
        return str(out_path), mime, target_fmt


if __name__ == "__main__":
    DataWorker().run()

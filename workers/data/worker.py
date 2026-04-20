"""Data format conversion worker: csv ↔ json ↔ xml ↔ yaml."""

import json
import logging
from pathlib import Path
from typing import Any

from workers.common.base_worker import SHARE_DIR, BaseWorker
from workers.common.safe_path import safe_share_path

logger = logging.getLogger(__name__)

SUPPORTED: dict[str, set[str]] = {
    "csv":  {"json", "xml", "yaml", "yml"},
    "json": {"csv", "xml", "yaml", "yml"},
    "xml":  {"csv", "json", "yaml", "yml"},
    "yaml": {"csv", "json", "xml"},
    "yml":  {"csv", "json", "xml"},
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


def _write_data(data: Any, out_path: Path) -> None:
    """Write Python object *data* to *out_path* in the appropriate format."""
    ext = out_path.suffix.lower().lstrip(".")

    if ext == "json":
        out_path.write_text(
            json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8"
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


class DataWorker(BaseWorker):
    """Queue worker for structured data format conversions (csv/json/xml/yaml)."""

    queue_name = "convertor:data"

    async def process_task(self, task: dict[str, Any]) -> dict[str, Any]:
        import asyncio

        input_path_raw: str = task["input_path"]
        output_format: str = task["output_format"].lower().lstrip(".")

        src = safe_share_path(input_path_raw, SHARE_DIR)
        if not src.is_file():
            raise FileNotFoundError(f"input file not found: {src}")

        in_format = src.suffix.lower().lstrip(".")
        if in_format not in SUPPORTED:
            raise ValueError(f"unsupported input format: {in_format}")

        # Normalise yml → yaml for lookup
        canon_in = "yaml" if in_format == "yml" else in_format
        canon_out = "yaml" if output_format == "yml" else output_format

        allowed = {("yaml" if f == "yml" else f) for f in SUPPORTED[in_format]}
        if canon_out not in allowed:
            raise ValueError(f"unsupported conversion: {in_format} -> {output_format}")

        out_path = src.with_suffix(f".{output_format}")

        data = await asyncio.to_thread(_read_data, src)
        await asyncio.to_thread(_write_data, data, out_path)

        if not out_path.exists():
            raise RuntimeError("data conversion produced no output file")

        logger.info(
            "converted %s -> %s (task id=%s)", src.name, out_path.name, task.get("id")
        )
        return {"status": "ok", "output_path": str(out_path)}


if __name__ == "__main__":
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )
    worker = DataWorker()
    worker.run()

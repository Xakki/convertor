"""Tests for DataWorker: csv→json, json→yaml, and round-trip conversions."""

import asyncio
import json
import sys
import types
from pathlib import Path
from unittest.mock import MagicMock, patch

import pytest

# ---------------------------------------------------------------------------
# Stub out redis / requests so we can import workers without those packages
# ---------------------------------------------------------------------------
if "redis" not in sys.modules:
    redis_stub = types.ModuleType("redis")
    redis_stub.Redis = MagicMock()
    sys.modules["redis"] = redis_stub

if "requests" not in sys.modules:
    req_stub = types.ModuleType("requests")
    req_stub.Session = MagicMock(return_value=MagicMock())
    adapters_stub = types.ModuleType("requests.adapters")
    adapters_stub.HTTPAdapter = MagicMock()
    sys.modules["requests"] = req_stub
    sys.modules["requests.adapters"] = adapters_stub

if "urllib3" not in sys.modules:
    sys.modules["urllib3"] = types.ModuleType("urllib3")
    sys.modules["urllib3.util"] = types.ModuleType("urllib3.util")
    retry_stub = types.ModuleType("urllib3.util.retry")
    retry_stub.Retry = MagicMock()
    sys.modules["urllib3.util.retry"] = retry_stub

from workers.data.worker import DataWorker, _read_data, _write_data  # noqa: E402

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

SAMPLE_RECORDS = [
    {"name": "Alice", "age": 30, "city": "Moscow"},
    {"name": "Bob", "age": 25, "city": "Almaty"},
]


# ---------------------------------------------------------------------------
# Unit tests for _read_data / _write_data (no I/O to KeyDB needed)
# ---------------------------------------------------------------------------

class TestReadWrite:
    def test_csv_roundtrip(self, tmp_path: Path) -> None:
        import pandas as pd

        csv_file = tmp_path / "data.csv"
        pd.DataFrame(SAMPLE_RECORDS).to_csv(csv_file, index=False)

        data = _read_data(csv_file)
        assert isinstance(data, list)
        assert len(data) == 2
        assert data[0]["name"] == "Alice"

        out_csv = tmp_path / "out.csv"
        _write_data(data, out_csv)
        assert out_csv.exists()
        loaded = pd.read_csv(out_csv).to_dict(orient="records")
        assert loaded[1]["city"] == "Almaty"

    def test_json_roundtrip(self, tmp_path: Path) -> None:
        json_file = tmp_path / "data.json"
        json_file.write_text(json.dumps(SAMPLE_RECORDS), encoding="utf-8")

        data = _read_data(json_file)
        assert data == SAMPLE_RECORDS

        out_json = tmp_path / "out.json"
        _write_data(data, out_json)
        reloaded = json.loads(out_json.read_text(encoding="utf-8"))
        assert reloaded == SAMPLE_RECORDS

    def test_yaml_roundtrip(self, tmp_path: Path) -> None:
        import yaml

        yaml_file = tmp_path / "data.yaml"
        yaml_file.write_text(yaml.dump(SAMPLE_RECORDS, allow_unicode=True), encoding="utf-8")

        data = _read_data(yaml_file)
        assert isinstance(data, list)
        assert data[0]["name"] == "Alice"

        out_yaml = tmp_path / "out.yaml"
        _write_data(data, out_yaml)
        reloaded = yaml.safe_load(out_yaml.read_text(encoding="utf-8"))
        assert reloaded[1]["name"] == "Bob"

    def test_xml_roundtrip(self, tmp_path: Path) -> None:
        xml_content = (
            '<?xml version=\'1.0\' encoding=\'us-ascii\'?>\n'
            "<root><item><name>Alice</name><age>30</age></item>"
            "<item><name>Bob</name><age>25</age></item></root>"
        )
        xml_file = tmp_path / "data.xml"
        xml_file.write_text(xml_content, encoding="utf-8")

        data = _read_data(xml_file)
        assert "root" in data

        out_xml = tmp_path / "out.xml"
        _write_data(data, out_xml)
        assert out_xml.exists()
        assert "<root>" in out_xml.read_text(encoding="utf-8")

    def test_csv_to_json(self, tmp_path: Path) -> None:
        import pandas as pd

        csv_file = tmp_path / "input.csv"
        pd.DataFrame(SAMPLE_RECORDS).to_csv(csv_file, index=False)

        data = _read_data(csv_file)
        out_json = tmp_path / "output.json"
        _write_data(data, out_json)

        result = json.loads(out_json.read_text(encoding="utf-8"))
        assert len(result) == 2
        assert result[0]["name"] == "Alice"

    def test_json_to_yaml(self, tmp_path: Path) -> None:
        import yaml

        json_file = tmp_path / "input.json"
        json_file.write_text(json.dumps(SAMPLE_RECORDS), encoding="utf-8")

        data = _read_data(json_file)
        out_yaml = tmp_path / "output.yaml"
        _write_data(data, out_yaml)

        result = yaml.safe_load(out_yaml.read_text(encoding="utf-8"))
        assert isinstance(result, list)
        assert result[0]["city"] == "Moscow"

    def test_unsupported_input_raises(self, tmp_path: Path) -> None:
        bad_file = tmp_path / "file.toml"
        bad_file.write_text("key = 'value'", encoding="utf-8")
        with pytest.raises(ValueError, match="unsupported input format"):
            _read_data(bad_file)

    def test_unsupported_output_raises(self, tmp_path: Path) -> None:
        bad_out = Path("/tmp/out.toml")
        with pytest.raises(ValueError, match="unsupported output format"):
            _write_data(SAMPLE_RECORDS, bad_out)


# ---------------------------------------------------------------------------
# Integration-style test through DataWorker.process_task
# ---------------------------------------------------------------------------

class TestDataWorkerProcessTask:
    @pytest.fixture()
    def worker(self, tmp_path: Path) -> DataWorker:
        with patch("workers.common.base_worker.QueueClient"):
            w = DataWorker()
        # Override SHARE_DIR so safe_share_path resolves correctly
        with patch("workers.data.worker.SHARE_DIR", tmp_path):
            yield w, tmp_path

    @pytest.mark.asyncio
    async def test_csv_to_json_via_process_task(self, tmp_path: Path) -> None:
        import pandas as pd

        csv_file = tmp_path / "data.csv"
        pd.DataFrame(SAMPLE_RECORDS).to_csv(csv_file, index=False)

        with patch("workers.common.base_worker.QueueClient"):
            w = DataWorker()

        with patch("workers.data.worker.SHARE_DIR", tmp_path):
            result = await w.process_task(
                {
                    "id": "t-csv-json",
                    "input_path": str(csv_file),
                    "output_format": "json",
                    "callback_url": None,
                }
            )

        assert result["status"] == "ok"
        out = Path(result["output_path"])
        assert out.exists()
        loaded = json.loads(out.read_text(encoding="utf-8"))
        assert loaded[0]["name"] == "Alice"

    @pytest.mark.asyncio
    async def test_json_to_yaml_via_process_task(self, tmp_path: Path) -> None:
        import yaml

        json_file = tmp_path / "data.json"
        json_file.write_text(json.dumps(SAMPLE_RECORDS), encoding="utf-8")

        with patch("workers.common.base_worker.QueueClient"):
            w = DataWorker()

        with patch("workers.data.worker.SHARE_DIR", tmp_path):
            result = await w.process_task(
                {
                    "id": "t-json-yaml",
                    "input_path": str(json_file),
                    "output_format": "yaml",
                    "callback_url": None,
                }
            )

        assert result["status"] == "ok"
        out = Path(result["output_path"])
        assert out.exists()
        loaded = yaml.safe_load(out.read_text(encoding="utf-8"))
        assert loaded[1]["name"] == "Bob"

    @pytest.mark.asyncio
    async def test_unsupported_format_raises(self, tmp_path: Path) -> None:
        txt_file = tmp_path / "data.txt"
        txt_file.write_text("hello", encoding="utf-8")

        with patch("workers.common.base_worker.QueueClient"):
            w = DataWorker()

        with patch("workers.data.worker.SHARE_DIR", tmp_path):
            with pytest.raises(ValueError, match="unsupported input format"):
                await w.process_task(
                    {
                        "id": "t-bad",
                        "input_path": str(txt_file),
                        "output_format": "json",
                        "callback_url": None,
                    }
                )

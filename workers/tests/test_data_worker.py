"""Tests for DataWorker.convert() — Phase 1 stream-based worker."""

import json
from pathlib import Path
from unittest.mock import MagicMock, patch

import pytest

from workers.data.worker import DataWorker, _read_data, _write_data

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

SAMPLE_RECORDS = [
    {"name": "Alice", "age": 30, "city": "Moscow"},
    {"name": "Bob", "age": 25, "city": "Almaty"},
]


def _make_job(conv_id: int, input_path: Path, src_fmt: str, tgt_fmt: str) -> dict:
    """Build a job dict. input_path is the local file convert() reads
    (base class injects it as _localInput after the S3 download)."""
    return {
        "conversionId": conv_id,
        "inputBucket": "convertor-inputs",
        "inputKey": f"inputs/{Path(input_path).name}",
        "_localInput": str(input_path),
        "originalFilename": Path(input_path).name,
        "sourceFormat": src_fmt,
        "targetFormat": tgt_fmt,
        "category": "data",
        "isAi": False,
        "subType": None,
        "options": [],
    }


def _worker(tmp_path: Path) -> DataWorker:
    """Return a DataWorker with redis + WORK_DIR mocked."""
    import workers.common.stream_consumer as sc_mod
    import workers.data.worker as dw_mod

    mock_redis = MagicMock()
    with patch.object(sc_mod, "REDIS_HOST", "localhost"), \
         patch("workers.common.stream_consumer.redis.Redis", return_value=mock_redis):
        worker = DataWorker()

    patch.object(dw_mod, "WORK_DIR", tmp_path).start()
    patch.object(sc_mod, "WORK_DIR", tmp_path).start()
    return worker


# ---------------------------------------------------------------------------
# Unit tests for _read_data / _write_data (format helpers)
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
# DataWorker.convert() — new wire contract
# ---------------------------------------------------------------------------

class TestDataConvert:
    def test_csv_to_json(self, tmp_path: Path) -> None:
        import pandas as pd

        src = tmp_path / "data.csv"
        pd.DataFrame(SAMPLE_RECORDS).to_csv(src, index=False)
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, mime, ext = worker.convert(_make_job(1, src, "csv", "json"))

        assert Path(out_path).exists()
        assert ext == "json"
        assert mime == "application/json"
        loaded = json.loads(Path(out_path).read_text(encoding="utf-8"))
        assert loaded[0]["name"] == "Alice"

    def test_json_to_yaml(self, tmp_path: Path) -> None:
        import yaml

        src = tmp_path / "data.json"
        src.write_text(json.dumps(SAMPLE_RECORDS), encoding="utf-8")
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, mime, ext = worker.convert(_make_job(2, src, "json", "yaml"))

        assert Path(out_path).exists()
        assert ext == "yaml"
        assert mime == "application/x-yaml"
        loaded = yaml.safe_load(Path(out_path).read_text(encoding="utf-8"))
        assert loaded[1]["name"] == "Bob"

    def test_output_placed_in_work_dir_with_conv_id(self, tmp_path: Path) -> None:
        src = tmp_path / "data.json"
        src.write_text(json.dumps(SAMPLE_RECORDS), encoding="utf-8")
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, _, ext = worker.convert(_make_job(42, src, "json", "csv"))

        name = Path(out_path).name
        assert Path(out_path).parent == tmp_path
        assert name.startswith("out-42-")
        assert name.endswith(f".{ext}")


class TestDataConvertErrors:
    def test_unsupported_source_raises(self, tmp_path: Path) -> None:
        src = tmp_path / "data.txt"
        src.write_text("hello", encoding="utf-8")
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="unsupported input format"):
                worker.convert(_make_job(3, src, "txt", "json"))

    def test_unsupported_conversion_raises(self, tmp_path: Path) -> None:
        src = tmp_path / "data.yaml"
        src.write_text("[]", encoding="utf-8")
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="unsupported conversion"):
                worker.convert(_make_job(4, src, "yaml", "yml"))

    def test_missing_input_raises(self, tmp_path: Path) -> None:
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            with pytest.raises(FileNotFoundError):
                worker.convert(_make_job(5, tmp_path / "missing.json", "json", "csv"))

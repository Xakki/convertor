"""Tests for DataWorker.convert() — Phase 1 stream-based worker."""

import json
from pathlib import Path
from unittest.mock import MagicMock, patch

import pytest

from workers.data.worker import (
    SUPPORTED,
    DataWorker,
    _read_data,
    _records_for_csv,
    _write_data,
)

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

    def test_toml_read_dict(self, tmp_path: Path) -> None:
        toml_file = tmp_path / "data.toml"
        toml_file.write_text(
            'title = "Convertor"\n[owner]\nname = "Alice"\nage = 30\n',
            encoding="utf-8",
        )
        data = _read_data(toml_file)
        assert isinstance(data, dict)
        assert data["title"] == "Convertor"
        assert data["owner"]["name"] == "Alice"

    def test_toml_write_wraps_list_under_rows(self, tmp_path: Path) -> None:
        import tomllib

        out_toml = tmp_path / "out.toml"
        _write_data(SAMPLE_RECORDS, out_toml)
        reloaded = tomllib.loads(out_toml.read_text(encoding="utf-8"))
        assert "rows" in reloaded
        assert reloaded["rows"][0]["name"] == "Alice"
        assert reloaded["rows"][1]["city"] == "Almaty"

    def test_toml_write_dict_passthrough(self, tmp_path: Path) -> None:
        import tomllib

        out_toml = tmp_path / "out.toml"
        _write_data({"owner": {"name": "Bob", "age": 25}}, out_toml)
        reloaded = tomllib.loads(out_toml.read_text(encoding="utf-8"))
        assert reloaded["owner"]["name"] == "Bob"

    def test_toml_write_drops_none(self, tmp_path: Path) -> None:
        import tomllib

        records = [{"name": "Alice", "city": None}, {"name": "Bob", "city": "Almaty"}]
        out_toml = tmp_path / "out.toml"
        _write_data(records, out_toml)
        reloaded = tomllib.loads(out_toml.read_text(encoding="utf-8"))
        assert "city" not in reloaded["rows"][0]
        assert reloaded["rows"][1]["city"] == "Almaty"

    def test_toml_write_drops_nan_from_csv(self, tmp_path: Path) -> None:
        import tomllib

        import pandas as pd

        csv_file = tmp_path / "data.csv"
        csv_file.write_text("name,city\nAlice,\nBob,Almaty\n", encoding="utf-8")
        data = _read_data(csv_file)  # pandas yields NaN for the blank cell

        out_toml = tmp_path / "out.toml"
        _write_data(data, out_toml)
        reloaded = tomllib.loads(out_toml.read_text(encoding="utf-8"))
        assert "city" not in reloaded["rows"][0]
        assert reloaded["rows"][1]["city"] == "Almaty"

    def test_toml_json_roundtrip(self, tmp_path: Path) -> None:
        toml_file = tmp_path / "data.toml"
        _write_data(SAMPLE_RECORDS, toml_file)

        data = _read_data(toml_file)
        assert data == {"rows": SAMPLE_RECORDS}

        out_json = tmp_path / "out.json"
        _write_data(data, out_json)
        reloaded = json.loads(out_json.read_text(encoding="utf-8"))
        assert reloaded["rows"] == SAMPLE_RECORDS

    def test_toml_with_date_to_json(self, tmp_path: Path) -> None:
        toml_file = tmp_path / "data.toml"
        toml_file.write_text('released = 2024-01-15\nname = "x"\n', encoding="utf-8")

        data = _read_data(toml_file)  # tomllib yields a datetime.date

        out_json = tmp_path / "out.json"
        _write_data(data, out_json)  # must not raise on the date value
        reloaded = json.loads(out_json.read_text(encoding="utf-8"))
        assert reloaded["released"] == "2024-01-15"

    def test_records_for_csv_list_passthrough(self) -> None:
        assert _records_for_csv(SAMPLE_RECORDS) == SAMPLE_RECORDS

    def test_records_for_csv_single_key_list_unwrap(self) -> None:
        assert _records_for_csv({"rows": SAMPLE_RECORDS}) == SAMPLE_RECORDS

    def test_records_for_csv_single_key_dict_descent(self) -> None:
        # XML-shaped: {root: {item: [...]}} -> descend twice -> rows
        nested = {"catalog": {"book": SAMPLE_RECORDS}}
        assert _records_for_csv(nested) == SAMPLE_RECORDS

    def test_records_for_csv_multi_key_one_lossless_row(self) -> None:
        data = {"title": "Release", "version": "1.0", "owner": "Alice"}
        rows = _records_for_csv(data)
        assert rows == [data]

    def test_records_for_csv_single_scalar_dict_one_row(self) -> None:
        data = {"title": "Release"}
        assert _records_for_csv(data) == [data]

    def test_xml_coerces_numbers_and_bools(self, tmp_path: Path) -> None:
        xml_content = (
            "<catalog><book><title>A</title><price>10</price>"
            "<code>007</code><active>true</active></book></catalog>"
        )
        xml_file = tmp_path / "data.xml"
        xml_file.write_text(xml_content, encoding="utf-8")

        data = _read_data(xml_file)
        book = data["catalog"]["book"]
        assert book["price"] == 10
        assert isinstance(book["price"], int)
        assert book["code"] == "007"  # leading zero -> stays string
        assert book["active"] is True

    def test_xml_non_finite_floats_stay_strings(self, tmp_path: Path) -> None:
        # nan/inf round-trip through float() but must NOT be coerced: they corrupt
        # downstream json/toml/csv writers (invalid JSON, silent drop, empty cell).
        xml_content = "<row><a>nan</a><b>inf</b><c>-inf</c></row>"
        xml_file = tmp_path / "data.xml"
        xml_file.write_text(xml_content, encoding="utf-8")

        data = _read_data(xml_file)
        row = data["row"]
        assert row["a"] == "nan"
        assert row["b"] == "inf"
        assert row["c"] == "-inf"

    def test_unsupported_input_raises(self, tmp_path: Path) -> None:
        bad_file = tmp_path / "file.ini"
        bad_file.write_text("key = value", encoding="utf-8")
        with pytest.raises(ValueError, match="unsupported input format"):
            _read_data(bad_file)

    def test_unsupported_output_raises(self, tmp_path: Path) -> None:
        bad_out = Path("/tmp/out.ini")
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

    def test_csv_to_toml(self, tmp_path: Path) -> None:
        import tomllib

        import pandas as pd

        src = tmp_path / "data.csv"
        pd.DataFrame(SAMPLE_RECORDS).to_csv(src, index=False)
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, mime, ext = worker.convert(_make_job(6, src, "csv", "toml"))

        assert Path(out_path).exists()
        assert ext == "toml"
        assert mime == "application/toml"
        loaded = tomllib.loads(Path(out_path).read_text(encoding="utf-8"))
        assert loaded["rows"][0]["name"] == "Alice"

    def test_json_to_toml(self, tmp_path: Path) -> None:
        import tomllib

        src = tmp_path / "data.json"
        src.write_text(json.dumps(SAMPLE_RECORDS), encoding="utf-8")
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, mime, ext = worker.convert(_make_job(7, src, "json", "toml"))

        assert ext == "toml"
        loaded = tomllib.loads(Path(out_path).read_text(encoding="utf-8"))
        assert loaded["rows"][1]["name"] == "Bob"

    def test_toml_to_json(self, tmp_path: Path) -> None:
        src = tmp_path / "data.toml"
        _write_data(SAMPLE_RECORDS, src)
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, mime, ext = worker.convert(_make_job(8, src, "toml", "json"))

        assert ext == "json"
        loaded = json.loads(Path(out_path).read_text(encoding="utf-8"))
        assert loaded["rows"][0]["name"] == "Alice"

    def test_toml_to_csv(self, tmp_path: Path) -> None:
        import pandas as pd

        src = tmp_path / "data.toml"
        _write_data(SAMPLE_RECORDS, src)
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, mime, ext = worker.convert(_make_job(9, src, "toml", "csv"))

        assert ext == "csv"
        loaded = pd.read_csv(Path(out_path)).to_dict(orient="records")
        assert loaded[0]["name"] == "Alice"
        assert loaded[1]["city"] == "Almaty"

    def test_yaml_to_toml_with_date(self, tmp_path: Path) -> None:
        import datetime
        import tomllib

        import yaml

        payload = {
            "title": "Release",
            "released": datetime.date(2024, 1, 15),
            "owner": {"name": "Alice", "age": 30},
        }
        src = tmp_path / "data.yaml"
        src.write_text(yaml.dump(payload, allow_unicode=True), encoding="utf-8")
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, mime, ext = worker.convert(_make_job(10, src, "yaml", "toml"))

        assert ext == "toml"
        assert mime == "application/toml"
        # tomllib parses TOML dates back to datetime.date → full structural round-trip.
        loaded = tomllib.loads(Path(out_path).read_text(encoding="utf-8"))
        assert loaded == payload

    def test_toml_to_yaml_with_date(self, tmp_path: Path) -> None:
        import datetime

        import yaml

        src = tmp_path / "data.toml"
        src.write_text(
            'title = "Release"\nreleased = 2024-01-15\n[owner]\nname = "Bob"\nage = 25\n',
            encoding="utf-8",
        )
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, mime, ext = worker.convert(_make_job(11, src, "toml", "yaml"))

        assert ext == "yaml"
        assert mime == "application/x-yaml"
        loaded = yaml.safe_load(Path(out_path).read_text(encoding="utf-8"))
        assert loaded == {
            "title": "Release",
            "released": datetime.date(2024, 1, 15),
            "owner": {"name": "Bob", "age": 25},
        }

    def test_json_multifield_to_csv_no_column_loss(self, tmp_path: Path) -> None:
        import pandas as pd

        payload = {"title": "Release", "version": "1.0", "owner": "Alice"}
        src = tmp_path / "data.json"
        src.write_text(json.dumps(payload), encoding="utf-8")
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, _, ext = worker.convert(_make_job(20, src, "json", "csv"))

        assert ext == "csv"
        df = pd.read_csv(Path(out_path))
        assert set(df.columns) == {"title", "version", "owner"}
        assert len(df) == 1

    def test_json_scalars_beside_list_to_csv_no_field_loss(self, tmp_path: Path) -> None:
        # Defect 1: scalar siblings next to a list value must not be dropped.
        import pandas as pd

        payload = {"project": "X", "version": "1.0", "rows": SAMPLE_RECORDS}
        src = tmp_path / "data.json"
        src.write_text(json.dumps(payload), encoding="utf-8")
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, _, ext = worker.convert(_make_job(24, src, "json", "csv"))

        assert ext == "csv"
        df = pd.read_csv(Path(out_path))
        assert "project" in df.columns
        assert "version" in df.columns
        assert len(df) == 1

    def test_toml_multifield_to_csv_no_column_loss(self, tmp_path: Path) -> None:
        import pandas as pd

        src = tmp_path / "data.toml"
        src.write_text(
            'title = "Convertor"\nowner = "Alice"\n[meta]\nversion = "1.0"\n',
            encoding="utf-8",
        )
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, _, ext = worker.convert(_make_job(21, src, "toml", "csv"))

        assert ext == "csv"
        df = pd.read_csv(Path(out_path))
        assert {"title", "owner", "meta"} <= set(df.columns)
        assert df.iloc[0]["title"] == "Convertor"
        assert df.iloc[0]["owner"] == "Alice"

    def test_yaml_multifield_to_csv_no_column_loss(self, tmp_path: Path) -> None:
        import pandas as pd
        import yaml

        payload = {"title": "Release", "version": "1.0", "owner": "Alice"}
        src = tmp_path / "data.yaml"
        src.write_text(yaml.dump(payload, allow_unicode=True), encoding="utf-8")
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, _, ext = worker.convert(_make_job(22, src, "yaml", "csv"))

        assert ext == "csv"
        df = pd.read_csv(Path(out_path))
        assert set(df.columns) == {"title", "version", "owner"}
        assert len(df) == 1

    def test_xml_to_csv_tabular(self, tmp_path: Path) -> None:
        import pandas as pd

        xml_content = (
            "<catalog>"
            "<book><title>A</title><price>10</price></book>"
            "<book><title>B</title><price>20</price></book>"
            "</catalog>"
        )
        src = tmp_path / "data.xml"
        src.write_text(xml_content, encoding="utf-8")
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, _, ext = worker.convert(_make_job(23, src, "xml", "csv"))

        assert ext == "csv"
        df = pd.read_csv(Path(out_path))
        assert list(df.columns) == ["title", "price"]
        assert len(df) == 2
        assert df.iloc[0]["title"] == "A"
        assert df.iloc[0]["price"] == 10
        assert df.iloc[1]["title"] == "B"

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


# ---------------------------------------------------------------------------
# Malformed / empty inputs — _read_data must surface a parse error (the base
# class turns any raised exception into a retry/DLQ; here we pin the format).
# ---------------------------------------------------------------------------

class TestMalformedInputs:
    def test_malformed_json_raises(self, tmp_path: Path) -> None:
        bad = tmp_path / "bad.json"
        bad.write_text('{"name": "Alice", }', encoding="utf-8")  # trailing comma
        with pytest.raises(json.JSONDecodeError):
            _read_data(bad)

    def test_empty_json_raises(self, tmp_path: Path) -> None:
        empty = tmp_path / "empty.json"
        empty.write_text("", encoding="utf-8")
        with pytest.raises(json.JSONDecodeError):
            _read_data(empty)

    def test_malformed_xml_raises(self, tmp_path: Path) -> None:
        import xml.etree.ElementTree as ET

        bad = tmp_path / "bad.xml"
        bad.write_text("<root><item>unclosed</root>", encoding="utf-8")
        with pytest.raises(ET.ParseError):
            _read_data(bad)

    def test_empty_csv_raises(self, tmp_path: Path) -> None:
        import pandas as pd

        empty = tmp_path / "empty.csv"
        empty.write_text("", encoding="utf-8")
        with pytest.raises(pd.errors.EmptyDataError):
            _read_data(empty)

    def test_malformed_yaml_raises(self, tmp_path: Path) -> None:
        import yaml

        bad = tmp_path / "bad.yaml"
        bad.write_text("foo: [unterminated\n  bar: : :", encoding="utf-8")
        with pytest.raises(yaml.YAMLError):
            _read_data(bad)

    def test_malformed_json_via_convert_propagates(self, tmp_path: Path) -> None:
        src = tmp_path / "bad.json"
        src.write_text("{not valid", encoding="utf-8")
        worker = _worker(tmp_path)
        with patch("workers.data.worker.WORK_DIR", tmp_path):
            with pytest.raises(json.JSONDecodeError):
                worker.convert(_make_job(30, src, "json", "csv"))


# ---------------------------------------------------------------------------
# Full declared matrix: every (src → tgt) pair in SUPPORTED converts and
# produces a non-empty output (helper level — no worker/redis needed).
# ---------------------------------------------------------------------------

def _matrix_pairs() -> list[tuple[str, str]]:
    pairs: list[tuple[str, str]] = []
    for src, targets in SUPPORTED.items():
        for tgt in targets:
            pairs.append((src, tgt))
    return pairs


def _extract_records(obj: object) -> list:
    """Dig the list-of-records out of whatever wrapper a format imposes.

    csv/json/yaml keep a top-level list; xml wraps it as {root: {item: [...]}}
    and toml wraps a top-level array under {"rows": [...]}. Unwrap single-key
    dicts until the row list is reached; a multi-key dict is a single row.
    """
    while isinstance(obj, dict) and len(obj) == 1:
        obj = next(iter(obj.values()))
    if isinstance(obj, dict):
        return [obj]
    assert isinstance(obj, list)
    return obj


def _norm(records: list) -> list:
    # Compare on stringified scalars: CSV stringifies everything and XML coerces
    # numerics, so a cross-format value match must be type-insensitive. This
    # still catches dropped fields, swapped values, or lost rows.
    return [{str(k): str(v) for k, v in rec.items()} for rec in records]


class TestFullMatrix:
    @pytest.mark.parametrize("src_fmt,tgt_fmt", _matrix_pairs())
    def test_pair_roundtrip_preserves_records(
        self, tmp_path: Path, src_fmt: str, tgt_fmt: str
    ) -> None:
        # Seed a source file in src_fmt, convert it to tgt_fmt (read+write), then
        # read the output back and assert the records survived the round-trip.
        # A silent corruption (dropped column, lost row, mangled value) fails here.
        src = tmp_path / f"in.{src_fmt}"
        _write_data(SAMPLE_RECORDS, src)

        data = _read_data(src)
        out = tmp_path / f"out.{tgt_fmt}"
        _write_data(data, out)

        assert out.exists(), f"{src_fmt}->{tgt_fmt}: no output"
        assert out.stat().st_size > 0, f"{src_fmt}->{tgt_fmt}: empty output"

        reloaded = _extract_records(_read_data(out))
        assert _norm(reloaded) == _norm(SAMPLE_RECORDS), (
            f"{src_fmt}->{tgt_fmt}: records changed in round-trip"
        )

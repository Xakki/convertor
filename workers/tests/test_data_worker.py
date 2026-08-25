"""Tests for DataWorker.convert() — Phase 1 stream-based worker."""

import json
from pathlib import Path
from unittest.mock import patch

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


def _make_job(
    conv_id: int,
    input_path: Path,
    src_fmt: str,
    tgt_fmt: str,
    options: dict | list | None = None,
) -> dict:
    """Build a job dict. input_path is the local file convert() reads
    (base class injects it as _localInput after the S3 download).

    options defaults to [] — same on-wire shape as an empty PHP array
    (docs/queue-contract.md: "Empty = []", not {}), matching real jobs.
    """
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
        "options": [] if options is None else options,
    }


def _worker(tmp_path: Path) -> DataWorker:
    """Return a DataWorker with WORK_DIR mocked."""
    import workers.common.stream_consumer as sc_mod
    import workers.data.worker as dw_mod

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

    def test_malformed_xml_raises_value_error(self, tmp_path: Path) -> None:
        """CNV-128: ParseError не наследует ValueError — без перехвата в
        _read_data() job классифицировался бы как транзиентный и ретраился
        бы вечно (process_job() маппит ValueError -> permanent=True, всё
        остальное -> permanent=False). Сообщение обязано сохранять исходную
        деталь парсера (line/column) — иначе ошибка не actionable."""
        bad = tmp_path / "bad.xml"
        bad.write_text("<root><item>unclosed</root>", encoding="utf-8")
        with pytest.raises(ValueError, match="line 1, column 22"):
            _read_data(bad)

    def test_malformed_xml_encoding_raises_value_error(self, tmp_path: Path) -> None:
        """Тот же дефект, другой источник: LookupError на неизвестной
        кодировке в XML-декларации тоже не наследует ValueError."""
        bad = tmp_path / "bad-encoding.xml"
        bad.write_bytes(b'<?xml version="1.0" encoding="bogus-enc"?><root>x</root>')
        with pytest.raises(ValueError, match="bogus-enc"):
            _read_data(bad)

    def test_deeply_nested_xml_raises_value_error(self, tmp_path: Path) -> None:
        """CNV-128 доп. находка: well-formed, но аномально глубоко вложенный
        XML переполняет рекурсию _elem_to_dict() -> RecursionError (подкласс
        RuntimeError, не ValueError) -> без перехвата тоже ушёл бы в
        бесконечный ретрай, хотя вход постоянно непроходим."""
        n = 2000
        bad = tmp_path / "deep.xml"
        bad.write_text("<a>" * n + "x" + "</a>" * n, encoding="utf-8")
        with pytest.raises(ValueError, match="nesting too deep"):
            _read_data(bad)

    def test_malformed_xml_via_convert_propagates_value_error(
        self, tmp_path: Path
    ) -> None:
        """Тот же дефект через полный convert() — permanent=True путь в
        StreamConsumerBase.process_job() зависит от того, что наружу выходит
        именно ValueError, а не сырой ET.ParseError."""
        src = tmp_path / "bad.xml"
        src.write_text("<root><item>unclosed</root>", encoding="utf-8")
        worker = _worker(tmp_path)
        with patch("workers.data.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="malformed XML"):
                worker.convert(_make_job(31, src, "xml", "json"))

    def test_empty_csv_raises(self, tmp_path: Path) -> None:
        import pandas as pd

        empty = tmp_path / "empty.csv"
        empty.write_text("", encoding="utf-8")
        with pytest.raises(pd.errors.EmptyDataError):
            _read_data(empty)

    def test_malformed_yaml_raises(self, tmp_path: Path) -> None:
        """CNV-131: yaml.YAMLError не наследует ValueError — без перехвата
        StreamConsumerBase.process_job() относит его к TRANSIENT и ретраит
        битый YAML бесконечно. Раньше этот тест закреплял именно это (сырой
        yaml.YAMLError) как ожидаемое поведение — переписан на permanent
        ValueError, которого требует контракт (CNV-128/CNV-98/CNV-75)."""
        bad = tmp_path / "bad.yaml"
        bad.write_text("foo: [unterminated\n  bar: : :", encoding="utf-8")
        with pytest.raises(ValueError, match="malformed YAML"):
            _read_data(bad)

    def test_deeply_nested_yaml_raises_value_error(self, tmp_path: Path) -> None:
        """CNV-131 доп. находка (тот же приём, что RecursionError у CNV-128
        для XML): well-formed, но аномально глубоко вложенный YAML переполняет
        рекурсивный конструктор PyYAML -> RecursionError (подкласс
        RuntimeError, не ValueError) -> без перехвата тоже бесконечный ретрай,
        хотя вход постоянно непроходим."""
        n = 2000
        bad = tmp_path / "deep.yaml"
        bad.write_text("a: " + "[" * n + "1" + "]" * n, encoding="utf-8")
        with pytest.raises(ValueError, match="nesting too deep"):
            _read_data(bad)

    def test_malformed_yaml_via_convert_propagates_value_error(
        self, tmp_path: Path
    ) -> None:
        """Тот же дефект через полный convert() — permanent=True путь в
        StreamConsumerBase.process_job() зависит от того, что наружу выходит
        именно ValueError, а не сырой yaml.YAMLError."""
        src = tmp_path / "bad.yaml"
        src.write_text("foo: [unterminated\n  bar: : :", encoding="utf-8")
        worker = _worker(tmp_path)
        with patch("workers.data.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError, match="malformed YAML"):
                worker.convert(_make_job(32, src, "yaml", "json"))

    def test_malformed_json_via_convert_propagates(self, tmp_path: Path) -> None:
        src = tmp_path / "bad.json"
        src.write_text("{not valid", encoding="utf-8")
        worker = _worker(tmp_path)
        with patch("workers.data.worker.WORK_DIR", tmp_path):
            with pytest.raises(json.JSONDecodeError):
                worker.convert(_make_job(30, src, "json", "csv"))


# ---------------------------------------------------------------------------
# CNV-104 — CSV settings: delimiter / quote applied to CSV OUTPUT.
# ---------------------------------------------------------------------------

class TestCsvSettings:
    def _json_src(self, tmp_path: Path, payload) -> Path:
        src = tmp_path / "data.json"
        src.write_text(json.dumps(payload), encoding="utf-8")
        return src

    def test_delimiter_semicolon_changes_output_bytes(self, tmp_path: Path) -> None:
        src = self._json_src(tmp_path, SAMPLE_RECORDS)
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, _, _ = worker.convert(
                _make_job(50, src, "json", "csv", options={"delimiter": ";"})
            )

        header = Path(out_path).read_text(encoding="utf-8").splitlines()[0]
        assert header == "name;age;city"
        assert "," not in header

    def test_delimiter_literal_tab_survives_into_output(self, tmp_path: Path) -> None:
        # Literal TAB character (not an escaped name like "tab") — the real
        # catalog `value` for this select option, per CNV-103.
        src = self._json_src(tmp_path, SAMPLE_RECORDS)
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, _, _ = worker.convert(
                _make_job(51, src, "json", "csv", options={"delimiter": "\t"})
            )

        header = Path(out_path).read_text(encoding="utf-8").splitlines()[0]
        assert header == "name\tage\tcity"
        assert "\t" in Path(out_path).read_bytes().decode("utf-8")

    def test_delimiter_literal_pipe_survives_into_output(self, tmp_path: Path) -> None:
        src = self._json_src(tmp_path, SAMPLE_RECORDS)
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, _, _ = worker.convert(
                _make_job(52, src, "json", "csv", options={"delimiter": "|"})
            )

        header = Path(out_path).read_text(encoding="utf-8").splitlines()[0]
        assert header == "name|age|city"

    def test_quote_single_applied_to_field_needing_quoting(self, tmp_path: Path) -> None:
        # note contains the (default) delimiter "," -> pandas QUOTE_MINIMAL
        # quotes this field; the quote CHARACTER used must be the option.
        payload = [{"name": "Alice", "note": "Hello, World"}]
        src = self._json_src(tmp_path, payload)
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, _, _ = worker.convert(
                _make_job(53, src, "json", "csv", options={"quote": "'"})
            )

        text = Path(out_path).read_text(encoding="utf-8")
        assert "'Hello, World'" in text
        assert '"Hello, World"' not in text

    def test_quote_default_stays_double_quote(self, tmp_path: Path) -> None:
        payload = [{"name": "Alice", "note": "Hello, World"}]
        src = self._json_src(tmp_path, payload)
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, _, _ = worker.convert(_make_job(54, src, "json", "csv"))

        text = Path(out_path).read_text(encoding="utf-8")
        assert '"Hello, World"' in text

    def test_no_options_csv_output_byte_identical_to_pre_cnv104(self, tmp_path: Path) -> None:
        import pandas as pd

        src = self._json_src(tmp_path, SAMPLE_RECORDS)
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, _, _ = worker.convert(_make_job(55, src, "json", "csv"))

        expected_buf = tmp_path / "expected.csv"
        pd.DataFrame(SAMPLE_RECORDS).to_csv(expected_buf, index=False, encoding="utf-8")
        assert Path(out_path).read_bytes() == expected_buf.read_bytes()


# ---------------------------------------------------------------------------
# CNV-104 — JSON settings: pretty / indent applied to JSON output.
# ---------------------------------------------------------------------------

class TestJsonSettings:
    def _csv_src(self, tmp_path: Path) -> Path:
        import pandas as pd

        src = tmp_path / "data.csv"
        pd.DataFrame(SAMPLE_RECORDS).to_csv(src, index=False)
        return src

    def test_pretty_true_indent_4_reindents_output(self, tmp_path: Path) -> None:
        src = self._csv_src(tmp_path)
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, _, _ = worker.convert(
                _make_job(60, src, "csv", "json", options={"pretty": True, "indent": 4})
            )

        text = Path(out_path).read_text(encoding="utf-8")
        lines = text.splitlines()
        assert lines[0] == "["
        assert lines[1] == "    {"  # 4-space indent, not the default 2
        assert json.loads(text)[0]["name"] == "Alice"

    def test_pretty_false_is_single_line_compact(self, tmp_path: Path) -> None:
        src = self._csv_src(tmp_path)
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, _, _ = worker.convert(
                _make_job(61, src, "csv", "json", options={"pretty": False})
            )

        text = Path(out_path).read_text(encoding="utf-8")
        assert "\n" not in text
        assert ", " not in text  # separators=(",", ":") — no padding spaces
        assert json.loads(text)[0]["name"] == "Alice"

    def test_indent_ignored_when_pretty_false(self, tmp_path: Path) -> None:
        src = self._csv_src(tmp_path)
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, _, _ = worker.convert(
                _make_job(62, src, "csv", "json", options={"pretty": False, "indent": 6})
            )

        text = Path(out_path).read_text(encoding="utf-8")
        assert "\n" not in text  # indent=6 had no effect

    def test_indent_alone_without_pretty_key_still_applies(self, tmp_path: Path) -> None:
        # pretty absent (None) defaults to the pre-CNV-104 "already pretty"
        # behaviour, so a bare indent still takes effect.
        src = self._csv_src(tmp_path)
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, _, _ = worker.convert(
                _make_job(63, src, "csv", "json", options={"indent": 6})
            )

        lines = Path(out_path).read_text(encoding="utf-8").splitlines()
        assert lines[1] == "      {"  # 6-space indent

    def test_no_options_json_output_byte_identical_to_pre_cnv104(self, tmp_path: Path) -> None:
        src = self._csv_src(tmp_path)
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_path, _, _ = worker.convert(_make_job(64, src, "csv", "json"))

        text = Path(out_path).read_text(encoding="utf-8")
        data = json.loads(text)
        expected = json.dumps(data, ensure_ascii=False, indent=2, default=str)
        assert text == expected


# ---------------------------------------------------------------------------
# CNV-104 — YAML/TOML/XML never read/receive settings; output stays
# byte-identical regardless of what junk options a synthetic job carries.
# ---------------------------------------------------------------------------

class TestNonProfiledFormatsIgnoreOptions:
    JUNK_OPTIONS = {"delimiter": ";", "quote": "'", "pretty": True, "indent": 8}

    @pytest.mark.parametrize("tgt_fmt", ["yaml", "yml", "toml", "xml"])
    def test_target_output_ignores_options(self, tmp_path: Path, tgt_fmt: str) -> None:
        src = tmp_path / "data.json"
        src.write_text(json.dumps(SAMPLE_RECORDS), encoding="utf-8")
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            out_with, _, _ = worker.convert(
                _make_job(70, src, "json", tgt_fmt, options=self.JUNK_OPTIONS)
            )
            out_without, _, _ = worker.convert(_make_job(71, src, "json", tgt_fmt))

        assert Path(out_with).read_bytes() == Path(out_without).read_bytes()


# ---------------------------------------------------------------------------
# CNV-104 — invalid UTF-8 input: predictable worker error, NO character
# replacement (no U+FFFD fallback). Python/pandas strict decoding already
# gives this for free; these tests pin it as a permanent regression guard.
# ---------------------------------------------------------------------------

class TestInvalidUtf8NoReplacement:
    def test_invalid_utf8_csv_source_raises_unicode_decode_error(self, tmp_path: Path) -> None:
        bad = tmp_path / "bad.csv"
        bad.write_bytes(b"name,city\nAlice,\xffMoscow\n")
        with pytest.raises(UnicodeDecodeError):
            _read_data(bad)

    def test_invalid_utf8_json_source_raises_unicode_decode_error(self, tmp_path: Path) -> None:
        bad = tmp_path / "bad.json"
        bad.write_bytes(b'{"name": "Alice", "note": "\xff"}')
        with pytest.raises(UnicodeDecodeError):
            _read_data(bad)

    def test_invalid_utf8_via_convert_is_a_permanent_worker_error(self, tmp_path: Path) -> None:
        # UnicodeDecodeError IS a ValueError subclass -> StreamConsumerBase.
        # process_job() routes it through the `except ValueError` branch
        # (permanent=True, no infinite retry) with no special-case code needed.
        assert issubclass(UnicodeDecodeError, ValueError)

        bad = tmp_path / "bad.csv"
        bad.write_bytes(b"name,city\nAlice,\xffMoscow\n")
        worker = _worker(tmp_path)

        with patch("workers.data.worker.WORK_DIR", tmp_path):
            with pytest.raises(ValueError):
                worker.convert(_make_job(80, bad, "csv", "json"))


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

"""Intentionally-unsupported source formats.

`29216306410573.dwg` (AutoCAD) is an orphan fixture: no worker handles CAD
formats yet. These tests pin that as a *deliberate* gap — the xfail flips to a
failure (strict) the day a CAD worker is added and stops raising, prompting a
real conversion test instead.
"""

from pathlib import Path

import pytest

from workers.data.worker import SUPPORTED as DATA_MATRIX
from workers.ffmpeg.worker import SUPPORTED as FFMPEG_MATRIX
from workers.image.worker import _MATRIX as IMAGE_MATRIX, ImageWorker

DWG_NAME = "29216306410573.dwg"


def _job(input_path: Path, src_fmt: str, tgt_fmt: str) -> dict:
    return {
        "conversionId": 1,
        "inputBucket": "convertor-inputs",
        "inputKey": f"inputs/{input_path.name}",
        "_localInput": str(input_path),
        "originalFilename": input_path.name,
        "sourceFormat": src_fmt,
        "targetFormat": tgt_fmt,
        "category": "image",
        "isAi": False,
        "subType": None,
        "options": [],
    }


def test_dwg_fixture_exists(example_files) -> None:
    dwg = example_files / DWG_NAME
    assert dwg.is_file(), "orphan .dwg fixture missing — keep it as the unsupported-format anchor"


def test_dwg_in_no_worker_matrix() -> None:
    assert "dwg" not in IMAGE_MATRIX
    assert "dwg" not in DATA_MATRIX
    assert "dwg" not in FFMPEG_MATRIX


@pytest.mark.xfail(reason="dwg is intentionally unsupported — no CAD worker yet", raises=ValueError, strict=True)
def test_dwg_conversion_is_unsupported(build_worker, example_files) -> None:
    worker = build_worker(ImageWorker, "workers.image.worker")
    dwg = example_files / DWG_NAME
    # Raises ValueError("unsupported source format") → recorded as expected failure.
    worker.convert(_job(dwg, "dwg", "png"))

from __future__ import annotations

import hashlib
import json
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
MANIFEST_PATH = REPO_ROOT / "workers/api/schema/manifest.json"


def test_api_openapi_snapshot_matches_manifest() -> None:
    manifest = json.loads(MANIFEST_PATH.read_text(encoding="utf-8"))
    assert set(manifest) == {
        "provider", "source", "serviceVersion", "openapi", "snapshot", "sha256", "bytes"
    }
    assert manifest["provider"] == "aip-g4f"
    assert manifest["source"] == "https://aip.xakki.ru/openapi.json"

    snapshot = MANIFEST_PATH.parent / manifest["snapshot"]
    payload = snapshot.read_bytes()
    assert len(payload) == manifest["bytes"]
    assert hashlib.sha256(payload).hexdigest() == manifest["sha256"]

    schema = json.loads(payload)
    assert schema["openapi"] == manifest["openapi"]
    assert schema["info"]["version"] == manifest["serviceVersion"]

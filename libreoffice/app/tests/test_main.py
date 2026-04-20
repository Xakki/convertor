"""Integration tests for the libreoffice conversion service.

Iterates every file in libreoffice/test_source/ and runs each through all
six endpoints, validating that the output preserves the test markers
(Cyrillic header, table, code samples) common to every source file.

Usage:
    python3 test_convert.py [BASE_URL]

Environment:
    LIBREOFFICE_URL   override base URL (default http://127.0.0.1:6000)
    TEST_SOURCE       host directory holding source files (default ../test_source)
    HOST_SHARE        host path mapped to /share inside container; required
                      for path-based tests, otherwise they're skipped

Stdlib only — no pytest, no requests.
"""

import json
import mimetypes
import os
import sys
import unittest
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
BASE_URL = os.getenv("LIBREOFFICE_URL", "http://127.0.0.1:6000")
SOURCE_DIR = Path(os.getenv("TEST_SOURCE", str(ROOT / "test_source"))).resolve()
DIST_DIR = Path(os.getenv("TEST_DIST", str(ROOT / "test_dist"))).resolve()
HOST_SHARE = Path(os.getenv("HOST_SHARE")).resolve() if os.getenv("HOST_SHARE") else None
CONTAINER_SHARE = os.getenv("CONTAINER_SHARE", "/share")

# Markers present in every source file (verified by manual extraction of Test.odt).
# We intentionally avoid Cyrillic markers in the universal set because PDF text
# extraction can mangle them depending on font embedding; Cyrillic is checked
# separately for non-PDF inputs.
COMMON_MARKERS = ["TABLE1", "POST", "/contact", "Yiisoft"]
CYRILLIC_MARKER = "Тестовый заголовок"


def _multipart(file_path: Path) -> tuple[bytes, str]:
    boundary = uuid.uuid4().hex
    name = file_path.name
    mime, _ = mimetypes.guess_type(name)
    mime = mime or "application/octet-stream"
    parts = [
        f"--{boundary}\r\n".encode(),
        f'Content-Disposition: form-data; name="file"; filename="{name}"\r\n'.encode(),
        f"Content-Type: {mime}\r\n\r\n".encode(),
        file_path.read_bytes(),
        f"\r\n--{boundary}--\r\n".encode(),
    ]
    return b"".join(parts), boundary


def post_upload(url: str, file_path: Path, timeout: int = 240):
    body, boundary = _multipart(file_path)
    req = urllib.request.Request(
        url, data=body,
        headers={"Content-Type": f"multipart/form-data; boundary={boundary}"},
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return resp.status, resp.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()


def post_form(url: str, fields: dict, timeout: int = 240):
    body = urllib.parse.urlencode(fields).encode()
    req = urllib.request.Request(
        url, data=body,
        headers={"Content-Type": "application/x-www-form-urlencoded"},
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return resp.status, resp.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()


def gather_sources() -> list[Path]:
    return sorted(p for p in SOURCE_DIR.iterdir()
                  if p.is_file() and not p.name.startswith("."))


def reset_dist_dir() -> None:
    """Wipe + recreate DIST_DIR so each run starts fresh."""
    if DIST_DIR.exists():
        for entry in DIST_DIR.iterdir():
            if entry.is_file() or entry.is_symlink():
                entry.unlink()
            else:
                import shutil
                shutil.rmtree(entry)
    else:
        DIST_DIR.mkdir(parents=True, exist_ok=True)


def dist_path(src: Path, endpoint: str, ext: str) -> Path:
    """Unique output path: <stem>.<src-ext>.<endpoint>.<out-ext>"""
    return DIST_DIR / f"{src.stem}{src.suffix}.{endpoint}.{ext}"


def save_dist(src: Path, endpoint: str, ext: str, body: bytes) -> Path:
    out = dist_path(src, endpoint, ext)
    out.write_bytes(body)
    return out


class HealthTest(unittest.TestCase):
    def test_health(self):
        with urllib.request.urlopen(f"{BASE_URL}/health", timeout=10) as r:
            self.assertEqual(r.status, 200)
            self.assertIn(b"ok", r.read())


class UploadConversionTest(unittest.TestCase):
    """POST file as multipart, expect converted body back."""

    @classmethod
    def setUpClass(cls):
        cls.sources = gather_sources()
        if not cls.sources:
            raise unittest.SkipTest(f"no sources in {SOURCE_DIR}")

    def _check_text(self, body: bytes, src: Path, kind: str):
        text = body.decode("utf-8", "replace")
        missing = [m for m in COMMON_MARKERS if m not in text]
        if missing:
            self.fail(
                f"{src.name} ({src.stat().st_size} bytes) -> {kind} "
                f"({len(text)} chars): missing markers {missing}\n"
                f"--- output preview (first 600) ---\n{text[:600]}\n---\n"
                f"Hint: the source file may be structurally damaged or hit a parser "
                f"limitation. Try re-exporting it from a known-good source."
            )
        # Cyrillic only for non-PDF inputs (PDF text extraction depends on font embedding)
        if src.suffix.lower() != ".pdf":
            self.assertIn(CYRILLIC_MARKER, text,
                f"{src.name} -> {kind}: missing Cyrillic marker '{CYRILLIC_MARKER}'")

    def test_doc2docxOld(self):
        for src in self.sources:
            with self.subTest(src=src.name):
                status, body = post_upload(f"{BASE_URL}/doc2docxOld", src)
                if status == 200:
                    save_dist(src, "doc2docxOld", "docx", body)
                self.assertEqual(status, 200, body[:300])
                self.assertEqual(body[:2], b"PK",
                    f"{src.name} -> docx: not a ZIP (first bytes={body[:8]!r})")
                self.assertGreater(len(body), 1000,
                    f"{src.name} -> docx: too small ({len(body)} bytes)")

    def test_doc2textOld(self):
        for src in self.sources:
            with self.subTest(src=src.name):
                status, body = post_upload(f"{BASE_URL}/doc2textOld", src)
                if status == 200:
                    save_dist(src, "doc2textOld", "txt", body)
                self.assertEqual(status, 200, body[:300])
                self._check_text(body, src, "txt")

    def test_doc2mdOld(self):
        for src in self.sources:
            with self.subTest(src=src.name):
                status, body = post_upload(f"{BASE_URL}/doc2mdOld", src)
                if status == 200:
                    save_dist(src, "doc2mdOld", "md", body)
                self.assertEqual(status, 200, body[:300])
                self._check_text(body, src, "md")
                if src.suffix.lower() != ".pdf":
                    # GFM table syntax — pdf path bypasses pandoc, so skip table check there.
                    text = body.decode("utf-8", "replace")
                    self.assertIn("|", text,
                        f"{src.name} -> md: no table separator (pandoc gfm)")


class SharedPathConversionTest(unittest.TestCase):
    """POST 'file=/share/<name>', expect JSON + output file written next to source."""

    @classmethod
    def setUpClass(cls):
        if HOST_SHARE is None:
            raise unittest.SkipTest("HOST_SHARE not set; skipping path-based tests")
        cls.sources = gather_sources()
        if not cls.sources:
            raise unittest.SkipTest(f"no sources in {SOURCE_DIR}")

    def _post_and_check(self, src: Path, endpoint: str, ext: str):
        container_path = f"{CONTAINER_SHARE.rstrip('/')}/{src.name}"
        status, body = post_form(f"{BASE_URL}{endpoint}", {"file": container_path})
        self.assertEqual(status, 200,
            f"{endpoint} {src.name}: HTTP {status} body={body[:300]!r}")
        try:
            payload = json.loads(body)
        except json.JSONDecodeError:
            self.fail(f"{endpoint} {src.name}: not JSON: {body[:200]!r}")
        self.assertEqual(payload.get("code"), 0,
            f"{endpoint} {src.name}: code != 0, payload={payload}")
        out_host = HOST_SHARE / f"{src.stem}.{ext}"
        self.assertTrue(out_host.exists(),
            f"{endpoint} {src.name}: expected output {out_host} not found")
        self.assertGreater(out_host.stat().st_size, 0,
            f"{endpoint} {src.name}: output {out_host} is empty")
        # Mirror the produced file into DIST_DIR so all conversion outputs
        # (upload + path-based) land in one inspectable place.
        save_dist(src, endpoint.lstrip("/"), ext, out_host.read_bytes())
        return out_host

    def test_doc2docx(self):
        for src in self.sources:
            if src.suffix.lower() == ".docx":
                continue  # don't convert into self
            with self.subTest(src=src.name):
                out = self._post_and_check(src, "/doc2docx", "docx")
                self.assertEqual(out.read_bytes()[:2], b"PK")

    def test_doc2text(self):
        for src in self.sources:
            with self.subTest(src=src.name):
                out = self._post_and_check(src, "/doc2text", "txt")
                text = out.read_text(encoding="utf-8", errors="replace")
                for marker in COMMON_MARKERS:
                    self.assertIn(marker, text)

    def test_doc2md(self):
        for src in self.sources:
            with self.subTest(src=src.name):
                out = self._post_and_check(src, "/doc2md", "md")
                text = out.read_text(encoding="utf-8", errors="replace")
                for marker in COMMON_MARKERS:
                    self.assertIn(marker, text)


class SecurityTest(unittest.TestCase):
    def test_path_traversal_rejected(self):
        for endpoint in ("/doc2docx", "/doc2text", "/doc2md"):
            with self.subTest(endpoint=endpoint):
                status, body = post_form(f"{BASE_URL}{endpoint}", {"file": "/etc/passwd"})
                self.assertEqual(status, 400,
                    f"{endpoint}: expected 400 for /etc/passwd, got {status} body={body[:200]!r}")


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1].startswith("http"):
        BASE_URL = sys.argv.pop(1)
    DIST_DIR.mkdir(parents=True, exist_ok=True)
    reset_dist_dir()
    print(f"BASE_URL    = {BASE_URL}")
    print(f"SOURCE_DIR  = {SOURCE_DIR}")
    print(f"DIST_DIR    = {DIST_DIR} (cleaned)")
    print(f"HOST_SHARE  = {HOST_SHARE} (path-based tests {'enabled' if HOST_SHARE else 'skipped'})")
    print(f"sources     = {[p.name for p in gather_sources()]}")
    print()
    runner = unittest.TextTestRunner(verbosity=2)
    result = runner.run(unittest.defaultTestLoader.loadTestsFromModule(sys.modules[__name__]))
    produced = sorted(DIST_DIR.iterdir()) if DIST_DIR.exists() else []
    print(f"\n=== Produced {len(produced)} files in {DIST_DIR} ===")
    for p in produced:
        print(f"  {p.stat().st_size:>10} B  {p.name}")
    sys.exit(0 if result.wasSuccessful() else 1)

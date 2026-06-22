"""Unit tests for safe_share_path — path-traversal guard (workers/common/safe_path.py).

Note: the helper is currently unused by the stream workers (inputs are pulled
from S3, not a shared volume), but it remains the canonical traversal guard and
must stay correct. See grooming note in the worker-conversion-tests card.
"""

from pathlib import Path

import pytest

from workers.common.safe_path import safe_share_path


class TestSafeSharePath:
    def test_relative_path_resolves_inside(self, tmp_path: Path) -> None:
        resolved = safe_share_path("sub/file.txt", tmp_path)
        assert resolved == (tmp_path / "sub" / "file.txt").resolve()
        assert tmp_path.resolve() in resolved.parents

    def test_absolute_path_inside_is_allowed(self, tmp_path: Path) -> None:
        target = tmp_path / "ok.bin"
        resolved = safe_share_path(str(target), tmp_path)
        assert resolved == target.resolve()

    def test_share_dir_itself_is_allowed(self, tmp_path: Path) -> None:
        assert safe_share_path(str(tmp_path), tmp_path) == tmp_path.resolve()

    def test_relative_dotdot_escape_raises(self, tmp_path: Path) -> None:
        with pytest.raises(ValueError, match="path escapes"):
            safe_share_path("../../etc/passwd", tmp_path)

    def test_absolute_outside_path_raises(self, tmp_path: Path) -> None:
        with pytest.raises(ValueError, match="path escapes"):
            safe_share_path("/etc/passwd", tmp_path)

    def test_sibling_prefix_collision_raises(self, tmp_path: Path) -> None:
        # /share-evil must NOT be accepted just because it shares the "/share" prefix.
        share = tmp_path / "share"
        share.mkdir()
        evil = tmp_path / "share-evil" / "x"
        with pytest.raises(ValueError, match="path escapes"):
            safe_share_path(str(evil), share)

    def test_symlink_escape_raises(self, tmp_path: Path) -> None:
        share = tmp_path / "share"
        share.mkdir()
        outside = tmp_path / "secret.txt"
        outside.write_text("top secret", encoding="utf-8")
        link = share / "link.txt"
        link.symlink_to(outside)  # resolves out of share_dir
        with pytest.raises(ValueError, match="path escapes"):
            safe_share_path(str(link), share)

"""Path traversal protection utilities for shared file access."""

from pathlib import Path


def safe_share_path(raw: str, share_dir: Path) -> Path:
    """Resolve *raw* to an absolute path confined within *share_dir*.

    Raises ValueError if the resolved path escapes *share_dir*.
    Mirrors the same guard used in libreoffice/app/main.py.
    """
    candidate = Path(raw)
    if not candidate.is_absolute():
        candidate = share_dir / candidate
    resolved = candidate.resolve()
    share_resolved = share_dir.resolve()
    if resolved != share_resolved and share_resolved not in resolved.parents:
        raise ValueError(f"path escapes {share_dir}: {raw!r}")
    return resolved

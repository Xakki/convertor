"""AI worker entry point.

Modes:
  worker     — production pull-loop (default), gated by PULL_ENABLED.
  devserver  — local dev FastAPI server + web UI (ai-worker-devserver).

Usage:
  python -m workers.ai             # worker mode
  python -m workers.ai worker
  python -m workers.ai devserver   # dev-server (also: python -m workers.ai --devserver)
"""

from __future__ import annotations

import logging
import sys

from workers.ai.config import load_config
from workers.ai.worker import run as run_worker


def main(argv: list[str] | None = None) -> int:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )
    args = sys.argv[1:] if argv is None else argv
    mode = args[0] if args else "worker"

    # Accept both the positional `devserver` and the `--devserver` flag (card AC).
    if mode == "devserver" or "--devserver" in args:
        from workers.ai.devserver.app import serve  # lazy: keeps prod worker fastapi-free

        serve()
        return 0

    if mode == "worker":
        run_worker(load_config())
        return 0

    logging.getLogger(__name__).error("unknown mode %r (expected: worker | devserver)", mode)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())

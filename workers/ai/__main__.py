"""AI worker entry point.

Modes:
  worker     — production pull-loop (default), gated by PULL_ENABLED.
  devserver  — local dev HTTP server (DEFERRED — ai-worker-devserver). Stub only.

Usage:
  python -m workers.ai            # worker mode
  python -m workers.ai worker
  python -m workers.ai devserver  # not implemented yet
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

    if mode == "worker":
        run_worker(load_config())
        return 0

    if mode == "devserver":
        # DEFERRED — implemented in ai-worker-devserver. Hook left intentionally empty.
        logging.getLogger(__name__).error(
            "devserver mode is not implemented yet (ai-worker-devserver)"
        )
        return 2

    logging.getLogger(__name__).error("unknown mode %r (expected: worker | devserver)", mode)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())

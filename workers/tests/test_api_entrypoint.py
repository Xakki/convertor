from __future__ import annotations

import runpy


def test_api_entrypoint_configures_logging_before_worker_run(monkeypatch) -> None:
    events: list[str] = []

    monkeypatch.setattr(
        "workers.common.logging_config.configure_logging",
        lambda: events.append("configure_logging"),
    )
    monkeypatch.setattr("workers.api.worker.run", lambda: events.append("run"))

    runpy.run_module("workers.api.__main__", run_name="__main__")

    assert events == ["configure_logging", "run"]

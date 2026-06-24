from __future__ import annotations

import csv
import json
from pathlib import Path


class ReportWriter:

    def __init__(
        self,
        report_dir: Path,
    ):
        self.report_dir = report_dir
        self.report_dir.mkdir(
            parents=True,
            exist_ok=True,
        )

    def save_json(
        self,
        data,
    ) -> None:

        path = self.report_dir / "report.json"

        path.write_text(
            json.dumps(
                data,
                ensure_ascii=False,
                indent=2,
            ),
            encoding="utf-8",
        )

    def save_csv(
        self,
        rows,
    ) -> None:

        if not rows:
            return

        path = self.report_dir / "report.csv"

        with path.open(
            "w",
            newline="",
            encoding="utf-8",
        ) as fp:

            writer = csv.DictWriter(
                fp,
                fieldnames=list(rows[0].keys()),
            )

            writer.writeheader()

            for row in rows:
                writer.writerow(row)

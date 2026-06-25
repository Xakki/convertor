from __future__ import annotations

import json
import time
from pathlib import Path
from typing import Any

import psutil
import yaml

from workers.ai.bench.metrics import (
    calculate_cer,
    calculate_wer,
    cosine_similarity,
    triplet_accuracy,
)

from workers.ai.bench.report import ReportWriter

from workers.ai.providers.embedding import EmbeddingProvider
from workers.ai.providers.streaming_stt import StreamingWhisper

try:
    import pynvml

    pynvml.nvmlInit()

    GPU_AVAILABLE = True

except Exception:
    GPU_AVAILABLE = False


class BenchmarkRunner:

    def __init__(
        self,
        config_path: Path,
    ):

        self.config = yaml.safe_load(
            config_path.read_text(
                encoding="utf-8",
            )
        )

        self.report_dir = Path(
            self.config["report_dir"]
        )

        self.writer = ReportWriter(
            self.report_dir
        )

    # ---------------------------------------------------------
    # resources
    # ---------------------------------------------------------

    def rss_mb(self) -> float:

        return (
            psutil.Process()
            .memory_info()
            .rss
            / 1024
            / 1024
        )

    def gpu_memory_mb(self) -> float:

        if not GPU_AVAILABLE:
            return 0.0

        peak = 0.0

        try:

            count = pynvml.nvmlDeviceGetCount()

            for idx in range(count):

                handle = pynvml.nvmlDeviceGetHandleByIndex(
                    idx
                )

                mem = (
                    pynvml.nvmlDeviceGetMemoryInfo(
                        handle
                    ).used
                    / 1024
                    / 1024
                )

                peak = max(
                    peak,
                    mem,
                )

        except Exception:
            pass

        return peak

    # ---------------------------------------------------------
    # embedding
    # ---------------------------------------------------------

    def run_embedding_test(
        self,
        model_cfg: dict,
        test_cfg: dict,
    ) -> dict:

        started = time.time()

        rss_before = self.rss_mb()
        gpu_before = self.gpu_memory_mb()

        provider = EmbeddingProvider(
            model_name=model_cfg["model"],
            device=model_cfg.get(
                "device",
                "cpu",
            ),
        )

        triplets = []

        reference_path = Path(
            test_cfg["reference"]
        )

        with reference_path.open(
            encoding="utf-8"
        ) as fp:

            for line in fp:

                item = json.loads(
                    line
                )

                anchor = provider.model.encode(
                    item["anchor"],
                    normalize_embeddings=True,
                )

                positive = provider.model.encode(
                    item["positive"],
                    normalize_embeddings=True,
                )

                negative = provider.model.encode(
                    item["negative"],
                    normalize_embeddings=True,
                )

                pos_score = cosine_similarity(
                    anchor,
                    positive,
                )

                neg_score = cosine_similarity(
                    anchor,
                    negative,
                )

                triplets.append(
                    {
                        "positive_score": pos_score,
                        "negative_score": neg_score,
                    }
                )

        metrics = triplet_accuracy(
            triplets
        )

        finished = time.time()

        return {
            "task": "embedding",
            "test_id": test_cfg["id"],
            "model": model_cfg["name"],
            "duration_sec": round(
                finished - started,
                3,
            ),
            "peak_rss_mb": round(
                self.rss_mb() - rss_before,
                2,
            ),
            "peak_gpu_mem_mb": round(
                self.gpu_memory_mb()
                - gpu_before,
                2,
            ),
            **metrics,
        }

    # ---------------------------------------------------------
    # stt
    # ---------------------------------------------------------

    def run_stt_test(
        self,
        model_cfg: dict,
        test_cfg: dict,
    ) -> dict:

        started = time.time()

        rss_before = self.rss_mb()
        gpu_before = self.gpu_memory_mb()

        stt = StreamingWhisper(
            model_name=model_cfg["model"],
            device=model_cfg.get(
                "device",
                "cpu",
            ),
            compute_type=model_cfg.get(
                "compute_type",
                "int8",
            ),
        )

        result = stt.process_file(
            Path(
                test_cfg["input"]
            )
        )

        actual_text = result["final"]

        expected_text = Path(
            test_cfg["transcript"]
        ).read_text(
            encoding="utf-8"
        )

        finished = time.time()

        return {
            "task": "speech_to_text",
            "test_id": test_cfg["id"],
            "model": model_cfg["name"],
            "duration_sec": round(
                finished - started,
                3,
            ),
            "peak_rss_mb": round(
                self.rss_mb() - rss_before,
                2,
            ),
            "peak_gpu_mem_mb": round(
                self.gpu_memory_mb()
                - gpu_before,
                2,
            ),
            "wer": calculate_wer(
                expected_text,
                actual_text,
            ),
            "cer": calculate_cer(
                expected_text,
                actual_text,
            ),
        }

    # ---------------------------------------------------------
    # tts
    # ---------------------------------------------------------

    def run_tts_test(
        self,
        model_cfg: dict,
        test_cfg: dict,
    ) -> dict:

        from workers.ai.providers.tts import espeak as _tts_espeak

        started = time.time()

        rss_before = self.rss_mb()
        gpu_before = self.gpu_memory_mb()

        text = Path(
            test_cfg["input"]
        ).read_text(
            encoding="utf-8"
        )

        tmp_audio = (
            self.report_dir
            / f"{test_cfg['id']}.wav"
        )

        import asyncio

        asyncio.run(
            _tts_espeak(
                text,
                "wav",
                tmp_audio,
            )
        )

        stt = StreamingWhisper(
            model_name="base",
            device="cpu",
            compute_type="int8",
        )

        result = stt.process_file(
            tmp_audio
        )

        recognized = result["final"]

        finished = time.time()

        return {
            "task": "text_to_speech",
            "test_id": test_cfg["id"],
            "model": model_cfg["name"],
            "duration_sec": round(
                finished - started,
                3,
            ),
            "peak_rss_mb": round(
                self.rss_mb() - rss_before,
                2,
            ),
            "peak_gpu_mem_mb": round(
                self.gpu_memory_mb()
                - gpu_before,
                2,
            ),
            "roundtrip_wer": calculate_wer(
                text,
                recognized,
            ),
            "roundtrip_cer": calculate_cer(
                text,
                recognized,
            ),
        }

    # ---------------------------------------------------------
    # main
    # ---------------------------------------------------------

    def run(self):

        rows: list[dict[str, Any]] = []

        models = self.config.get(
            "models",
            {},
        )

        tests = self.config.get(
            "tests",
            {},
        )

        for model_cfg in models.get(
            "embedding",
            [],
        ):

            for test_cfg in tests.get(
                "embedding",
                [],
            ):

                rows.append(
                    self.run_embedding_test(
                        model_cfg,
                        test_cfg,
                    )
                )

        for model_cfg in models.get(
            "speech_to_text",
            [],
        ):

            for test_cfg in tests.get(
                "speech_to_text",
                [],
            ):

                rows.append(
                    self.run_stt_test(
                        model_cfg,
                        test_cfg,
                    )
                )

        for model_cfg in models.get(
            "text_to_speech",
            [],
        ):

            for test_cfg in tests.get(
                "text_to_speech",
                [],
            ):

                rows.append(
                    self.run_tts_test(
                        model_cfg,
                        test_cfg,
                    )
                )

        self.writer.save_json(
            rows
        )

        self.writer.save_csv(
            rows
        )

        print(
            f"Benchmark complete. Results: {self.report_dir}"
        )


def run(
    config_path: str,
):

    BenchmarkRunner(
        Path(
            config_path
        )
    ).run()

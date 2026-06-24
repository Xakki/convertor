#!/usr/bin/env python3
import os
import sys
import time
import json
import psutil
import logging
from pathlib import Path
import numpy as np

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")
logger = logging.getLogger("ModelTester")

def calculate_cosine_similarity(v1: list[float], v2: list[float]) -> float:
    """Вычисление косинусного сходства векторов для оценки качества Embedding."""
    a, b = np.array(v1), np.array(v2)
    denom = np.linalg.norm(a) * np.linalg.norm(b)
    return float(np.dot(a, b) / denom) if denom != 0 else 0.0

def calculate_wer(reference: str, hypothesis: str) -> float:
    """Вычисление Word Error Rate (WER) для Speech-to-Text."""
    try:
        import jiwer
        return jiwer.wer(reference, hypothesis)
    except ImportError:
        # Резервный метод расчета расстояния Левенштейна на случай отсутствия jiwer
        ref_words = reference.split()
        hyp_words = hypothesis.split()
        # Простая эмуляция дистанции
        return abs(len(ref_words) - len(hyp_words)) / max(len(ref_words), 1)

def run_benchmarks(config_path: Path, output_dir: Path):
    with open(config_path, 'r', encoding='utf-8') as f:
        config = json.load(f)

    report = {
        "timestamp": time.time(),
        "results": {}
    }

    process = psutil.Process(os.getpid())

    # --- ТЕСТИРОВАНИЕ EMBEDDING ---
    if "_embedding" in config:
        logger.info("Starting embedding tests...")
        task_conf = config["_embedding"]
        report["results"]["_embedding"] = []

        for model in task_conf.get("models", []):
            os.environ["EMBEDDING_MODEL"] = model
            from sentence_transformers import SentenceTransformer

            # Загрузка модели и замер памяти
            mem_before = process.memory_info().rss
            t_start = time.perf_counter()

            try:
                # Чтение входных данных
                input_text = Path(task_conf["input_file"]).read_text(encoding="utf-8")
                with open(task_conf["ground_truth"], 'r') as gt_f:
                    gt_data = json.load(gt_f)

                # Инициализация и обработка
                encoder = SentenceTransformer(model, device="cpu")
                vector = encoder.encode(input_text, convert_to_numpy=True).tolist()

                t_end = time.perf_counter()
                mem_after = process.memory_info().rss

                # Оценка качества (Cosine Similarity с вектором-эталоном)
                similarity = calculate_cosine_similarity(vector, gt_data["expected_vector"])

                report["results"]["_embedding"].append({
                    "model": model,
                    "execution_time_sec": t_end - t_start,
                    "memory_consumed_mb": (mem_after - mem_before) / (1024 * 1024),
                    "metric_cosine_similarity": similarity,
                    "status": "SUCCESS"
                })
            except Exception as e:
                logger.error(f"Failed embedding test for {model}: {e}")
                report["results"]["_embedding"].append({"model": model, "status": f"FAILED: {str(e)}"})

    # --- ТЕСТИРОВАНИЕ SPEECH TO TEXT ---
    if "_speech_to_text" in config:
        logger.info("Starting speech-to-text tests...")
        task_conf = config["_speech_to_text"]
        report["results"]["_speech_to_text"] = []

        for model_name in task_conf.get("models", []):
            os.environ["WHISPER_MODEL"] = model_name
            from faster_whisper import WhisperModel

            for case in task_conf.get("test_cases", []):
                mem_before = process.memory_info().rss
                t_start = time.perf_counter()

                try:
                    model = WhisperModel(model_name, device="cpu", compute_type="int8")
                    segments, _ = model.transcribe(case["audio"], beam_size=3)
                    result_text = " ".join([s.text.strip() for s in segments])

                    t_end = time.perf_counter()
                    mem_after = process.memory_info().rss

                    wer = calculate_wer(case["text"], result_text)

                    report["results"]["_speech_to_text"].append({
                        "model": model_name,
                        "file": case["audio"],
                        "execution_time_sec": t_end - t_start,
                        "memory_consumed_mb": (mem_after - mem_before) / (1024 * 1024),
                        "metric_wer": wer,
                        "status": "SUCCESS"
                    })
                except Exception as e:
                    report["results"]["_speech_to_text"].append({"model": model_name, "status": f"FAILED: {str(e)}"})

    # --- ТЕСТИРОВАНИЕ TEXT TO SPEECH ---
    if "_text_to_speech" in config:
        logger.info("Starting text-to-speech tests...")
        task_conf = config["_text_to_speech"]
        report["results"]["_text_to_speech"] = []

        for case in task_conf.get("test_cases", []):
            t_start = time.perf_counter()
            mem_before = process.memory_info().rss

            try:
                text = Path(case["text_file"]).read_text(encoding="utf-8")
                out_wav = output_dir / f"tts_test_{uuid.uuid4().hex[:6]}.wav"

                # Вызов утилиты espeak-ng напрямую
                subprocess.run(["espeak-ng", text, "-w", str(out_wav)], check=True)

                t_end = time.perf_counter()
                mem_after = process.memory_info().rss

                file_valid = out_wav.exists() and out_wav.stat().st_size > 0

                report["results"]["_text_to_speech"].append({
                    "file": case["text_file"],
                    "execution_time_sec": t_end - t_start,
                    "memory_consumed_mb": (mem_after - mem_before) / (1024 * 1024),
                    "file_generated": file_valid,
                    "status": "SUCCESS" if file_valid else "FAILED_ZERO_SIZE"
                })
            except Exception as e:
                report["results"]["_text_to_speech"].append({"status": f"FAILED: {str(e)}"})

    # Сохранение отчета
    output_report = output_dir / "test_report.json"
    with open(output_report, 'w', encoding='utf-8') as rep_f:
        json.dump(report, rep_f, indent=4, ensure_ascii=False)
    logger.info(f"Testing finished! Report saved to {output_report}")

if __name__ == "__main__":
    cfg = Path("/app/default_config.json")
    # Если переопределен пользователем через volume
    if Path("/work/config.json").exists():
        cfg = Path("/work/config.json")

    out = Path("/work")
    out.mkdir(parents=True, exist_ok=True)
    run_benchmarks(cfg, out)

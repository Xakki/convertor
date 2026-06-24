from __future__ import annotations

import numpy as np

from jiwer import wer
from jiwer import cer


def calculate_wer(
    expected: str,
    actual: str,
) -> float:
    return float(
        wer(expected, actual)
    )


def calculate_cer(
    expected: str,
    actual: str,
) -> float:
    return float(
        cer(expected, actual)
    )


def cosine_similarity(
    a,
    b,
):
    a = np.array(a)
    b = np.array(b)

    return float(
        np.dot(a, b)
        /
        (
            np.linalg.norm(a)
            *
            np.linalg.norm(b)
        )
    )


def triplet_accuracy(
    triplets,
):
    total = len(triplets)

    if total == 0:
        return 0.0

    correct = 0

    margins = []

    for item in triplets:

        pos = item["positive_score"]
        neg = item["negative_score"]

        if pos > neg:
            correct += 1

        margins.append(pos - neg)

    return {
        "accuracy": correct / total,
        "mean_margin": float(np.mean(margins)),
        "median_margin": float(np.median(margins)),
        "p10_margin": float(np.percentile(margins, 10)),
    }

from __future__ import annotations

import json
from pathlib import Path
from typing import Any


class EmbeddingProvider:

    def __init__(
        self,
        model_name: str,
        device: str = "cpu",
    ) -> None:
        from sentence_transformers import SentenceTransformer

        self.model_name = model_name
        self.device = device
        self.model = SentenceTransformer(
            model_name,
            device=device,
        )

    def embed_document(
        self,
        text: str,
    ) -> dict[str, Any]:

        vector = self.model.encode(
            text,
            normalize_embeddings=True,
        )

        return {
            "model": self.model_name,
            "dimensions": len(vector),
            "embedding": vector.tolist(),
        }

    def embed_chunks(
        self,
        text: str,
        chunk_size: int = 1000,
        overlap: int = 200,
    ) -> dict[str, Any]:

        chunks: list[str] = []

        pos = 0

        while pos < len(text):
            chunks.append(text[pos:pos + chunk_size])
            pos += chunk_size - overlap

        vectors = self.model.encode(
            chunks,
            normalize_embeddings=True,
        )

        result_chunks = []

        for chunk, vector in zip(chunks, vectors):
            result_chunks.append(
                {
                    "text": chunk,
                    "embedding": vector.tolist(),
                }
            )

        return {
            "model": self.model_name,
            "chunks": result_chunks,
        }


def generate_embedding(
    src: Path,
    out_path: Path,
    model_name: str,
    device: str = "cpu",
    mode: str = "document",
    chunk_size: int = 1000,
    overlap: int = 200,
) -> None:

    text = src.read_text(
        encoding="utf-8",
    )

    provider = EmbeddingProvider(
        model_name=model_name,
        device=device,
    )

    if mode == "chunked":
        result = provider.embed_chunks(
            text,
            chunk_size=chunk_size,
            overlap=overlap,
        )
    else:
        result = provider.embed_document(
            text,
        )

    out_path.write_text(
        json.dumps(result, ensure_ascii=False),
        encoding="utf-8",
    )

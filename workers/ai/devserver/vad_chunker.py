"""Потоковый VAD-разбивщик PCM (s16le, 16 кГц, моно) на речевые сегменты.

Использует webrtcvad (webrtcvad-wheels) — лёгкое C-расширение, не требующее torch.
Логика: накапливать speech-фреймы; N подряд идущих тихих фреймов = граница сегмента.
Каждый сегмент передаётся в transcribe_pcm() — размер ограничен, O(1) на вызов.
"""

from __future__ import annotations

FRAME_MS = 30       # webrtcvad поддерживает 10/20/30 мс
SAMPLE_RATE = 16000
FRAME_SAMPLES = SAMPLE_RATE * FRAME_MS // 1000   # 480 samples
FRAME_BYTES = FRAME_SAMPLES * 2                   # 960 bytes (s16le)


class VadChunker:
    """Потоковый VAD-разбивщик: push(pcm) → list[сегмент], flush() → хвост или None."""

    def __init__(
        self,
        aggressiveness: int = 2,
        silence_frames: int = 10,
        max_segment_sec: float = 30.0,
        overlap_sec: float = 0.0,
        sample_rate: int = SAMPLE_RATE,
    ) -> None:
        import webrtcvad
        self._vad = webrtcvad.Vad(aggressiveness)
        self._silence_frames = silence_frames
        self._max_frames = int(max_segment_sec * 1000 / FRAME_MS)
        self._overlap_bytes = int(overlap_sec * sample_rate * 2)
        self._sample_rate = sample_rate

        self._tail: bytearray = bytearray()       # байты, ещё не собравшиеся в фрейм
        self._seg: bytearray = bytearray()        # текущий накапливаемый сегмент
        self._in_speech: bool = False
        self._silence_count: int = 0
        self._seg_frames: int = 0
        self._overlap_carry: bytes = b""          # хвост предыдущего сегмента → контекст

    def push(self, pcm: bytes) -> list[bytes]:
        """Принять очередной PCM-чанк; вернуть список готовых сегментов (может быть [])."""
        self._tail.extend(pcm)
        segments: list[bytes] = []

        while len(self._tail) >= FRAME_BYTES:
            frame = bytes(self._tail[:FRAME_BYTES])
            del self._tail[:FRAME_BYTES]
            is_speech = self._vad.is_speech(frame, self._sample_rate)

            if is_speech:
                if not self._in_speech:
                    # Начало нового сегмента — приклеить overlap из предыдущего
                    self._seg = bytearray(self._overlap_carry)
                    self._seg_frames = 0
                self._in_speech = True
                self._silence_count = 0
                self._seg.extend(frame)
                self._seg_frames += 1
                # Принудительный сброс при непрерывной речи без пауз
                if self._seg_frames >= self._max_frames:
                    segments.append(self._emit_segment())
            elif self._in_speech:
                self._silence_count += 1
                self._seg.extend(frame)
                self._seg_frames += 1
                if (
                    self._silence_count >= self._silence_frames
                    or self._seg_frames >= self._max_frames
                ):
                    seg = self._emit_segment()
                    segments.append(seg)

        return segments

    def flush(self) -> bytes | None:
        """Вернуть незавершённый сегмент при stop (хвост сессии)."""
        if self._in_speech and self._seg:
            return self._emit_segment()
        return None

    @property
    def resident_bytes(self) -> int:
        """Текущий размер внутреннего буфера (tail + seg)."""
        return len(self._tail) + len(self._seg)

    def _emit_segment(self) -> bytes:
        seg = bytes(self._seg)
        if self._overlap_bytes:
            self._overlap_carry = seg[-self._overlap_bytes:]
        else:
            self._overlap_carry = b""
        self._seg = bytearray()
        self._seg_frames = 0
        self._in_speech = False
        self._silence_count = 0
        return seg

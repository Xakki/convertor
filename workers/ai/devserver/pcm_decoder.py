"""Потоковый декодер аудио-контейнеров (WebM/Opus и др.) → s16le PCM через PyAV.

Один персистентный контейнер (demuxer/decoder) на сессию. Браузер MediaRecorder
отправляет audio/webm;codecs=opus потоком: первый фрагмент содержит EBML-заголовок,
последующие — кластеры. Переоткрывать контейнер на каждый фрагмент нельзя.
Решение: PyAV читает байты через _BytePipe — файло-подобный блокирующий буфер,
который main-поток питает методом feed(), а decode-поток дренирует через av.open().
"""

from __future__ import annotations

import logging
import threading

logger = logging.getLogger(__name__)


class _BytePipe:
    """Потокобезопасный файло-подобный буфер с блокирующим read() для PyAV."""

    def __init__(self) -> None:
        self._buf: bytearray = bytearray()
        self._cond: threading.Condition = threading.Condition()
        self._eof: bool = False

    def feed(self, data: bytes) -> None:
        with self._cond:
            self._buf.extend(data)
            self._cond.notify_all()

    def close(self) -> None:
        """Сигнализировать EOF; идемпотентен."""
        with self._cond:
            self._eof = True
            self._cond.notify_all()

    # --- file-like interface для PyAV AVIO ---

    def read(self, n: int = -1) -> bytes:
        """Блокирует до появления данных или EOF; возвращает частичный результат."""
        with self._cond:
            while not self._buf and not self._eof:
                self._cond.wait(timeout=0.05)
            if not self._buf:
                return b""  # EOF → PyAV/libav прекращает демуксинг
            if n < 0:
                data = bytes(self._buf)
                self._buf.clear()
            else:
                data = bytes(self._buf[:n])
                del self._buf[:n]
            return data

    def seek(self, offset: int, whence: int = 0) -> int:
        """Поток не позиционируемый — возвращаем -1, чтобы libav не использовал seek."""
        return -1

    def seekable(self) -> bool:
        return False

    def readable(self) -> bool:
        return True

    def writable(self) -> bool:
        return False


class PcmStreamDecoder:
    """Персистентный потоковый декодер WebM/Opus → s16le PCM через PyAV.

    Аудиофрагменты подаются через feed(); декодированный PCM забирается через drain().
    Декодирование идёт в фоновом потоке; всё thread-safe.
    """

    def __init__(self, sample_rate: int = 16000) -> None:
        self._sample_rate = sample_rate
        self._pipe = _BytePipe()
        self._pcm: bytearray = bytearray()
        self._lock = threading.Lock()
        self._done = threading.Event()
        self._decode_error: Exception | None = None  # устанавливается из фонового потока
        # Event даёт happens-before между записью _decode_error в decode-потоке и
        # чтением в route-корутине (per-tick) — не полагаемся на GIL неявно.
        self._error_event = threading.Event()
        self._thread = threading.Thread(target=self._decode_loop, daemon=True)
        self._thread.start()

    def feed(self, data: bytes) -> None:
        """Подать очередной аудиофрагмент (webm-chunk) декодеру."""
        self._pipe.feed(data)

    def drain(self) -> bytes:
        """Забрать и очистить накопленный PCM (не блокирует)."""
        with self._lock:
            data = bytes(self._pcm)
            self._pcm.clear()
            return data

    def close(self) -> bytes:
        """Завершить поток, дождаться конца декодера, вернуть остаток PCM."""
        self._pipe.close()
        self._done.wait(timeout=5.0)
        return self.drain()

    def decode_error(self) -> Exception | None:
        """Потокобезопасно вернуть ошибку decode-потока (или None).

        Гейт по _error_event: если событие выставлено, запись _decode_error в
        decode-потоке гарантированно видна читателю (happens-before), без опоры на GIL.
        """
        if self._error_event.is_set():
            return self._decode_error
        return None

    def _decode_loop(self) -> None:
        try:
            import av  # доступен только в AI-образе (av==17.1.0)
            container = av.open(self._pipe, format="webm")
            audio_streams = [s for s in container.streams if s.type == "audio"]
            if not audio_streams:
                return
            # s16p = планарный s16; planes[0] — моно-канал сырыми bytes
            resampler = av.AudioResampler(
                format="s16p",
                layout="mono",
                rate=self._sample_rate,
            )
            for frame in container.decode(*audio_streams):
                for rf in resampler.resample(frame):
                    # to_ndarray() возвращает shape (channels, nb_samples) без padding;
                    # bytes(planes[0]) может включать alignment-байты → не использовать.
                    with self._lock:
                        self._pcm.extend(rf.to_ndarray().flatten().tobytes())
        except Exception as exc:
            self._decode_error = exc
            self._error_event.set()  # порядок: сперва запись поля, затем set() события
            logger.warning("pcm decode loop завершился с ошибкой: %s", exc)
        finally:
            self._done.set()

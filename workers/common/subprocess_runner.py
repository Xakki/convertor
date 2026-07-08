"""Общий async-раннер внешних процессов для on-server-воркеров.

Инкапсулирует дублировавшийся паттерн `create_subprocess_exec` + `wait_for(timeout)`
+ `kill`/`wait` на таймауте + проверку returncode (был в libreoffice/`_run` и
ffmpeg/`run_ffmpeg`). Логирования тут НЕТ намеренно — вызывающий воркер сам
логирует под своим logger'ом, чтобы имя лог-записи не «уехало» в workers.common.
"""

from __future__ import annotations

import asyncio


async def run_capture(
    argv: list[str],
    timeout: int,
    *,
    full_error: bool = True,
) -> tuple[bytes, bytes]:
    """Запустить *argv*, захватив stdout+stderr, с таймаутом (kill на истечении).

    Возвращает (stdout, stderr) при returncode==0. На таймауте — kill/wait и
    RuntimeError ``"{argv[0]} timed out after {timeout}s"``. На ненулевом коде —
    RuntimeError ``"{argv[0]} failed: <detail>"``:
      - full_error=True (LibreOffice-семантика): detail = ``err or out or rc``;
      - full_error=False (ffmpeg-семантика): detail = ``err`` (только stderr).
    """
    proc = await asyncio.create_subprocess_exec(
        *argv,
        stdout=asyncio.subprocess.PIPE,
        stderr=asyncio.subprocess.PIPE,
    )
    try:
        out_b, err_b = await asyncio.wait_for(proc.communicate(), timeout=timeout)
    except asyncio.TimeoutError:
        proc.kill()
        await proc.wait()
        raise RuntimeError(f"{argv[0]} timed out after {timeout}s")

    if proc.returncode != 0:
        err = err_b.decode("utf-8", "replace").strip()
        if full_error:
            out = out_b.decode("utf-8", "replace").strip()
            raise RuntimeError(f"{argv[0]} failed: {err or out or proc.returncode}")
        raise RuntimeError(f"{argv[0]} failed: {err}")

    return out_b, err_b

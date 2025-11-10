# app.py
from __future__ import annotations

import asyncio
import json
import logging
import os
import signal
import tempfile
from http import HTTPStatus
from pathlib import Path
from typing import Dict, Optional, Tuple

from aiohttp import web

# ----------------------------
# Конфигурация
# ----------------------------

SOFFICE_TIMEOUT_S = int(os.getenv("SOFFICE_TIMEOUT", "120"))
MAX_PARALLEL = int(os.getenv("MAX_PARALLEL", "2"))
CLIENT_MAX_SIZE = int(os.getenv("CLIENT_MAX_SIZE_MB", "25")) * 1024 * 1024
SHARE_ROOT = Path(os.getenv("SHARE_PATH", "/shared-files")).resolve()

# Разрешённые типы конверсий.
# Ключ — публичное имя, значения — (фильтр LibreOffice, расширение, content-type)
CONVERSIONS: Dict[str, Tuple[str, str, str]] = {
    "docx": (
        "docx",
        "docx",
        "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    ),
    "txt": ("txt:Text (encoded):UTF8", "txt", "text/plain; charset=utf-8"),
}

LOG = logging.getLogger("app")
logging.basicConfig(
    level=os.getenv("LOG_LEVEL", "INFO"),
    format="%(asctime)s %(levelname)s %(name)s: %(message)s",
)


# ----------------------------
# Утилиты
# ----------------------------

def _safe_join(root: Path, relative: str) -> Path:
    """
    Безопасно соединяет root и относительный путь.
    Бросает ValueError, если путь указывает вне root.
    """
    # Запрещаем абсолютные пути сразу
    rel = Path(relative.lstrip("/"))
    candidate = (root / rel).resolve()
    if not str(candidate).startswith(str(root)):
        raise ValueError("Path escapes shared root")
    return candidate


def _build_soffice_cmd(input_path: Path, output_dir: Path, filter_name: str) -> Tuple[str, ...]:
    """
    Строим команду без shell-интерполяции.
    """
    return (
        "soffice",
        "--headless",
        "--convert-to",
        filter_name,
        "--outdir",
        str(output_dir),
        str(input_path),
    )


def _disposition_filename(filename: str) -> str:
    """
    Формирует безопасный Content-Disposition.
    Упрощённо: экранируем кавычки и добавляем filename* (RFC 5987).
    """
    from urllib.parse import quote

    safe = filename.replace('"', "'")
    return f'attachment; filename="{safe}"; filename*=UTF-8\'\'{quote(filename)}'


# ----------------------------
# Сервис конвертации
# ----------------------------

class LibreOfficeConverter:
    """
    Фасад над вызовом LibreOffice. Неблокирующее выполнение, таймаут,
    ограничение параллельности, аккуратное завершение процесса.
    """

    def __init__(self, timeout_s: int, max_parallel: int) -> None:
        self._timeout_s = timeout_s
        self._sem = asyncio.Semaphore(max_parallel)

    async def convert(
        self,
        input_path: Path,
        output_dir: Path,
        filter_name: str,
        dest_ext: str,
    ) -> Path:
        output_dir.mkdir(parents=True, exist_ok=True)
        cmd = _build_soffice_cmd(input_path, output_dir, filter_name)

        # Для POSIX — стартуем в новой сессии, чтобы убивать процесс-группу.
        preexec_fn = os.setsid if hasattr(os, "setsid") else None

        # Для Windows можно добавить creationflags=CREATE_NEW_PROCESS_GROUP (опущено для краткости).

        async with self._sem:
            LOG.info("Run: %s", " ".join(cmd))
            proc = await asyncio.create_subprocess_exec(
                *cmd,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE,
                preexec_fn=preexec_fn,  # type: ignore[arg-type]
            )
            try:
                stdout, stderr = await asyncio.wait_for(
                    proc.communicate(), timeout=self._timeout_s
                )
            except asyncio.TimeoutError:
                LOG.error("Conversion timed out (pid=%s)", proc.pid)
                # Пытаемся корректно завершить всю группу
                if preexec_fn and hasattr(os, "killpg"):
                    try:
                        os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
                    except Exception:  # noqa: BLE001
                        pass
                else:
                    proc.kill()
                raise TimeoutError("Conversion timed out") from None

            if stdout:
                LOG.debug("LibreOffice stdout: %s", stdout.decode(errors="ignore"))
            if proc.returncode != 0:
                err_text = stderr.decode(errors="ignore")
                LOG.warning("LibreOffice failed: rc=%s, stderr=%s", proc.returncode, err_text)
                raise RuntimeError("LibreOffice conversion failed")

        # LibreOffice кладёт результат в output_dir с тем же base name.
        base = input_path.stem
        # На некоторых системах расширение может отличаться регистром — ищем case-insensitive.
        candidates = list(output_dir.glob(f"{base}.*"))
        result: Optional[Path] = None
        for c in candidates:
            if c.suffix.lower().lstrip(".") == dest_ext.lower():
                result = c
                break

        if not result or not result.exists():
            raise FileNotFoundError("Converted file not found")

        return result


# ----------------------------
# HTTP-обработчики
# ----------------------------

async def hello(_: web.Request) -> web.Response:
    return web.Response(text="HELLO")


def multipart_convert_handler(conv_key: str):
    """
    Фабрика обработчиков для multipart загрузки.
    """
    filter_name, dest_ext, content_type = CONVERSIONS[conv_key]

    async def handler(request: web.Request) -> web.StreamResponse:
        converter: LibreOfficeConverter = request.app["converter"]  # DI
        # Читаем multipart поток
        reader = await request.multipart()
        if not reader:
            raise web.HTTPBadRequest(text="Missing multipart data")

        # Находим первое файловое поле (по имени 'file' или любой Part c filename)
        field = None
        async for part in reader:
            if part.filename:
                field = part
                break
        if field is None:
            raise web.HTTPBadRequest(text="Missing file field")

        original_name = field.filename or "upload"
        # Временный каталог для входа/выхода
        with tempfile.TemporaryDirectory() as tmpdir:
            tmpdir_p = Path(tmpdir)
            # Сохраняем входной файл по чанкам
            suffix = Path(original_name).suffix or ".bin"
            input_path = tmpdir_p / f"in{suffix}"
            with input_path.open("wb") as f:
                while True:
                    chunk = await field.read_chunk()  # aiohttp сам ограничит размер чанка
                    if not chunk:
                        break
                    f.write(chunk)

            try:
                result_path = await converter.convert(
                    input_path=input_path,
                    output_dir=tmpdir_p,
                    filter_name=filter_name,
                    dest_ext=dest_ext,
                )
            except TimeoutError:
                raise web.HTTPGatewayTimeout(text="Conversion timed out") from None
            except FileNotFoundError:
                raise web.HTTPInternalServerError(text="Converted file not found") from None
            except RuntimeError as e:
                raise web.HTTPBadRequest(text=str(e)) from None

            # Формируем безопасное имя для скачивания
            safe_base = Path(original_name).stem or "output"
            out_name = f"{safe_base}.{dest_ext}"

            headers = {
                "Content-Disposition": _disposition_filename(out_name),
                "X-Content-Type-Options": "nosniff",
            }
            # Отдаём файл напрямую, aiohttp сам буферизует по чанкам
            return web.FileResponse(
                path=result_path,
                status=HTTPStatus.OK,
                headers=headers,
                content_type=content_type,
            )

    return handler


def shared_convert_handler(conv_key: str):
    """
    Фабрика обработчиков для файлов из общего каталога (relative path).
    Возвращает JSON с путём результата относительно SHARE_ROOT.
    """
    filter_name, dest_ext, _ = CONVERSIONS[conv_key]

    async def handler(request: web.Request) -> web.Response:
        converter: LibreOfficeConverter = request.app["converter"]
        data = await request.post()
        rel = (data.get("file") or "").strip()
        if not rel:
            raise web.HTTPBadRequest(text="Missing 'file' parameter")

        try:
            src_path = _safe_join(SHARE_ROOT, rel)
        except ValueError:
            raise web.HTTPBadRequest(text="Invalid file path") from None

        if not src_path.is_file():
            raise web.HTTPNotFound(text="File not available")

        try:
            result_path = await converter.convert(
                input_path=src_path,
                output_dir=src_path.parent,
                filter_name=filter_name,
                dest_ext=dest_ext,
            )
        except TimeoutError:
            raise web.HTTPGatewayTimeout(text="Conversion timed out") from None
        except RuntimeError as e:
            raise web.HTTPBadRequest(text=str(e)) from None

        # Возвращаем путь относительно SHARE_ROOT, без утечки абсолютных путей
        rel_result = result_path.resolve().relative_to(SHARE_ROOT)
        payload = {"path": f"/{rel_result.as_posix()}", "code": 0}
        return web.json_response(payload, status=HTTPStatus.OK)

    return handler


# ----------------------------
# Приложение и маршруты
# ----------------------------

def create_app() -> web.Application:
    app = web.Application(client_max_size=CLIENT_MAX_SIZE)

    # DI: единый сервис конвертации
    app["converter"] = LibreOfficeConverter(
        timeout_s=SOFFICE_TIMEOUT_S, max_parallel=MAX_PARALLEL
    )

    async def on_startup(_: web.Application) -> None:
        SHARE_ROOT.mkdir(parents=True, exist_ok=True)
        LOG.info("Share root: %s", SHARE_ROOT)

    app.on_startup.append(on_startup)

    app.router.add_get("/hello", hello)

    # multipart → файл в ответ
    app.router.add_post("/convert/multipart/docx", multipart_convert_handler("docx"))
    app.router.add_post("/convert/multipart/txt", multipart_convert_handler("txt"))

    # shared → json с путём результата внутри SHARE_ROOT
    app.router.add_post("/convert/shared/docx", shared_convert_handler("docx"))
    app.router.add_post("/convert/shared/txt", shared_convert_handler("txt"))

    return app


if __name__ == "__main__":
    # Порт задаётся через PORT, по умолчанию 8080
    web.run_app(create_app(), port=int(os.getenv("PORT", "80")))
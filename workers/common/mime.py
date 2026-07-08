"""Общие MIME-константы воркеров.

Здесь только записи, реально общие для нескольких воркеров. `_DOCX_MIME` дублировался
в libreoffice + image; docx/pdf/txt/md — общий выход document- и image/OCR-воркеров.
Специфичные для воркера MIME (аудио/видео у ffmpeg, data-форматы) остаются локальными.
"""

from __future__ import annotations

DOCX_MIME = (
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
)

# MIME-строки, общие для document- (libreoffice) и image/OCR-воркеров.
DOC_TEXT_MIME: dict[str, str] = {
    "docx": DOCX_MIME,
    "pdf":  "application/pdf",
    "txt":  "text/plain",
    "md":   "text/markdown",
}

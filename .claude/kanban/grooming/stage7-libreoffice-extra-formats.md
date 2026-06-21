### LibreOffice-воркер: доп-форматы Стадии 7 (epub-вход / pages / таблицы / презентации / pdf→jpg)

**Критичность:** Low — Стадия 7 (post-MVP)

**TAGS:**
- feature

**Описание:**
Форматы, отложенные при [[validate-libreoffice-worker]] (MVP-карточка покрыла doc/pdf/markup(md)). Собраны здесь, чтобы не потерять.

**Scope (всё — Стадия 7):**
- **epub как ВХОД (полностью).** Сейчас урезано до `epub→md` (pandoc). soffice не импортирует epub, поэтому остальные цели надо делать через pandoc: `epub→docx/odt/html/rtf/txt` (pandoc-writers), `epub→pdf` цепочкой pandoc→docx→soffice. ROADMAP стр. 147 числит epub во входе Стадии-1 — расхождение зафиксировано.
- **pages (Apple Pages) как ВХОД.** Убран (import через libetonyek не проверен). Проверить наличие libetonyek в образе и валидировать `pages→office/pdf` через soffice.
- **Таблицы / LibreOffice Calc:** `xls, xlsx, ods, csv` → `xlsx, ods, csv, pdf` (ROADMAP «Электронные таблицы»).
- **Презентации / LibreOffice Impress:** `ppt, pptx, odp` → `pptx, odp, pdf` (ROADMAP «Презентации»).
- **PDF→jpg постранично** (`pdftoppm`) — «jpg (страницы)» из PDF-операций (ROADMAP «PDF»).
- markup `rst/latex/wiki` (pandoc) — если решено делать в этом воркере.

**Зависимости:** После выравнивания матрицы [[align-document-stream-matrix-dlq]] (чтобы API рекламировал ровно то, что воркер умеет).

**Decisions:**
- Выделено из [[validate-libreoffice-worker]] / [[post-mvp-conversion-formats]] (2026-06-21).

### docs/queue-contract.md — выпилить устаревший Phase 0/Phase 1 нарратив

**Критичность:** Low

**TAGS:**
- docs
- cleanup

**Описание:**
`docs/queue-contract.md` содержит устаревший фазовый нарратив из старого
queue-redesign (до per-key стримов):
- строки 15-18: «Phase status: **Phase 0** ships a single stream `conversions`…
  Per-routing-key streams… are Phase 1 (card C)».
- строка 89: «Phase 0 used a single stream `conversions`; Phase 1 replaces it…».

По факту per-key стримы `conv.<type>` уже боевые (S1 WS-transport завершён).
Ссылка на «card C» и single-stream `conversions` вводит в заблуждение. Нужно
переписать эти врезки под текущую реальность (единственный контракт — `conv.<key>`,
никаких Phase 0/`conversions`), сохранив полезную часть про naming convention.

**Обнаружено:** при выполнении `[[s1-13-integration-doc-fixes]]` (вне его scope —
карта трогала только §2 double-envelope + имена каналов в CLAUDE.md).

**Файлы:**
- Изменить: `docs/queue-contract.md` (§1 врезки Phase 0/1, строка 89).

**Decisions (груминг 2026-07-08):** docs-only, открытых вопросов нет. Переписать §1-врезки (строки 15-18) и
строку 89: убрать Phase 0/1 + `card C` + single-stream `conversions`; оставить только текущую реальность
(единственный контракт — per-key `conv.<type>`) + полезную часть про naming convention. Кода не трогаем.

**Status:** todo — груминг завершён, scope ясен.

**Execution Log (2026-07-08):**
- Переписана §1-врезка (строки 15-18): убран «Phase 0 single stream `conversions` / Phase 1 (card C)»,
  оставлен единственный контракт — per-key `conv.<key>` + naming convention (RU-проза).
- Заголовок §1 «Stream / transport naming (Phase 1 — current)» → «Stream / transport naming».
- Удалена врезка на строке ~89 «Phase 0 used a single stream `conversions`; Phase 1 replaces it…».
- `grep -n "Phase 0\|Phase 1\|card C\|conversions\b"` → 0 совпадений. `conversions_failed` (§4/failure
  transport) сохранён, под паттерн `\b` не попадает — легитимен, вне мёртвого нарратива.
- DOCS-only, кода не трогали. Открытых вопросов нет.

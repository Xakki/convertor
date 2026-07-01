### Выровнять матрицу `conv.document`: PHP-реестр vs воркер + fast-DLQ перманентных ошибок

**Критичность:** Medium — UX/надёжность очереди (не блокирует MVP-конвертацию, но даёт плохой DLQ-опыт)

**TAGS:**
- tech-debt
- bug

**Описание:**
Всплыло при ревью [[validate-libreoffice-worker]] (2026-06-21). PHP `ConversionRegistry` рекламирует в стрим `conv.document` пары, которых нет ни у одного consumer'а (LibreOffice-воркер их отклоняет): `xls/xlsx/ods/csv→pdf`, `ppt/pptx/odp→pdf`, `dwg/dxf→pdf/svg/png`, `pdf→jpg`, markup `rst/latex/wiki`. Все они — Stage-7 (отложены), но API их принимает и диспатчит.

**Проблема:**
- API принимает запрос (напр. `xlsx→pdf` или `pdf→jpg`), ставит в `conv.document`. Воркер кидает `ValueError("unsupported conversion")` — **детерминированная, неретраябельная** ошибка.
- Но база (`workers/common/stream_consumer.py`) трактует ВСЕ исключения `convert()` как ретраябельные: запись не ACK-ается, редоставка через `XAUTOCLAIM` раз в `_IDLE_MS` (~5 мин) × `_MAX_RETRIES=3` → ~15-20 мин болтанки прежде чем задача умрёт.
- В DLQ/`conv.result` уходит обобщённая причина `"max_retries (3) exceeded"`, а не реальная `"unsupported conversion"` → оператор/юзер видит вводящую в заблуждение причину.

**Зависимости:** [[validate-libreoffice-worker]] (откуда всплыло).

**Decisions:**
- Matrix source-of-truth: long-term = **dynamic worker self-registration in DB** (Q2.1) → moved to NEW epic `registry-self-registration` (not this card).
- The "registry advertises unhandled Stage-7 pairs" structural fix = **chaining** (offer A→B→C via two available conversions) → moved to NEW Stage-7 card `conversion-chaining`. Do NOT remove the Stage-7 pairs from the registry *in this card*.
- ⚠ UPDATE [USER DECISION 2026-07-01]: long-term those Stage-7 pairs **will disappear** — once `registry-self-registration` Phase 2 lands, the matrix holds only live-worker-declared pairs and the API 400-rejects unhandled pairs at submit (see `registry-self-registration`). This **supersedes** the "do NOT remove" line above going forward. Until then, THIS card's `PermanentError` fast-DLQ is the interim coverage.
- NEAR-TERM deliverable of THIS card = **permanent-vs-transient fast-DLQ in the Python base consumer** via a dedicated **`PermanentError`** class (Q2.3). Base: `except PermanentError → fast-DLQ with str(exc) as the real reason; except Exception → existing retry path`. Migrate the 4 workers' "unsupported source/conversion/format" raises (currently `ValueError`) to `PermanentError`. Fix `_send_to_dlq` to surface the real reason (not hardcoded "max_retries exceeded"). Stream: `conv.dead` (canonical).

**Status:** ready (todo) — scope = PermanentError fast-DLQ only.

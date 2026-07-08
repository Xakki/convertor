### Выровнять матрицу `conv.document`: PHP-реестр vs воркер + fast-DLQ перманентных ошибок

**Re-scoped into S1:** решение `PermanentError` fast-DLQ **переносится** в эпик [[s1-ws-worker-transport]] → [[s1-06-reclaim-poison-dlq]], но **цель смещается**: с `except PermanentError` в `StreamConsumerBase` на WS-протокол — воркер поднимает `fail{permanent:true}`, а DLQ-решение (`conv.dead`) принимает gateway. Реализовывать fast-DLQ в S1-06, не в этой карточке. Матричная часть (Stage-7-пары) остаётся вне S1 (см. [[registry-self-registration]]).

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

**Status:** done — near-term deliverable ДОСТАВЛЕН в S1. Воркеры (ai + 4 on-server) кидают `ValueError` на unsupported-конвертацию → `StreamConsumerBase.process_job()` (`workers/common/stream_consumer.py:89-92`) мапит в `ResultSignal.failed(permanent=True)` → `ws_client._send_fail()` ставит `permanent:true` → gateway `_handle_fail()` (`ws_server.py:542-546`) → `add_to_dlq()` пишет `conv.dead` с реальной причиной. Тесты: `test_stream_consumer.py:128`, `test_ws_client.py:346`, `test_gateway_relay.py:274`. Остаток (Stage-7-пары в матрице → 400-reject) — вне этой карточки, в `[[registry-self-registration]]` Phase 2.

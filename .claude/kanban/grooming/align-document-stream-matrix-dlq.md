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

**Open questions:**
- Где источник истины матрицы `conv.document` — PHP-реестр или воркер? Должны сойтись (один не рекламирует то, чего не умеет другой).
- Убрать Stage-7 пары из PHP-реестра (до их реализации) ИЛИ научить базу различать перманентные (fast-DLQ, без ретраев) и транзиентные ошибки?
- Перманентную ошибку различать по типу исключения (напр. `ValueError`/спец-класс `PermanentError`) → база сразу DLQ с реальной причиной. Это касается ВСЕХ воркеров, не только libreoffice — менять в базе.

**Зависимости:** [[validate-libreoffice-worker]] (откуда всплыло).

**Decisions:** —

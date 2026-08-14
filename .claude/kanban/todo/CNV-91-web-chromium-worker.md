### Runtime записи загрузки страницы в browser worker

**Criticality:** High

**TAGS:**
- feature
- browser
- recording
- video
- worker

**Description:**
Browser-worker-специалист реализует запись начальной загрузки разрешённого URL в
WebM без audio. Runtime применяет только серверные network presets, лимиты плана и
существующий надёжный lifecycle результата.

**Problem:**
Video worker не управляет browser timeline и не может безопасно записывать URL
navigation. Неограниченная запись истощает CPU, память, диск, контейнерный slot и
очередь.

**Impact:**
Нельзя воспроизводимо получить видеозапись загрузки страницы, а ошибка или oversize
могут оставить процессы, потерять результат либо создать повторные terminal effects.

**Recommendation:**
Записывать URL navigation в WebM без audio до server cap. Применять server-defined
`normal|fast_3g|slow_3g`, этапы progress `fetching|navigating|recording|encoding|
uploading`, multipart для большого результата и новый context на job. Не принимать
raw latency/downlink, не поддерживать HTML recording, MP4, UI controls или consent
экран.

**Acceptance Criteria:**
- URL → playable non-empty WebM доступен basic/pro; guest/free получают отказ по
  backend contract до запуска worker-а.
- Basic ограничен 15 s/15 FPS/25 MB, Pro — 30 s/24 FPS/50 MB; на контейнер допустим
  один job, context и recording slot.
- Timeout и oversize проходят retry/DLQ; результат persisted до XACK, а cleanup не
  оставляет дочерние процессы и не создаёт duplicate terminal effects.
- Worker-тесты покрывают normal и slow_3g preset, лимиты обоих планов, cleanup и
  жизненный цикл результата; целевые проверки проходят без новых предупреждений.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Владелец: browser-worker-специалист; граница работы — только recording runtime.
- `slow_3g` является server preset, не пользовательскими network numbers; no audio и
  WebM-only — границы MVP.
- CNV-91 зависит от CNV-88, CNV-113, CNV-89, CNV-114 и готового screenshot runtime
  CNV-90; CNV-116 зависит от CNV-91 и владеет UI и обязательным consent.

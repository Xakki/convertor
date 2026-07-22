### Отображение всех форматов + видимость AI-конверсий

**Criticality:** Medium

**TAGS:**
- feature
- frontend

**Description:**
`GET /api/v1/formats` уже отдаёт ВСЕ 309 пар, включая 25 AI-конверсий
(STT `audio→txt/srt/vtt`, TTS `txt→mp3/wav/ogg`, embedding `txt→json`;
`isAi:true`) — фильтрации на бэке нет. Но в UI они не видны/неотличимы:
1. Таблица «поддерживаемые форматы» в инфо-блоке (`index.html.twig`, ~L210-280,
   home-04 Part A) — **захардкоженная** Twig-таблица без AI-строк, не из API.
2. Витрина (`_converter_app_script.html.twig`, `showcaseCategories`) рендерит
   AI-пары, но БЕЗ бейджа «AI» — неотличимо от обычных (хотя `pair.isAi`
   доступен клиентски).

**Acceptance Criteria:**
- Таблица форматов в инфо-блоке генерируется из данных `/api/v1/formats`
  (клиентски из уже загруженного `this.formats`), а не хардкодом — включает
  строки AI (STT/TTS/embedding). Больше не устаревает при изменении матрицы.
- В витрине на парах с `isAi===true` виден бейдж «AI» (i18n EN+RU).
- `make phpstan`/`cs-check`/`test` зелёные; `/` рендерится 200, таблица и
  витрина показывают AI-пары.

**Decisions:**
- Пользователь выбрал «и таблицу из API, и AI-бейдж».
- Image-captioning и перевод воркером НЕ реализованы (только STT/TTS/embedding)
  — вне этой задачи, не выдумывать несуществующие пары.

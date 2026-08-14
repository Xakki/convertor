### Скриншоты HTML и URL через Chromium worker

**Criticality:** High

**TAGS:**
- feature
- browser
- screenshot

**Description:**
Добавить PNG/JPEG screenshot из self-contained uploaded HTML и authenticated URL через
отдельный Chromium worker.

**Problem:**
Текущая конвертация не умеет визуально рендерить HTML или web URL, а смешение local
HTML и remote URL без отдельных policy создаёт file/network угрозы.

**Impact:**
Пользователь не сможет получать визуальные снимки страниц, а наивная реализация
может открыть worker filesystem или сеть для пользовательского HTML.

**Recommendation:**
HTML — один self-contained файл, внешние subresources disabled. URL screenshot ждёт
`domcontentloaded` + bounded settle delay. Settings profile: viewport preset,
width/height, capture viewport/full-page, PNG/JPEG, background и bounded settle.

**Acceptance Criteria:**
- HTML → PNG/JPEG работает в isolated origin без внешних ресурсов; URL → screenshot
  проходит только через CNV-89 policy.
- Viewport до 1920×1080, full-page до 10k px/20 MP, navigation 30 s, settle 5 s;
  timeout/infinite page завершается безопасно.
- Guest получает fixed default HTML screenshot, free — safe base profile, URL —
  только basic/pro; effective options сохраняются в Conversion/history.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- CNV-85 задаёт общий profile contract; никакие raw Chromium flags, selectors, headers,
  proxy, cookie или JS injection не принимаются.

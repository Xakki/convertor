### Frontend-управление browser-скриншотами

**Criticality:** High

**TAGS:**
- feature
- browser
- frontend
- screenshot

**Description:**
Frontend-специалист добавляет пользовательский интерфейс отправки self-contained HTML
и разрешённого URL для screenshot, а также ограниченные controls профиля снимка и
отображение статуса/ошибок готового backend contract.

**Problem:**
Готовый screenshot runtime невозможно безопасно использовать без UI, который чётко
разделяет HTML и URL modes, показывает plan gates и не даёт передать запрещённые raw
Chromium-параметры.

**Impact:**
Пользователь не сможет запустить screenshot либо получит неясные ошибки и controls,
которые расходятся с server-side policy и создают ложное ожидание возможностей.

**Recommendation:**
Реализовать mode picker `html|url`, поле URL с клиентской предвалидацией и
redacted отображением, upload self-contained HTML, controls viewport preset,
width/height, viewport/full-page, PNG/JPEG, background и settle delay только в
пределах server profile. Отображать plan/role denial, queue progress и безопасные
ошибки. Не создавать API contract, proxy, sandbox или screenshot runtime.

**Acceptance Criteria:**
- UI не смешивает HTML и URL flows: HTML не предлагает сетевые privileges, URL
  отображается только basic/pro и отправляется в API contract CNV-89.
- Controls соответствуют ограниченному screenshot profile и не содержат raw flags,
  selectors, headers, cookies, proxy, JS injection или произвольные network values.
- Отображаются loading/progress, success и безопасные validation/policy/timeout errors;
  URL не раскрывается в UI-ошибках сверх redacted представления.
- Component/integration/e2e tests покрывают HTML, basic/pro URL, guest/free denial и
  ошибки; целевые frontend-проверки проходят без новых предупреждений.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Владелец: frontend-специалист; граница работы — интерфейс и presentation state.
- Сервер является источником истины для validation, plan gate и effective options;
  клиентская проверка служит только ранней обратной связью.
- CNV-115 зависит от готового screenshot runtime CNV-90 и URL contract CNV-89;
  CNV-88, CNV-113 и CNV-114 для него являются транзитивными prerequisites.

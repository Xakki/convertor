### Runtime скриншотов в browser worker

**Criticality:** High

**TAGS:**
- feature
- browser
- screenshot
- worker

**Description:**
Browser-worker-специалист реализует исполнение screenshot-задачи: self-contained
HTML и разрешённый URL преобразуются в PNG или JPEG внутри изолированного browser
runtime с ограниченными параметрами профиля.

**Problem:**
После появления маршрута и URL contract отсутствует runtime, который безопасно
создаёт новый browser context, применяет профиль снимка и формирует результат с
ограничением времени и размера страницы.

**Impact:**
Пользователь не получает screenshot, а неограниченная навигация или page capture
может занять slot browser container-а и истощить его ресурсы.

**Recommendation:**
Для HTML открыть один self-contained файл в isolated origin с отключёнными внешними
subresources. Для URL использовать только proxy CNV-114. Реализовать profile:
viewport preset, width/height, viewport/full-page, PNG/JPEG, background и bounded
settle delay; запускать новый context на job и корректно очищать дочерние процессы.
Не реализовывать форму UI, контейнерный sandbox, proxy и запись видео.

**Acceptance Criteria:**
- HTML → PNG/JPEG работает без сетевых и файловых subresources; URL → screenshot
  использует только egress proxy CNV-114.
- Ограничения runtime соблюдаются: viewport до 1920×1080, full-page до 10 000 px и
  20 MP, navigation до 30 s, settle до 5 s; timeout и бесконечная страница завершают
  задачу безопасной terminal ошибкой.
- Effective options сохраняются в Conversion/history; raw Chromium flags, selectors,
  headers, cookie, proxy и JS injection не принимаются.
- Worker-тесты покрывают HTML, URL через proxy, timeout и cleanup context/processes;
  целевые проверки проходят без новых предупреждений.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Владелец: browser-worker-специалист; граница работы — screenshot runtime и result.
- Скриншот остаётся category `image`, но приходит через browser route CNV-88.
- CNV-90 зависит от CNV-88, CNV-113, CNV-89 и CNV-114; CNV-115 зависит от CNV-90 и
  реализует только пользовательский интерфейс этого готового runtime.

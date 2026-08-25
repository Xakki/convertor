### CSV-источник: разделитель и кавычка при чтении не настраиваются

**Criticality:** Medium

**TAGS:**
- data
- conversion-options

**Description:**
Настройки CSV (`delimiter`, `quote`) в каталоге привязаны к парам, где CSV —
ЦЕЛЬ. Для пар, где CSV является ИСТОЧНИКОМ (`csv→json`, `csv→yaml`, `csv→toml`,
`csv→xml`), способа сообщить системе разделитель исходного файла нет.

**Problem:**
Пользователь с CSV через точку с запятой (обычное дело для локалей, где запятая
— десятичный разделитель) при конвертации в JSON получит мусор: вся строка
попадёт в одну колонку.

**Impact:**
Молчаливо неверный результат вместо ошибки. Пользователь не поймёт, почему
данные «слиплись», и настройки, которая это чинит, в интерфейсе не найдёт.

**Evidence:**
Проверено 2026-08-24 при приёмке CNV-103: `workers/data/worker.py:100` —
`pd.read_csv(src)` без параметра `sep`, то есть pandas ждёт запятую. Sniffing
не включён (`sep=None, engine="python"` не используется).
ВАЖНО: это НЕ регрессия CNV-103 и не дефект её реализации — дыра существовала
до эпика. CNV-103 сознательно приняла «опции настраивают выход» по аналогии с
CNV-97 (`pageRange` для `to=pdf`), и это решение корректно в своих границах.

**Recommendation:**
Поддержать input-профиль `delimiter`/`quote` и opt-in auto-detection. Явные
поля имеют приоритет; без них worker использует текущий совместимый default,
а auto-detection включается только явной дешёвой настройкой.

**Acceptance Criteria:**
- CSV input-profile поддерживает delimiter и quote для csv→json/yaml/toml/xml.
- Opt-in auto-detection доступен без плана и не переопределяет явно заданные
  delimiter/quote.
- Тесты покрывают явный `;`, quote, default CSV и auto-detection edge case.

**Decisions:**
- 2026-08-26: поддержать input-профиль и opt-in auto-detection; явные поля
  приоритетнее, auto-detection не является неявным default.

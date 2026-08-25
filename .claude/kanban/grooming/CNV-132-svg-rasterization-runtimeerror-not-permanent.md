### SVG-растеризация: постоянно-неренедрящийся SVG ретраится вечно (RuntimeError, не ValueError)

**Criticality:** Medium

**TAGS:**
- bug-fix
- reliability
- images

**Description:**
Найдено при работе над CNV-131 (обзор `workers/image/worker.py` вокруг
`Image.open()`), но это ДРУГОЙ call-site и другая ошибка — не относится к
CNV-131 (тот покрывал только два конкретных `Image.open()`).
`_do_svg_convert()` оборачивает `PNGSurface.convert()` (CairoSVG-растеризация)
в `except Exception: raise RuntimeError("SVG rasterization failed") from None`
(намеренное решение CNV-75 — не раскрывать детали парсера). `RuntimeError` НЕ
наследует `ValueError`, а `StreamConsumerBase.process_job()` классифицирует
как PERMANENT только подклассы `ValueError` (см. skill `backend-architecture`,
`workers/common/stream_consumer.py:59-61,91-97`). Значит SVG, который
CairoSVG постоянно не может растеризовать (well-formed XML, но, например,
неподдерживаемый CSS/фича, вызывающая внутреннюю ошибку cairo), сегодня
ретраится бесконечно — тот же класс дефекта, что CNV-128/CNV-131 закрыли для
XML/YAML/растровых изображений.

**Problem:**
`workers/image/worker.py:236-241` (`_do_svg_convert`, блок `except Exception:
... raise RuntimeError("SVG rasterization failed") from None`) ловит ЛЮБОЕ
исключение из `PNGSurface.convert()`/дальнейшей обработки и всегда
перевыбрасывает как `RuntimeError` — независимо от того, постоянна причина
(неподдерживаемая SVG-фича) или временна (OOM, состояние font-cache).

**Impact:**
Меньше, чем YAML/image из CNV-131 — SVG реже встречается как формат
пользовательской загрузки, а само по себе изображение уже прошло
XML-well-formedness проверку (`_validate_svg_well_formed`, CNV-128-приём,
уже ValueError). Но постоянно-неренедрящийся, но well-formed SVG всё ещё
ретраится вечно без обратной связи пользователю.

**Evidence:**
Установлено чтением (НЕ выполнением) при работе над CNV-131 — это КАНДИДАТ,
не подтверждённая находка:
- `RuntimeError.__mro__` не включает `ValueError` (стандартная иерархия
  Python — не перепроверялось локальным запуском для этого конкретного кейса,
  но тривиально верно).
- Не воспроизведено: не найден конкретный SVG-input, вызывающий именно эту
  ветку `except Exception` (а не `_validate_svg_well_formed`, которая уже
  ValueError). Нужен реальный пример SVG, который CairoSVG принимает как
  well-formed, но не может растрировать.

**Recommendation:**
НЕ мехонически заменять `except Exception: raise RuntimeError(...)` на
`ValueError` — это конвертнёт и genuine TRANSIENT сбои (OOM в cairo,
состояние font-cache) в PERMANENT (DLQ), что хуже текущего бага в этих
случаях. Нужно решение архитектора/team-lead: какое подмножество исключений
из `PNGSurface.convert()` действительно постоянно (и какое сообщение
безопасно показать — CNV-75 сознательно не раскрывает детали парсера
пользователю). Отдельная карточка, отдельное решение, не мехонический
re-raise по образцу CNV-128/131.

**Дополнение к объёму (ревью 2026-08-25, НЕ проверено исполнением):**
`except Exception` в `_do_svg_convert()` оборачивает ВЕСЬ конвейер, а не только
растеризацию: открытие PNG-байтов, `_apply_image_options` и сохранение
(`_save_svg_ico/bmp/tiff`, `_save_image`). Отсюда два следствия:
1. Механическая замена на `ValueError` невозможна в принципе — под одним
   перехватом лежат и постоянные отказы cairo, и временные, и ошибки на
   стороне записи результата. Сначала надо РАЗДЕЛИТЬ try, потом
   классифицировать.
2. **Непроверенный кандидат:** `_apply_image_options` находится внутри того же
   try (строки ~266/268/270). Значит `ValueError` о неверной опции на
   SVG-пути превращается в `RuntimeError` и считается ВРЕМЕННЫМ, тогда как та
   же самая ошибка на растровом пути (`_do_convert`, опции применяются вне
   перехвата) остаётся постоянной. Одинаковый вход — разная классификация в
   зависимости от формата источника. Проверить исполнением до принятия решения.


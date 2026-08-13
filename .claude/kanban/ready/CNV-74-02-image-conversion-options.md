### Настройки конвертации изображений

**Criticality:** High

**TAGS:**
- feature
- images
- frontend
- api
- workers

**Description:**
Добавить первый полный путь параметров результата изображения: форма → API → job →
image-worker. Параметры доступны после выбора целевого формата.

**Problem:**
`app-front/js/upload.js` отправляет только файл, source и target format; в DTO,
задаче и image-worker нет контракта параметров. Пользователь не может выбрать
размер, качество или фон.

**Impact:**
Даже поддерживаемые изображения нельзя адаптировать для веба, печати или систем с
ограничением размера файла без внешнего редактора.

**Recommendation:**
Ввести явный структурированный `options`-контракт и whitelist-валидацию по target
format. В форме показывать сворачиваемый по умолчанию блок сразу под target format;
хранить выбранные значения в общем `localStorage` по target format. Воркер применяет
только разрешённые параметры, а API отклоняет лишние и некорректные.

**Acceptance Criteria:**
- Для target-изображений доступны ширина и высота; при заполнении одного измерения
  второе вычисляется с сохранением пропорций, при двух — результат вписывается в
  заданный bounding box без искажения.
- Для `jpg/jpeg` и `webp` доступно quality с валидным диапазоном 1–100; для
  `jpg/jpeg` доступен выбор фона, которым заменяется прозрачность.
- Значения по умолчанию совпадают с текущим поведением воркера; действие «Сбросить»
  восстанавливает дефолты выбранного target format.
- Блок расположен под target format, закрыт при первой загрузке и имеет доступное
  label/button с `aria-expanded`; смена target format показывает только его опции.
- Предпочтения сохраняются в `localStorage` браузера по target format и не требуют
  входа; повреждённые/устаревшие данные игнорируются безопасно.
- API/DTO валидирует типы, границы и whitelist; параметры доходят до job и
  применяются image-worker без влияния на неподдерживаемые форматы.
- Есть тесты API-валидации, worker-результата и клиентского сохранения/сброса.
- Выполнены применимые `make`-проверки frontend, workers и backend; PHPStan и code
  style без новых ошибок.

**Decisions:**
- 2026-08-15: первая версия покрывает только изображения.
- 2026-08-15: localStorage общий для всех пользователей данного браузера, ключ —
  target format; серверная синхронизация не нужна.

**Execution Log:**
- 2026-08-15: добавлен полный контракт options: API whitelist и нормализация,
  JSON-поле Conversion с миграцией, передача в ConversionMessage и повторное
  использование при retry.
- 2026-08-15: форма Symfony получила сворачиваемые настройки размера, качества
  и JPEG-фона с безопасной загрузкой/сбросом localStorage по target format.
- 2026-08-15: image-worker применяет bounding box без искажений, quality и
  непрозрачный JPEG-фон; покрыто PHP API и Python worker тестами.

**Affected zones:**
- `app-front/js/upload.js`
- `app-symfony/templates/conversion/*.html.twig`
- `app-symfony/src/Controller/Api/ConversionController.php`
- `app-symfony/src/DTO/ConversionRequestDTO.php`
- `app-symfony/src/Service/Conversion/ConversionManager.php`
- `app-symfony/src/Message/ConversionMessage.php`
- `workers/image/worker.py`
- API, PHP и Python тесты соответствующих слоёв

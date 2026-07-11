### Admin: вкл/выкл конкретной конвертации (эпик admin-panel #6)

**Criticality:** Minor
**Epic:** [[admin-panel]] — подзадача 6. Зависит от [[admin-panel-auth]].

**TAGS:**
- feature

**Description:**
Из ROADMAP-спеки (`ROADMAP.md:227-228`): админ может включить/выключить конкретную
конвертацию. **Модели данных сегодня нет** — реестр статичен
(`src/Service/Conversion/ConversionRegistry.php`), нужен персистентный флаг.

**Scope:**
- **Модель данных:** персистентный toggle для (from→to)/типа конвертации. Решить
  гранулярность: по паре форматов, по категории (`conv.<type>`), или по записи
  реестра. Черновик: новая entity `ConversionToggle` (ключ + enabled) ИЛИ флаг на
  существующей конфиг-модели; + миграция.
- **Чтение флага:** `ConversionRegistry` (и/или слой валидации в `ConversionManager`/
  API) при выборе конвертора учитывает toggle → выключенная конвертация даёт
  внятную 4xx-ошибку на сабмите, не уходит в очередь.
- **Кэш:** флаги читаются на каждый сабмит — кэшировать (KeyDB DB0 cache), инвалид
  при переключении.
- **API:** `GET /api/v1/admin/conversions-toggle` (список + состояние),
  `POST .../conversions-toggle/{key}` (вкл/выкл).
- **UI:** `templates/admin/toggle.html.twig` — список конвертаций с переключателями
  (Alpine + HTMX).

**Acceptance criteria:**
- [ ] Выключенная конвертация отвергается на сабмите (4xx), не попадает в очередь.
- [ ] Переключение персистится и сразу влияет на новые сабмиты (кэш инвалид).
- [ ] Гранулярность toggle задокументирована и согласована в реализации.
- [ ] Эндпоинты под ROLE_ADMIN; 403 иначе. `make phpstan` 0, `make cs-check` чисто,
      PHPUnit на «выключено → reject».

**Files:** новая entity + миграция, `src/Service/Conversion/ConversionRegistry.php`,
`src/Service/Conversion/ConversionManager.php` (или валидация в
`ConversionController`), `src/Controller/Admin/ConversionToggleController.php`,
`templates/admin/toggle.html.twig`.

**Open note:** гранулярность toggle (пара форматов vs категория vs запись реестра) —
финализировать в начале реализации, зафиксировать в Execution Log.

**Status:** todo.

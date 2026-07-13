### Ложный `unhealthy` у worker-libreoffice — healthcheck дёргает отсутствующий `curl`

**Criticality:** Low

**TAGS:**
- bug-fix
- infra
- config
- observability

**Description:**
Контейнер `libreoffice` (`worker-libreoffice`) в `docker compose ps` показывается
`unhealthy` — это ложная тревога. Его healthcheck дёргает `curl`, которого нет
в PATH образа. Сам демон при этом работает и слушает `0.0.0.0:6000`
(конвертация docx→md проходит успешно).

**Impact:**
Шум в `make ps` / мониторинге: сервис помечен `unhealthy`, хотя фактически
здоров. Это маскирует реальные проблемы здоровья — на фоне постоянного
ложного `unhealthy` легко пропустить настоящий отказ воркера.

**Recommendation:**
Один из вариантов (выбрать на grooming):
- добавить `curl` в образ воркера;
- переписать healthcheck на доступный в образе инструмент — `wget`,
  встроенную проверку порта через `python`, либо `nc`;
- убрать healthcheck, если проверка не несёт ценности.
Файлы: `docker-compose.yml` (healthcheck сервиса `worker-libreoffice`)
+ соответствующий `docker/workers/libreoffice.Dockerfile`.

**Status:** grooming.

# Мониторинг convertor (Prometheus / Grafana)

Переиспользуем кросс-проектный стек **dockprom** (`/home/soft/dockprom/`, Grafana
`mon.xakki.ru`) — отдельный Prometheus/Grafana в compose convertor не поднимаем.

Источник правды в репозитории: `deploy/monitoring/`.

| Файл | Назначение |
|------|------------|
| `convertor.rules` | Alert rules (Prometheus) |
| `prometheus-scrape-snippet.yml` | Документация scrape-job `convertor-exporter` |
| `grafana-convertor-streams.json` | Дашборд «Convertor — KeyDB Streams» |

CPU/RAM воркеров — в БД/admin (CNV-35), **не** в Prometheus. Здоровье пула
воркеров в алертах проксируется через метрики KeyDB Streams (consumers / lag /
pending idle).

---

## 1. Профиль `monitoring`

Сервис `metrics-exporter` в `docker-compose.yml` под профилем `monitoring`
(жёсткое имя контейнера `convertor-metrics-exporter` — так скрейпит dockprom).

```bash
# в корневом .env
COMPOSE_PROFILES=server,monitoring,…   # monitoring обязателен на основном хосте
make up
```

Тест-стенд (`TEST=1` / `.env.test`) профиль `monitoring` **не** активирует —
два стенда не могут одновременно владеть именем `convertor-metrics-exporter`.

Exporter слушает `:9472` только во внутренних сетях (`backend` + внешняя
`common`); хост-порт не публикуется.

### KeyDB hostname

Exporter сидит в двух сетях (`backend` + `common`). Имя `keydb` на `common`
может резолвиться в чужой KeyDB (например `shared-keydb`). Поэтому для
`metrics-exporter` задано:

`REDIS_HOST=${METRICS_REDIS_HOST:-${COMPOSE_PROJECT_NAME}-keydb}`

Переопределение — только через `METRICS_REDIS_HOST` в `.env.local` при
нестандартной топологии.

---

## 2. Применение rules в dockprom

1. Скопировать rules:

```bash
sudo cp /home/xakki/convertor/deploy/monitoring/convertor.rules \
  /home/soft/dockprom/prometheus/convertor.rules
```

2. Убедиться, что в `prometheus.yml` есть:

```yaml
rule_files:
  - "convertor.rules"
```

и scrape-job из `prometheus-scrape-snippet.yml` (таргет
`convertor-metrics-exporter:9472` в сети `common`).

3. Дашборд (если обновляли JSON):

```bash
sudo cp /home/xakki/convertor/deploy/monitoring/grafana-convertor-streams.json \
  /home/soft/dockprom/grafana/provisioning/dashboards/convertor-streams.json
```

4. Reload Prometheus (без рестарта контейнера, если API доступен):

```bash
# из контейнера prometheus или с хоста, если порт проброшен
curl -X POST http://localhost:9090/-/reload
# либо
cd /home/soft/dockprom && docker compose kill -s SIGHUP prometheus
```

Точный способ reload — по принятой практике dockprom на хосте; после sync
проверьте `http://…:9090/rules` и статус target `convertor-exporter`.

---

## 3. Метрики exporter’а

| Метрика | Смысл |
|---------|--------|
| `convertor_exporter_up` | 1 = последний scrape KeyDB успешен |
| `convertor_dead_letter_messages` | XLEN `conv.dead` |
| `convertor_stream_length` | XLEN стрима |
| `convertor_stream_group_pending` | размер PEL |
| `convertor_stream_group_lag` | недоставленный backlog |
| `convertor_stream_group_consumers` | число consumers в группе `convertor` |
| `convertor_stream_pending_max_idle_ms` | idle самого старого pending (0 если PEL пуст) |
| `convertor_exporter_scrape_errors_total` | счётчик failed scrape cycles |

Подробнее — `docs/queue-streams.md` § Metrics.

---

## 4. Семантика алертов

Все rules с label `project=convertor` (маршрут Alertmanager → Telegram-топик convertor).

| Alert | Условие | `for` | Severity |
|-------|---------|-------|----------|
| `ConvertorExporterDown` | `convertor_exporter_up==0` или `up{job=…}==0` | 5m | warning |
| `ConvertorDeadLetterGrowing` | `convertor_dead_letter_messages > 0` | 10m | warning |
| `ConvertorQueueLagHigh` | `max by (stream) (convertor_stream_group_lag{group="convertor"}) > 100` | 10m | warning |
| `ConvertorNoConsumers` | `convertor_stream_group_consumers{group="convertor"} == 0` | 10m | critical |
| `ConvertorPendingStall` | `convertor_stream_pending_max_idle_ms{group="convertor"} > 600000` | 5m | warning |

- **NoConsumers** — прокси «пул воркеров / читатель gateway мёртв» по стриму
  (не per-worker CPU).
- **PendingStall** — задача зависла в PEL дольше ~10 мин (выше типичного
  XAUTOCLAIM reclaim).
- **QueueLagHigh** — потребители не успевают; масштабировать категорию или
  проверить gateway.

Пороги при необходимости крутить в `deploy/monitoring/convertor.rules` и
снова sync в dockprom.

---

## 5. Проверка

```bash
# метрики с живого exporter
docker exec convertor-metrics-exporter curl -sf http://localhost:9472/metrics | grep convertor_stream_group

# unit-тесты exporter
make test-python-metrics
```

Ожидаются labeled-series по `conv.document` / `conv.image` / … (не только
`convertor_exporter_up` и DLQ). Если series нет — проверить `REDIS_HOST` /
сеть (см. §1).

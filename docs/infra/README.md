# MinIO IAM-политики convertor (dev)

Scoped-политики для сервисного аккаунта convertor на shared MinIO. Широкая встроенная политика `readwrite` заменяется тремя узкими.

## Политики и JSON-файлы

| Имя политики в MinIO | JSON-файл | Бакет |
|---|---|---|
| `convertor-dev-inputs-rw` | `minio-convertor-dev-inputs-policy.json` | `convertor-dev-inputs` |
| `convertor-dev-results-rw` | `minio-convertor-dev-results-policy.json` | `convertor-dev-results` |
| `convertor-dump-rw-nodelete` | `minio-convertor-dump-policy.json` | `convertor-dump` (без `s3:DeleteObject`) |

Файл `minio-convertor-policy.json` **устарел** — старая политика только для `convertor-results`; не использовать.

## Создание и привязка

MCP `minio` не умеет создавать кастомные IAM-политики — только встроенные (`readwrite`, `readonly`, …). Кастомные политики создаются через `mc admin policy create` на хосте shared-minio (CNV-10, CNV-45).

Порядок привязки к сервисному аккаунту:

1. Создать три scoped-политики из JSON-файлов выше.
2. Привязать все три scoped-политики.
3. Отвязать широкую `readwrite`.

## Lifecycle (ILM) бакета `convertor-dump`

Авто-ротация дампов — **MinIO ILM** (серверная сторона), не cron-скрипт.
IAM `convertor-dev` по-прежнему без `s3:DeleteObject` на dump — lifecycle
удаляет объекты от имени MinIO.

| Параметр | Значение |
|---|---|
| Current object expiry | **30 дней** |
| Noncurrent version expiry | **30 дней** |
| Rule ID (пример) | `d9oc8qg60lkns07alv7g` |
| Status | Enabled, prefix `-` (весь бакет) |

MCP `minio` **не умеет** ставить/читать ILM — только `bucket_info` показывает
флаг `ILM: Enabled/Disabled`. Управление — через `mc ilm` внутри контейнера
`shared-minio` с root-alias `MC_HOST_local` (тот же escape hatch, что CNV-10 /
CNV-45 для `mc admin policy`).

Добавить / пересоздать правило:

```bash
docker exec -e MC_HOST_local="http://$MINIO_ROOT_USER:$MINIO_ROOT_PASSWORD@localhost:9000" \
  shared-minio mc --config-dir /tmp/.mc --no-color \
  ilm rule add --expire-days 30 --noncurrent-expire-days 30 local/convertor-dump
```

Проверка:

```bash
docker exec -e MC_HOST_local="http://$MINIO_ROOT_USER:$MINIO_ROOT_PASSWORD@localhost:9000" \
  shared-minio mc --config-dir /tmp/.mc --no-color \
  ilm rule ls local/convertor-dump

docker exec -e MC_HOST_local="http://$MINIO_ROOT_USER:$MINIO_ROOT_PASSWORD@localhost:9000" \
  shared-minio mc --config-dir /tmp/.mc --no-color \
  ilm rule export local/convertor-dump
```

Ожидаемый export (структура):

```json
{"Rules":[{"Expiration":{"Days":30},"ID":"…","NoncurrentVersionExpiration":{"NoncurrentDays":30},"Status":"Enabled"}]}
```

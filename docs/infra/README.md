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

---
name: backend-architecture
description: Convertor backend architecture (PHP 8.5 + Symfony 7) — Registry pattern for converter routing, Manager orchestration, Symfony Messenger queue transport, DTO layers, AbstractBase (StreamConsumerBase) for Python workers, modeled on github.com/Xakki/ExRate. Use when touching src/Service/Conversion/*, src/Message/*, src/DTO/*, config/packages/messenger.yaml, or workers/common/stream_consumer.py, or when asked how a conversion request flows end-to-end.
---

# Backend Architecture (Convertor)

## Суть паттерна

Backend построен по образцу **https://github.com/Xakki/ExRate**: слоистая
архитектура, где каждый слой отвечает строго за одно решение и не знает о
деталях соседних слоёв.

- **Registry** — чистая функция роутинга/валидации пар форматов (аналог
  `ProviderRegistry` в ExRate). Не оркестрирует, не имеет побочных эффектов
  (кроме чтения БД/кеша), не знает про очереди.
- **Manager** — оркестрирует бизнес-флоу конвертации: гейты (toggle/ai-video/
  size/mime/quota) → S3 → персист Entity → dispatch в очередь → charge quota.
  Единственная точка входа для Controller'ов.
- **Messenger (Symfony)** — транспорт постановки задачи в очередь. PHP только
  ПИШЕТ (produce-only), никогда не читает `conv_*` (не запускает
  `messenger:consume`).
- **DTO** — контракты передачи данных МЕЖДУ слоями/процессами, а не ORM-сущности.
- **AbstractBase** — базовый класс для Python-воркеров, задающий общий
  жизненный цикл обработки задачи.

## Карта классов и директорий

| Роль | Файл |
|---|---|
| Registry (routing/capability matrix) | `app-symfony/src/Service/Conversion/ConversionRegistry.php` |
| Manager (оркестрация) | `app-symfony/src/Service/Conversion/ConversionManager.php` |
| Toggle-гейт (админ вкл/выкл пары) | `app-symfony/src/Service/Conversion/ConversionToggleService.php` |
| Queue-DTO (job-контракт на воркер) | `app-symfony/src/Message/ConversionMessage.php` |
| Status-DTO (ответ клиенту) | `app-symfony/src/DTO/ConversionResultDTO.php` |
| Request-DTO (вход Controller → Manager) | `app-symfony/src/DTO/ConversionRequestDTO.php` |
| Entity (персистентное состояние) | `app-symfony/src/Entity/Conversion.php`, `WorkerCapability.php`, `FileStorage.php` |
| Custom Messenger-transport (XADD в KeyDB Streams) | `app-symfony/src/Messenger/Transport/CleanRedisTransport.php`, `CleanRedisTransportFactory.php` |
| Messenger routing config (7 транспортов `conv_<key>` → stream `conv.<key>`, включая `conv_browser` с CNV-88 — пока без консьюмера) | `app-symfony/config/packages/messenger.yaml` |
| HTTP-вход (Controller) | `app-symfony/src/Controller/Api/ConversionController.php` |
| Live-статус из Redis-хэша | `app-symfony/src/Service/Queue/ConversionStatusReader.php` |
| Персист результата от воркера (relay) | `app-symfony/src/Service/Queue/ConversionResultPersister.php`, `Controller/Api/InternalWorkerController.php` |
| AbstractBase для on-server Python-воркеров | `workers/common/stream_consumer.py` (класс `StreamConsumerBase`) |
| WS-транспорт-клиент (общий для всех воркеров) | `workers/common/ws_client.py` |
| Конкретные on-server воркеры (наследники `StreamConsumerBase`) | `workers/image/worker.py` (`ImageWorker`), `workers/libreoffice/worker.py` (`LibreOfficeWorker`, стрим `document`), `workers/ffmpeg/worker.py` (`FfmpegWorker`, стримы `audio`+`video`), `workers/data/worker.py` (`DataWorker`) |
| Remote AI-воркер (НЕ наследует `StreamConsumerBase` — см. «Дрифт») | `workers/ai/worker.py` |
| WS-Gateway (единственный читатель KeyDB Streams) | `workers/gateway/` (`ws_server.py`, `keydb.py`, `relay.py`) |

## Поток запроса: Registry → Manager → Messenger → воркер

1. `ConversionController::convert()` (HTTP `POST /api/v1/.../convert`) собирает
   `ConversionRequestDTO` (`user`, `file`, `toFormat`, `ocr`, `privileged`) и
   вызывает `ConversionManager::createConversion(ConversionRequestDTO $request)`
   — DTO destructure'ится в локальные переменные в начале метода, дальше поток
   не меняется.
2. Manager спрашивает **Registry** — `isSupported()`/`getCategory()`/`isAi()`
   (или `isOcrSupported()` для OCR-флага) — чистая проверка пары форматов по
   матрице (CNV-71-02: единственный источник — статический коммиченный каталог
   `app-symfony/config/catalog/conversion_pairs.json`, загружает
   `ConversionRegistry::loadCatalogMatrix()`, per-request memo без
   межзапросного кеша; отсутствующий/невалидный/пустой файл — громкий
   `\RuntimeException`, не тихий фолбэк). Каталог генерируется из
   `worker_capabilities.json`, который генерируется из Python `CAPABILITIES`
   воркеров (`workers/tools/gen_worker_capabilities.py` +
   `workers/tools/capabilities_ast.py`, AST-экстракция); регенерация и
   drift-проверка — таргеты `formats-catalog`/`test-drift` в
   `workers/Makefile`. БД `worker_capabilities`/`WorkerCapabilityRepository`
   больше НЕ читается для построения матрицы — единственный оставшийся
   потребитель — `ConversionRegistry::getCapabilityWarnings()` (live-диагностика
   воркеров), а не роутинг.
3. Manager прогоняет гейты **в фиксированном порядке** (до любых S3/quota
   side-эффектов): toggle (`ConversionToggleService`) → ai/video-гейт для
   гостя → size (413) → mime (415) → quota (`QuotaService::check`).
4. Загружает файл в S3 (`S3Storage::putObject`), персистит `FileStorage` +
   `Conversion` entity (Doctrine).
5. `ConversionManager::dispatch()` строит `ConversionMessage` (queue-DTO,
   camelCase-контракт, см. `docs/queue-contract.md`) и кладёт его в шину:
   `$this->bus->dispatch($message, [new TransportNamesStamp(['conv_' . $key])])`,
   где `$key = ConversionRegistry::streamFor($from, $to, $ocr)` — чистая
   routing-функция (`ai` для AI-пар, иначе категория, `markup` схлопывается в
   `document`; CNV-88: ряд каталога может нести необязательное поле
   `executionKind` — override этой логики ПО ПАРЕ (независимый от category),
   напр. `browser` — сегодня ни один ряд его не несёт, механизм существует
   только на уровне схемы. CNV-106: `streamFor()` несёт ещё один, отдельный
   4-й параметр `$animated` — request-scoped override той же формы, что и
   `$ocr` (hardcoded allowlist в Registry, НЕ через каталожный `executionKind`
   — тот override per-ПАРЕ и переписал бы маршрут ОБОИХ вариантов одной пары;
   `$animated` нужен ИМЕННО потому, что одна and та же пара, напр. svg→gif,
   может требовать двух разных маршрутов в зависимости от запроса, а
   `conversion_pairs.json` хранит один маршрут на пару. Ни один живой
   HTTP-запрос сегодня не может выставить этот флаг — ни Controller, ни DTO,
   ни Manager его не читают).
6. Symfony Messenger отправляет в кастомный транспорт `conv_<key>`
   (`CleanRedisTransportFactory`, DSN-схема `conv+redis://`), который делает
   `XADD conv.<key> * message '<чистый JSON>'` — БЕЗ стандартной обёртки
   `{body,headers}` (Option D, см. `docs/queue-contract.md` §2).
7. Единственный читатель Streams — WS-Gateway (`workers/gateway/`, отдельный
   Python-сервис: `XREADGROUP`/`XACK`/`XAUTOCLAIM`). Он раздаёт задачи
   подключённым по WebSocket воркерам (on-server и remote) и потом сам делает
   `XACK` по результату — воркеры **никогда** не трогают KeyDB/S3 напрямую.
8. Воркер получает job по WS (`workers/common/ws_client.py: WsClient`), вызывает
   `StreamConsumerBase.process_job()` → `convert()` (реализован в подклассе) →
   `ResultSignal`. Малый результат — inline по WS; большой —
   `POST /jobs/{id}/result` в Symfony (см. `InternalWorkerController`).
9. Symfony (`ConversionResultPersister`) пишет финальный статус/файл в БД+S3;
   живой промежуточный статус (`conv:status:{id}`) пишет gateway напрямую в
   Redis, не PHP — читает его `ConversionStatusReader::read()`.

## Ссылка на образец

Архитектурный референс — https://github.com/Xakki/ExRate (Registry/Manager/DTO
слоение). Здесь имена и границы слоёв те же, но нет отдельного "Provider"
слоя — его роль частично берёт на себя связка Registry (матрица) + сами
Python-воркеры (исполнение).

## Дрифт от текста CLAUDE.md (зафиксировать при обнаружении новых расхождений)

- «AbstractBase для воркеров» из CLAUDE.md верно только для **4 из 5**
  типов воркеров (`image`, `libreoffice`/document, `ffmpeg`/audio+video,
  `data` — наследники `StreamConsumerBase`). Remote AI-воркер
  (`workers/ai/worker.py`) НЕ наследует `StreamConsumerBase` — он работает
  через `WsClient` напрямую с модульными функциями, без общего базового
  класса.
- Живых DTO — **три**: `ConversionRequestDTO` (вход Controller → Manager,
  `createConversion(ConversionRequestDTO $request)`), `ConversionMessage`
  (queue-job) и `ConversionResultDTO` (status-ответ клиенту). До рефакторинга
  `ConversionRequestDTO` был мёртвым кодом (объявлен, но не использовался) —
  теперь это реальный входной контракт.

**Перед тем как полагаться на факт из этого файла — сверься с указанными
исходниками; нашёл дрифт — поправь скилл в том же изменении и сообщи team-lead.**

### S1-01 — Чистый single-JSON wire-контракт (Option D кастомный Messenger-транспорт)

**Критичность:** High

**TAGS:**
- transport
- backend
- worker

**Описание:**
Устранить внешнюю обёртку конверта `{body,headers}` стокового `redis-messenger` так, чтобы поле `message` записи Stream несло **один чистый JSON** плоского camelCase payload'а задачи (Option D, §3/§4 spec). Стоковый `Connection::add` безусловно `json_encode`-ит `{body,headers}` в поле `message` — это структурно для Redis-транспорта, и кастомный *serializer* (Option B) её убрать не смог бы; убирает только кастомный *транспорт*. Привязать транспорты `conv_*` к нашему кастомному транспорту + factory: его `add()` делает `XADD` со значением `encode()['body']` **напрямую** в поле `message`. Итог: одна декодировка вместо двух, и контракт стрима становится НАШИМ (его никто не читает через Messenger — хендлеров нет, `messenger:consume conv_*` запрещён).

**Инвариант диспетча цел (§3):** `ConversionManager::dispatch()`, `ConversionMessage`, call-site и `TransportNamesStamp(['conv_'.$key])` routing — БАЙТ-В-БАЙТ без изменений. Меняется только реализация транспорта за `conv_*`.

**Caveat (жёстко):** phpredis-footgun `serializer: 0` (`\Redis::SERIALIZER_NONE`) **перенесён, а не устранён** — Redis-writer кастомного транспорта ОБЯЗАН выставлять `SERIALIZER_NONE`, иначе phpredis PHP-сериализует `message` и обернёт наш чистый JSON.

Одинарная декодировка на обеих сторонах: PHP `WorkerStreamGateway::parseMessage()` (`message` → JSON → задача), Python `workers/common/envelope.py` (`json.loads(message)` → dict). Сохранить защиты: poison-message (`parseOrAck`: при ошибке разбора — `XACK` + лог) и дроп `conversionId <= 0`.

**Файлы:**
- Создать: кастомный транспорт + factory под `app-symfony/src/` (напр. `src/Messenger/Transport/`).
- Создать: `workers/common/envelope.py` (одна `json.loads`, poison-safe).
- Создать: `app-symfony/tests/Fixtures/messenger_envelope.golden.json` (чистый single-JSON, плоский camelCase — НЕ двойно-обёрнутая форма).
- Изменить: `app-symfony/config/packages/messenger.yaml` (`dsn:`/класс транспорта `conv_*` → кастомный).
- Изменить: `app-symfony/src/Service/Worker/WorkerStreamGateway.php` (`parseMessage()` → одна декодировка).
- Изменить: `docs/queue-contract.md` §2 (описание чистого single-JSON контракта).

**Критерии приёмки:**
- Golden-тест PHP: `ConversionManager::dispatch()` → байты поля `message` записи Stream **==** `messenger_envelope.golden.json` (кастомный транспорт XADD-ит чистый JSON, без `{body,headers}`, с `SERIALIZER_NONE`).
- Golden-тест Python: `envelope.parse(fixture)` == ожидаемый dict задачи (одна `json.loads`).
- Poison-запись: искажённый `message` → `parseOrAck` делает `XACK` + лог (не крутится вечно); `conversionId <= 0` дропается.
- `dispatch()` / `ConversionMessage` / call-site диффом не тронуты (байт-идентичны).
- `make phpstan` / `make cs` зелёные; `pytest workers/tests` (envelope) зелёный.

**Зависит от:** —

**Эпик:** `[[s1-ws-worker-transport]]`

**Status:** ready (авто-ревью пройдено: APPROVE, нити закрыты, e2e зелёный `00d91d8`). Ждёт финального ready→done пользователя.

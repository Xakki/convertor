### Воркер регистрируется только один раз за WS-соединение, без ретраев

**Criticality:** High

**TAGS:**
- tech-debt
- registry
- worker
- resilience

**Description:**
`workers/common/ws_client.py::_run_connection()` при каждом успешном WS-подключении один раз
запускает `register = asyncio.create_task(self._register())` (строка 486) — параллельно с
`reader`/`pinger` на то же соединение. `_register()` (строки 614–628) — best-effort:

```python
async def _register(self) -> None:
    """Best-effort self-register on connect. Failure is non-fatal: logged and ignored."""
    if self._capabilities is None:
        return
    try:
        http = await self._get_http()
        url = f"{self._cfg.api_base}/api/v1/worker/register"
        resp = await http.post(
            url, headers=self._auth_headers(),
            json=self._build_register_body(), timeout=5.0,
        )
        resp.raise_for_status()
        logger.info("worker registered", extra={"workerType": self._cfg.worker_type})
    except Exception as exc:  # noqa: BLE001 — non-fatal: any failure → log + continue
        logger.warning("register failed (non-fatal)", extra={"error": str(exc)})
```

Любое исключение (HTTP-код, DNS, таймаут) молча логируется как WARNING и проглатывается — ни
ретрая, ни backoff, ни повторной попытки на этом же WS-соединении.

**Problem:**
Если HTTP-запрос `register()` не удался в момент подключения (например, конкурирующий деплой
переcоздаёт nginx/php/gateway), а само WS-соединение при этом устояло — воркер больше НИКОГДА не
перерегистрируется до следующего реконнекта WS (может не наступить днями). Наблюдалось
2026-07-23: в окне деплоя 02:40–02:42 (пересоздание nginx/php/gateway) 5 из 7 on-server воркеров
получили 502/400/DNS-ошибки на `register()`, не сделали ретрай, а WS-соединение осталось живым —
итог: перманентный лог `ERROR "PHP has no capability row for this instance"` в ws-gateway каждые
30с. Воркеры при этом продолжают обрабатывать джобы (WS исправен), но отсутствуют в
`worker_capabilities` → отсутствуют в матрице маршрутизации и на admin workers page.

**Impact:**
- Форматы, которые уникально объявляет пострадавший воркер (не покрытые seed-снапшотом/соседним
  инстансом), пропадают из `/formats` и матрицы маршрутизации до ручного вмешательства (рестарт
  воркера).
- Постоянный лог-шум ERROR в ws-gateway каждые 30с на каждый незарегистрированный инстанс.
- Пересекается с уже заведённой карточкой `[[liveness-orphaned-capability-reregister]]` (тот же
  симптом «orphan capability row», но с другим триггером — там TTL GC удаляет устаревшую строку у
  живого соединения; здесь строка вообще никогда не создавалась из-за упавшего `register()` в
  момент коннекта). Оба сценария лечатся тем же классом решений (периодическая
  само-регистрация / re-register по сигналу).

**Recommendation:**
Периодическая ре-регистрация (например, вместе с liveness-пингом или по таймеру), и/или чтобы
ws-gateway форсировал push переrегистрации при обнаружении condition «нет capability-строки для
подключённого инстанса» (это состояние уже детектируется и логируется).

**Эпик:** возможно объединить с `[[registry-00-self-registration]]` / `[[registry-06-liveness-push]]`.

**Зависит от:** пересекается с `[[liveness-orphaned-capability-reregister]]`.

**Status:** grooming.

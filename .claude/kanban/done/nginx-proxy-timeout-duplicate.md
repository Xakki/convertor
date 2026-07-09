# nginx: duplicate `proxy_read_timeout` рушит стек при пересоздании

**Приоритет:** High (блокирует любой recreate nginx: `make up`, `make test-e2e`, `make test-api-integration`; в момент находки положил convertor dev-nginx в crash-loop).
**Найдено:** задачей `api-integration-tests` (test-engineer) — интеграционный e2e-таргет не смог поднять стек.

## Симптом
```
[emerg] "proxy_read_timeout" directive is duplicate in /etc/nginx/conf.d/default.conf:73
```
nginx контейнер (`xakki-convertor-nginx-1`) в `Restarting` — падает на старте.

## Корень
Коммит `3d660ab` (WS-worker transport epic) добавил в общий include
`docker/nginx/params/proxy_params`:
```
proxy_connect_timeout 600;
proxy_send_timeout    600;
proxy_read_timeout    600;
```
А в `location ^~ /ws/worker/` (и dev, и prod) после `include params/proxy_params;`
стоит явный override:
- `docker/nginx/dev/conf.d/default.conf:73` → `proxy_read_timeout 3600s;` (+ `proxy_send_timeout 3600s;`)
- `docker/nginx/prod/conf.d/default.conf:68` → тот же паттерн

nginx запрещает директиве встречаться дважды в ОДНОМ контексте (include инлайнится
в тот же `location`) → `[emerg] duplicate`. Комментарий в конфиге предполагает, что
явная строка «перебьёт» 600s из proxy_params — но это неверно: override работает
только между уровнями (http→server→location), не внутри одного блока.

Баг латентный: работающий nginx держал старый конфиг в памяти; проявляется на первом
`--force-recreate`. Затрагивает **dev И prod** — любой пересоздаст nginx и упадёт.

## Намеренное поведение (сохранить)
Комментарий: `proxy_read/send_timeout ЗАВЕДОМО > самой долгой конвертации (video ~600s)`
— т.е. на `/ws/worker/` таймаут ДОЛЖЕН быть 3600s, не 600s.

## Предлагаемый фикс (Option A — минимальный, чинит корень)
Убрать три `*_timeout` из `params/proxy_params` и задать 600s-дефолты на уровне
`server {}` в dev+prod `default.conf`. Тогда per-location `proxy_read_timeout 3600s;`
в `/ws/worker/` — легальный override (разные уровни), дубликата нет, остальные
локации сохраняют 600s.
Альтернативы: (B) отдельный timeout-free include для `/ws/worker/`; (C) убрать
3600s и жить с 600s (меняет намерение, риск обрыва долгого video — НЕ рекомендуется).

## Проверка после фикса
`docker exec <nginx> nginx -t` зелёный; `make up` поднимает nginx healthy;
`make test-api-integration` доходит до PHPUnit.

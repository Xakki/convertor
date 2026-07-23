### `make queue-status` падает на AUTH при пустом REDIS_PASSWORD

**Criticality:** Low

**TAGS:**
- tech-debt
- makefile
- keydb

**Description:**
Таргет `queue-status` (`workers/Makefile:239-243`, подключён в корневой `Makefile` через
`include workers/Makefile` на строке 168) безусловно передаёт `-a "$(REDIS_PASSWORD)"`:

```makefile
.PHONY: queue-status
queue-status: ## Show queue lengths in KeyDB (db 2)
	@echo "=== Queue lengths (KeyDB db 2) ==="
	@docker exec $(KEYDB_CONT) keydb-cli -a "$(REDIS_PASSWORD)" -n $(REDIS_QUEUE_DB) \
	    eval "local ks=redis.call('keys','queue:*') local out={} for _,k in ipairs(ks) do out[#out+1]=k..': '..redis.call('llen',k) end return out" 0
```

`REDIS_PASSWORD` пустой и в `.env` (`REDIS_PASSWORD=`, строка 59), и не переопределён в
`.env.local`. При пустом пароле сервер KeyDB работает без AUTH, а `keydb-cli -a ""` шлёт AUTH-команду
на сервер без установленного пароля → keydb-cli возвращает ошибку AUTH вместо списка длин очередей.

**Problem:**
`make queue-status` не даёт полезный вывод в текущей конфигурации (REDIS_PASSWORD не задан).

Отдельно замечено при чтении таргета (расходится с ожиданием из workaround ниже): сам запрос
использует legacy-механизм `keys queue:*` + `llen` (списки), а не Streams `conv.<type>` +
`XLEN`/`XINFO GROUPS`, которые по `CLAUDE.md` являются актуальной моделью очередей
(`conv.document`, `conv.image`, `conv.audio`, `conv.video`, `conv.data`, `conv.ai`). Возможно,
таргет унаследован от достримовой реализации и не обновлён под текущую архитектуру — стоит
уточнить при фиксе AUTH-бага, актуален ли сам запрос.

**Impact:** низкий — есть рабочий обходной путь: `docker exec xakki-convertor-keydb keydb-cli -n 2
xlen conv.<type>` и `docker exec xakki-convertor-keydb keydb-cli -n 2 xinfo groups conv.<type>`.

**Recommendation:**
- Передавать `-a` только когда `REDIS_PASSWORD` непусто.
- Заодно проверить/обновить сам запрос таргета на Streams-модель (`XLEN`/`XINFO GROUPS` по
  `conv.*`) вместо `keys queue:*`/`llen`, если legacy-запрос больше не отражает реальные очереди.

**Контекст:** найдено в ходе диагностического прогона 2026-07-23 (проверка локальных и удалённых
воркеров, хост xBook).

**Status:** grooming.

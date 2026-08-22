# Convertor API Skill Synchronization Implementation Plan

> **For Hermes:** Use subagent-driven-development skill to implement this plan task-by-task.

**Goal:** Сделать публичный и локальный `convertor-api` одним и тем же skill, сохранив безопасную поддержку необязательной авторизации и guest-режима.

**Architecture:** Единственный редактируемый источник — `app-symfony/public/convertor-api/SKILL.md`. Публичный `/SKILL.md` раздаёт этот файл, а локальный `/convertor-api` подключается к тому же каталогу через read-only symlink; для других машин предусмотрена проверенная атомарная установка из публичного URL. Credentials никогда не входят в skill: агент использует `CONVERTOR_TOKEN` из окружения или runtime secret store, а при отсутствии токена один раз предлагает продолжить как guest либо настроить токен вне чата.

**Tech Stack:** Agent Skills `SKILL.md`, Bash installer для Linux/macOS, Symfony PHPUnit, Nginx, Makefile, `skills-ref` validator.

---

## Целевая модель

### Канонический контент

Единственный редактируемый файл:

```text
app-symfony/public/convertor-api/SKILL.md
```

Потребители:

```text
https://convertor.xakki.pro/SKILL.md
~/.claude/skills/convertor-api/SKILL.md
~/.hermes/skills/convertor-api/SKILL.md
~/.hermes/profiles/<profile>/skills/convertor-api/SKILL.md
```

На текущем хосте локальные consumers должны быть symlink на канонический каталог. Поэтому локальный и публичный варианты не требуют ручного редактирования и не расходятся.

### Credentials

Skill и credentials разделяются намеренно:

1. Агент проверяет `CONVERTOR_TOKEN` в process environment/runtime secret store.
2. Если токен задан, агент использует `Authorization: Bearer $CONVERTOR_TOKEN`, не выводя значение в лог, ответ или командный transcript.
3. Если токена нет, агент при первом использовании сообщает, что авторизация необязательна, и предлагает:
   - продолжить как guest;
   - настроить `CONVERTOR_TOKEN` вне чата и повторить;
   - использовать токен только для текущего процесса через безопасный secret-input механизм runtime.
4. Для guest-compatible операции безопасный default — продолжить как guest после выбора пользователя.
5. Для AI/video/другой операции, которую live API помечает как требующую auth, агент объясняет ограничение и просит настроить токен.
6. Агент никогда не предлагает вставлять токен в `SKILL.md`, репозиторий, URL, обычное сообщение чата или shell history.

Это позволяет обоим skills быть побайтово одинаковыми. Персональные credentials остаются локальным runtime overlay, а не второй версией skill.

## Предпосылка безопасности

Старый локальный `/convertor-api` содержит встроенный JWT. До миграции:

1. Считать токен раскрытым.
2. Отозвать/ротировать его через штатный auth flow.
3. Не переносить старое значение ни в новый skill, ни в installer, ни в тестовые fixtures.
4. Проверить точные локальные файлы `~/.claude/skills/convertor-api/SKILL.md` и `reference.md` на последующее удаление только после успешной миграции.

Ротация и любые production/auth-действия выполняются только после отдельного явного подтверждения пользователя.

---

### Task 1: Зафиксировать credential contract тестами

**Objective:** Описать ожидаемое поведение авторизации до изменения skill.

**Files:**
- Modify: `app-symfony/tests/Unit/PublicSkillTest.php`
- Test: `app-symfony/tests/Unit/PublicSkillTest.php`

**Steps:**

1. Добавить тест, требующий упоминание `CONVERTOR_TOKEN` без literal token value.
2. Добавить тест приоритета: configured token → authenticated request.
3. Добавить тест first-use flow: token absent → guest/configure choice.
4. Добавить тест, что token optional и guest flow документирован.
5. Добавить запрет на JWT/Bearer literal, secret assignments, PEM keys, account identifiers и machine-local credential paths.
6. Запустить targeted PHPUnit через проектный Makefile; ожидаемый результат до реализации — новые assertions падают.

**Verification:** Тесты падают только на отсутствующем credential contract, а не на существующем frontmatter или Nginx route.

### Task 2: Синхронизировать полезные инструкции в canonical skill

**Objective:** Сделать публичный skill функционально не слабее безопасной части старого локального варианта.

**Files:**
- Modify: `app-symfony/public/convertor-api/SKILL.md`
- Test: `app-symfony/tests/Unit/PublicSkillTest.php`

**Steps:**

1. Сохранить English-only frontmatter, discovery description/tags и обязательную загрузку `https://convertor.xakki.pro/api/doc.json` перед каждым использованием.
2. Добавить раздел `Authentication and first use` с credential contract из целевой модели.
3. Добавить краткий `Operational caveats` только из перепроверенных наблюдений:
   - `POST` возвращает `202` и conversion id;
   - polling идёт через status endpoint;
   - download выполняется только после `completed`;
   - guest session сохраняет одну cookie jar;
   - result скачивается сразу после completion;
   - AI jobs требуют умеренного polling и bounded wait;
   - результат проверяется по status, content type и ненулевому размеру.
4. Не переносить admin/account данные, статические персональные quotas, встроенный JWT, refresh-процедуры и утверждения, противоречащие live OpenAPI.
5. Запустить targeted PHPUnit; ожидаемый результат — PASS.
6. Запустить `skills-ref validate app-symfony/public/convertor-api`; ожидаемый результат — `Valid skill`.

**Verification:** Canonical skill остаётся English-only, standalone, без linked `reference.md` и без credentials.

### Task 3: Добавить безопасный installer/updater

**Objective:** Обеспечить повторяемую установку одного canonical skill в Claude/Hermes без ручного копирования.

**Files:**
- Create: `tools/agent-skills/sync-convertor-api.sh`
- Create: `tools/agent-skills/tests/sync-convertor-api.bats` или эквивалентный shell test в существующем формате проекта
- Modify: `Makefile`

**Steps:**

1. Добавить Makefile target `agent-skill-sync-convertor-api`, вызывающий installer без Docker-команд напрямую.
2. Installer принимает режим source:
   - `--source-repo` — текущий canonical каталог;
   - `--source-url https://convertor.xakki.pro/SKILL.md` — для другой машины.
3. Перед записью проверить HTTP `200`, `Content-Type`, Agent Skills frontmatter, отсутствие literal secrets и обязательный OpenAPI URL.
4. Для локального repo source создавать symlink на весь `convertor-api` directory.
5. Для URL source скачивать во временный каталог, валидировать и выполнять atomic rename; сохранять `.source-url` и `.sha256`.
6. Не перезаписывать неизвестный существующий каталог без `--replace`; сначала выводить dry-run diff/path summary.
7. Никогда не читать, копировать или удалять runtime credentials.
8. Добавить `--check`, который сравнивает resolved source и SHA-256 без изменений.

**Verification:** В изолированном временном `$HOME` install → check проходит; повторный install idempotent; malformed/secret-bearing skill отклоняется.

### Task 4: Мигрировать локальный `/convertor-api`

**Objective:** Заменить небезопасную локальную копию canonical skill без потери возможности авторизации.

**Files:**
- Replace after explicit approval: `~/.claude/skills/convertor-api`
- Link after explicit approval: `~/.hermes/skills/convertor-api`
- Link only for approved profiles: `~/.hermes/profiles/<profile>/skills/convertor-api`

**Steps:**

1. Выполнить installer `--check` и показать planned replacements.
2. Убедиться, что новый токен, если нужен, настроен через runtime secret store/`CONVERTOR_TOKEN`, а не через skill.
3. После явного подтверждения пользователя переместить старый каталог во временный quarantine вне loader roots; не печатать содержимое.
4. Создать symlink consumers на canonical directory.
5. Проверить, что старый `reference.md` больше не индексируется и duplicate skill отсутствует.
6. Запустить fresh Claude/Hermes session discovery и разрешить `/convertor-api` к ожидаемому canonical path.
7. После успешной проверки и отдельного подтверждения удалить quarantine.

**Verification:** SHA-256 локального `SKILL.md` совпадает с canonical/live, skill обнаруживается ровно один раз, token отсутствует в skill tree.

### Task 5: Проверить публичную и локальную интеграцию

**Objective:** Доказать равенство контента и работоспособность auth/guest сценариев.

**Files:**
- Modify if needed: `app-symfony/tests/Unit/PublicSkillTest.php`
- Modify if needed: `app-symfony/tests/Functional/Controller/Web/LegalControllerTest.php`

**Steps:**

1. `make test-php-unit` — ожидается PASS.
2. `make cs-check` — ожидается PASS.
3. `make phpstan` — ожидается PASS.
4. `make docker-check` — ожидается PASS.
5. `git diff --check` — ожидается PASS.
6. HTTP smoke: `/SKILL.md` → `200`, `text/markdown; charset=utf-8`.
7. Сравнить SHA-256 repo file, live response и каждого локального consumer; все значения должны совпасть.
8. Проверить live OpenAPI parsing и наличие базовых conversion/status/download/formats/quota operations без snapshot всего контракта.
9. Провести guest smoke на маленьком бесплатном conversion fixture, сохраняя cookie jar.
10. Auth smoke выполнять только при явно настроенном тестовом token и отдельном разрешении на billable/live operation; значение token не логировать.
11. Провести отдельное read-only security/code review.

**Verification:** Все проверки PASS; публичный и локальные skills идентичны; guest работает без token; auth flow использует только внешний credential source.

---

## Риски и trade-offs

- **Secret exposure:** невозможно безопасно сделать публичный и локальный skill одинаковыми, если credential встроен внутрь. Поэтому credentials обязаны быть внешним overlay.
- **Symlink portability:** symlink оптимален на текущем хосте; URL installer нужен для Windows, контейнеров и других машин.
- **Runtime differences:** не все агенты умеют безопасно сохранять secrets. Общий контракт должен требовать runtime secret store или environment, а не придумывать собственное хранилище внутри skill.
- **Guest limitations:** список auth-required операций нельзя хардкодить навечно; агент сверяет live OpenAPI и `/api/v1/formats`.
- **Billing:** authenticated retries/conversions могут списывать баланс; любые smoke tests должны быть bounded и явно разрешены.
- **Dirty workspace:** текущие изменения ещё не закоммичены. При реализации нужно сохранить baseline и не затронуть несвязанные файлы.

## Принятые допущения

- «Записанные credentials» означает token из environment/runtime secret store, а не token внутри `SKILL.md`.
- При отсутствии token агент не требует его обязательно: предлагает guest или безопасную настройку.
- На текущем хосте предпочтителен symlink к canonical repo directory; на других машинах — проверенная атомарная копия из live URL.
- Коммиты, push, ротация токена и удаление старого каталога требуют отдельного явного подтверждения пользователя.

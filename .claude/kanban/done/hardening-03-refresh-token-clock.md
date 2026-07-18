### RefreshTokenService: инъектируемые часы для тестов grace-окна

**Критичность:** Low

**TAGS:**
- chore
- tests
- refactor

**Описание:**
`RefreshTokenService` (введён задачей `auth-refresh-token`, 2026-06-22) берёт текущее время напрямую (`time()`), без инъектируемого clock.

**Проблема:**
- Тестирование grace-окна reuse-detection (ветка «prev-secret после graceUntil → reuse») потребовало от tester'а «состаривать» `graceUntil` прямой записью в стор (poke), т.к. время не управляется из теста. Time-independent ветка (secret «две ротации назад») тестируется без хака, но grace-граница — нет.

**Решение (ориентир):**
- Внедрить `Psr\Clock\ClockInterface` (Symfony Clock) в `RefreshTokenService`, заменить прямые вызовы `time()`.
- В тестах использовать `MockClock` для детерминированного перехода через grace-границу без манипуляций со стором.

**Критерии приёмки:**
- `RefreshTokenService` получает clock через DI.
- Тест grace-границы управляет временем через `MockClock`, без poke стора.

**Decisions:**
- 2026-06-22: находка ревью/тестов в `auth-refresh-token`; не баг (логика корректна против живого KeyDB), а улучшение тестируемости — вынесено отдельно.
- inject `Psr\Clock\ClockInterface`, MockClock in tests ; BOTH time() sites use the clock — RefreshTokenService.php:76 ($now) and :105 ((string)time() into Lua ARGV). Pure testability refactor, no behavior change.

**Status:** done.

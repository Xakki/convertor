### Мёртвый код: ConversionRequestDTO не используется

**Критичность:** Low

**TAGS:**
- tech-debt
- dead-code

**Описание:**
`app-symfony/src/DTO/ConversionRequestDTO.php` объявлен, но нигде не используется вне собственного файла. Реальная точка входа Manager принимает `User`/`UploadedFile`/`string $toFormat` напрямую, а не DTO. Найдено при выносе архитектуры в skill `backend-architecture` (2026-07-16). 

DTO — два других: `ConversionMessage` (очередь) и `ConversionResultDTO` (результат). Первоначальный контракт документирован в CLAUDE.md/skill как «DTO для передачи данных между слоями», но реально живут только две DTO. ConversionRequestDTO — реликт ранней архитектуры.

**Acceptance criteria:**

1. Убедиться, что ConversionRequestDTO действительно не используется: `grep -r "ConversionRequestDTO" app-symfony/` (кроме объявления в самом файле).
2. Решить: 
   - **Вариант A (рекомендуется):** удалить `app-symfony/src/DTO/ConversionRequestDTO.php` целиком.
   - **Вариант B:** довести до использования — рефакторить Manager входной контракт на `ConversionRequestDTO`, обновить документацию.
3. Обновить CLAUDE.md/skill: указать, что живых DTO две (`ConversionMessage`, `ConversionResultDTO`), реликт ConversionRequestDTO удалён либо отмечен как планируемый.

**Files:**
- `app-symfony/src/DTO/ConversionRequestDTO.php` (удалить или refactor)
- `CLAUDE.md` (обновить DTO-раздел)
- `app-symfony/src/Service/ConversionManager.php` (если идти по варианту B)

**Verify:** `grep -r ConversionRequestDTO` пусто (если вариант A); PHPStan зелёный; тесты Manager зелёные.

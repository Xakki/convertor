### PHPUnit Notice: mock без expectations в двух тестах (QueueStatsProvider/ConversionRegistryFallback)

**Criticality:** Nit

**TAGS:**
- tech-debt
- tests

**Description:**
`make test-php-live` завершается со статусом «OK, but there were issues!» —
3 `PHPUnit Notice` (PHPUnit 13 предупреждает про мок-объекты без сконфигурированных
expectations, рекомендует стаб вместо мока или атрибут
`#[AllowMockObjectsWithoutExpectations]`):
- `App\Tests\Unit\Service\Admin\QueueStatsProviderTest::testExtractsPerStreamMetricsAndSignals`
- `App\Tests\Unit\Service\Admin\QueueStatsProviderTest::testUnreachableExporterDegradesGracefully`
- `App\Tests\Unit\Service\Conversion\ConversionRegistryFallbackTest::testInvalidateMatrixResetsPerRequestCache`
  (мок `App\Repository\WorkerCapabilityRepository` без вызовов `->method()`/
  `->expects()`).

**Problem:**
Не влияет на прохождение тестов (0 failures/0 errors), но замусоривает вывод
`make test-php-live` и маскирует новые notice, если они появятся позже
(легко не заметить среди «ожидаемых» трёх).

**Recommendation:** заменить `createMock()` на `createStub()` там, где
expectations реально не нужны (см. паттерн `fakeProvider()` в
`OauthControllerTest`, который уже различает `createStub`/`createMock`).

**Контекст:** обнаружено при quality-gate карточки `oauth-02-google-github.md`
(2026-07-19) — предсуществующее, не введено этой карточкой; проверено прогоном
без новых Provider-тестов (notices остаются).

**Decisions:**
- Закрыто как уже исправленное (2026-08-01): моки без expectations в указанных тестах
  уже заменены/погашены в тестовом коде. Отдельная работа не нужна.

**Status:** done.

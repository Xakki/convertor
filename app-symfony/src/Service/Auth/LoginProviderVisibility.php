<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * Единственный источник правды для того, какие кнопки логина показывать на
 * `/login` в зависимости от серверной локали запроса (home-08). Это ЧИСТО
 * визуальная фильтрация (UX), не access-контроль: `OauthProviderRegistry`
 * продолжает регистрировать все 4 OAuth-адаптера, а `/api/v1/auth/oauth/
 * {provider}/start` и Telegram-флоу работают независимо от того, что здесь
 * возвращено — прямой переход по скрытому для локали провайдеру не ломается.
 *
 * Правило (см. .claude/kanban/progress/home-08-login-providers-by-locale.md,
 * согласовано в Decisions эпика home-00, не переоткрывать):
 * - локаль `ru` → только Yandex + VK;
 * - любая другая локаль (en и т.д.) → Google + GitHub + Telegram.
 *
 * Используется из `LoginController::index()` (передаёт готовый список во
 * Twig) — сам шаблон `auth/login.html.twig` только итерирует набор через
 * `in visibleProviders`, никакой if/else-логики по локали в шаблоне нет.
 */
final class LoginProviderVisibility
{
    private const RU_PROVIDERS      = ['yandex', 'vk'];
    private const DEFAULT_PROVIDERS = ['google', 'github', 'telegram'];

    /**
     * @return list<string> ключи провайдеров, видимых для данной локали
     *                       (OAuth-ключи `OauthProviderRegistry::key()` плюс
     *                       псевдо-ключ `telegram` для magic-link кнопки)
     */
    public function visibleFor(string $locale): array
    {
        return $locale === 'ru' ? self::RU_PROVIDERS : self::DEFAULT_PROVIDERS;
    }
}

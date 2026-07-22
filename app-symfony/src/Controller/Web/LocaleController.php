<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\EventListener\LocaleCookieListener;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Переключатель языка (i18n-фундамент, home-07). Открытая GET-ссылка (не
 * секретное, не мутирующее данные действие — CSRF-токен избыточен), ставит
 * cookie `locale` (SameSite=Lax, 1 год) и редиректит обратно на страницу,
 * откуда пришли (Referer, при том же хосте), иначе на `/`. Cookie получает
 * приоритет над Accept-Language на последующих запросах —
 * см. App\EventListener\LocaleCookieListener.
 *
 * Временное расположение самой кнопки — инлайн в шапке conversion/index.html.twig
 * и в карточке auth/login.html.twig: общего header-партиала ещё нет, его
 * заведёт home-01-header-nav и туда же перенесёт переключатель.
 *
 * `requirements: ['locale' => 'en|ru']` — литерал, ДОЛЖЕН совпадать с
 * LocaleCookieListener::SUPPORTED_LOCALES (PHP-атрибуты допускают только
 * constant-выражения, implode() тут недоступен).
 */
class LocaleController extends AbstractController
{
    private const COOKIE_TTL_SECONDS = 31536000; // 1 год

    #[Route(
        '/locale/{locale}',
        name: 'app_locale_switch',
        methods: ['GET'],
        requirements: ['locale' => 'en|ru'],
    )]
    public function switch(string $locale, Request $request): RedirectResponse
    {
        $response = new RedirectResponse($this->resolveRedirectTarget($request));
        $response->headers->setCookie(Cookie::create(
            LocaleCookieListener::COOKIE_NAME,
            $locale,
            time() + self::COOKIE_TTL_SECONDS,
            '/',
            null,
            $request->isSecure(),
            false,
            false,
            Cookie::SAMESITE_LAX,
        ));

        return $response;
    }

    /**
     * Referer — только если это тот же хост (не открытый редирект на
     * произвольный внешний URL); иначе безопасный фолбэк на главную.
     */
    private function resolveRedirectTarget(Request $request): string
    {
        $referer = $request->headers->get('referer');
        if ($referer !== null) {
            $refererHost = parse_url($referer, PHP_URL_HOST);
            if ($refererHost !== null && $refererHost === $request->getHost()) {
                return $referer;
            }
        }

        return $this->generateUrl('app_home');
    }
}

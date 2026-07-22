<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Отдаёт cookie `locale` приоритет НАД Accept-Language (i18n-фундамент, home-07).
 *
 * framework.yaml включает встроенный `set_locale_from_accept_language: true` —
 * \Symfony\Component\HttpKernel\EventListener\LocaleListener (kernel.request,
 * приоритет 16) сам детектит локаль по Accept-Language, но ТОЛЬКО если
 * request-атрибут `_locale` ещё не выставлен. Этот listener выставляет
 * `_locale` из cookie РАНЬШЕ (приоритет 17 — между роутингом на 32 и встроенным
 * на 16), поэтому итоговый приоритет: явный выбор через переключатель
 * (App\Controller\Web\LocaleController → ставит cookie) → cookie на
 * последующих визитах → Accept-Language → framework.default_locale (en).
 *
 * Значение cookie валидируется по SUPPORTED_LOCALES — иначе игнорируется
 * (падаем на Accept-Language/default, а не роняем запрос).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 17)]
final class LocaleCookieListener
{
    public const COOKIE_NAME = 'locale';
    /** @var list<string> */
    public const SUPPORTED_LOCALES = ['en', 'ru'];

    public function __invoke(RequestEvent $event): void
    {
        if (! $event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->attributes->has('_locale')) {
            return;
        }

        $locale = $request->cookies->get(self::COOKIE_NAME);
        if (is_string($locale) && in_array($locale, self::SUPPORTED_LOCALES, true)) {
            $request->attributes->set('_locale', $locale);
        }
    }
}

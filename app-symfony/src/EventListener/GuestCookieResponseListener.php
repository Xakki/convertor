<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\GuestAuthenticator;
use App\Service\Auth\GuestCookieFactory;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Вешает Set-Cookie `guest_id` на ответ, если GuestAuthenticator создал нового
 * гостя (положил подписанное значение в request-атрибут). В stateless firewall'е
 * аутентификатор не может выставить cookie сам, не оборвав контроллер, поэтому
 * это делается на kernel.response.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class GuestCookieResponseListener
{
    public function __construct(
        private readonly GuestCookieFactory $cookieFactory,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $signed = $event->getRequest()->attributes->get(GuestAuthenticator::ATTR_SET_COOKIE);
        if (! is_string($signed) || $signed === '') {
            return;
        }

        $event->getResponse()->headers->setCookie($this->cookieFactory->create($signed));
    }
}

<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Security\GuestAuthenticator;
use App\Service\Auth\GuestCookieFactory;
use App\Service\Auth\GuestTokenService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Вешает Set-Cookie `guest_id` на ответ, но ТОЛЬКО если гость реально
 * материализовался за этот запрос (ленивая модель).
 *
 * GuestAuthenticator кладёт в request-атрибут созданного ТРАНЗИЕНТНОГО гостя.
 * Строку в `users` создаёт лишь успешная конвертация (ConversionManager
 * персистит гостя → присваивает id). Поэтому здесь смотрим на id:
 *   - id!==null → гость персистнут за этот запрос → подписываем guestId и
 *     выставляем cookie;
 *   - id===null → это был /quota, /history или отклонённый convert, гость так и
 *     остался транзиентным → cookie НЕ выставляем (не плодим guest-строк/cookie).
 *
 * В stateless firewall'е аутентификатор не может выставить cookie сам, не
 * оборвав контроллер, поэтому это делается на kernel.response.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class GuestCookieResponseListener
{
    public function __construct(
        private readonly GuestCookieFactory $cookieFactory,
        private readonly GuestTokenService $tokenService,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $guest = $event->getRequest()->attributes->get(GuestAuthenticator::ATTR_GUEST_USER);
        if (! $guest instanceof User) {
            return;
        }

        // Гость остался транзиентным (не конвертировал) → cookie не выставляем.
        if ($guest->getId() === null) {
            return;
        }

        $signed = $this->tokenService->sign((string) $guest->getGuestId());
        $event->getResponse()->headers->setCookie($this->cookieFactory->create($signed));
    }
}

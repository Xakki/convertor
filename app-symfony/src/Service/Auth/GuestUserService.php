<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\Conversion;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Перепривязка истории гостя к реальному пользователю при Telegram-логине.
 *
 * Вызывается со стороны backend-B (в `poll`) при выдаче JWT, если в запросе
 * присутствует валидный guest-cookie. Сам сброс cookie здесь НЕ делается —
 * ответ формирует backend-B; для гашения cookie доступен GuestCookieFactory::clear().
 */
final class GuestUserService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Переназначить все Conversion гостя (по сырому $guestId) на $real,
     * затем деактивировать гостя и занулить его guestId (чтобы устаревшая
     * cookie не воскресила удалённого гостя).
     *
     * Идемпотентна и безопасна: если гость не найден, уже не гость, либо это
     * тот же самый пользователь — тихо выходим (no-op).
     */
    public function mergeInto(User $real, string $guestId): void
    {
        $guest = $this->users->findActiveGuestByGuestId($guestId);

        if ($guest === null || $guest->getId() === $real->getId()) {
            return;
        }

        // Bulk-UPDATE: одним запросом переносим владельца всех конвертаций гостя.
        $this->em->createQuery(
            'UPDATE ' . Conversion::class . ' c SET c.user = :real WHERE c.user = :guest',
        )
            ->setParameter('real', $real)
            ->setParameter('guest', $guest)
            ->execute();

        $guest->setIsActive(false);
        $guest->setGuestId(null);

        $this->em->flush();
    }
}

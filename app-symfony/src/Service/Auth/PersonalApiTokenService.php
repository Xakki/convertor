<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\PersonalApiToken;
use App\Entity\User;
use App\Repository\PersonalApiTokenRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PersonalApiTokenService
{
    public const MAX_ACTIVE_TOKENS = 3;
    public const PREFIX            = 'cnv_';

    public function __construct(
        private readonly PersonalApiTokenRepository $tokens,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array{token: PersonalApiToken, secret: string} */
    public function issue(User $user, string $label): array
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 100) {
            throw new \InvalidArgumentException('Token label must be 1-100 characters.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($user, $label): array {
            $lockedUser = $this->entityManager->find(User::class, $user->getId(), LockMode::PESSIMISTIC_WRITE);
            if (! $lockedUser instanceof User) {
                throw new \InvalidArgumentException('User not found.');
            }
            if ($this->tokens->countActiveForUser($lockedUser) >= self::MAX_ACTIVE_TOKENS) {
                throw new ConflictHttpException('Maximum active API tokens reached.');
            }
            $secret = self::PREFIX . self::base64Url(random_bytes(32));
            $token  = new PersonalApiToken($lockedUser, $label, substr($secret, 0, 12), hash('sha256', $secret));
            $this->tokens->save($token, true);

            return ['token' => $token, 'secret' => $secret];
        });
    }

    /** @return list<PersonalApiToken> */
    public function list(User $user): array
    {
        return $this->tokens->findActiveForUser($user);
    }

    public function authenticate(string $secret): ?PersonalApiToken
    {
        if (! str_starts_with($secret, self::PREFIX) || strlen($secret) < 40) {
            return null;
        }
        $token = $this->tokens->findByVerifier(hash('sha256', $secret));
        if ($token === null || ! $token->matches($secret) || ! $token->getUser()->isActive() || $token->getUser()->isGuest()) {
            return null;
        }

        try {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE personal_api_tokens SET last_used_at = :last_used_at WHERE id = :id',
                ['last_used_at' => new \DateTimeImmutable(), 'id' => $token->getId()],
                ['last_used_at' => 'datetime_immutable', 'id' => 'integer'],
            );
        } catch (\Throwable) {
            // Аутентификация уже проверена; сбой метаданных не должен её отменять.
        }

        return $token;
    }

    public function revoke(User $owner, int $id): void
    {
        $token = $this->tokens->find($id);
        if (! $token instanceof PersonalApiToken || $token->getUser()->getId() !== $owner->getId()) {
            throw new NotFoundHttpException('Token not found.');
        }
        if ($token->isActive()) {
            $token->revoke();
            $this->entityManager->flush();
        }
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

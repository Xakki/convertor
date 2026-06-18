<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\DTO\TelegramAuthDTO;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

class TelegramAuthService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly string $telegramBotToken,
    ) {}

    public function verify(TelegramAuthDTO $dto): bool
    {
        $checkString = $this->buildCheckString($dto);
        $secretKey = hash('sha256', $this->telegramBotToken, true);
        $expectedHash = hash_hmac('sha256', $checkString, $secretKey);

        return hash_equals($expectedHash, $dto->hash ?? '');
    }

    public function findOrCreateUser(TelegramAuthDTO $dto): User
    {
        $user = $this->userRepository->findByTelegramId($dto->id);

        if ($user !== null) {
            return $user;
        }

        $user = new User();
        $user->setTelegramId($dto->id);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function buildCheckString(TelegramAuthDTO $dto): string
    {
        $fields = array_filter([
            'auth_date'  => (string) $dto->authDate,
            'first_name' => $dto->firstName,
            'id'         => $dto->id,
            'last_name'  => $dto->lastName,
            'photo_url'  => $dto->photoUrl,
            'username'   => $dto->username,
        ], static fn (?string $v) => $v !== null);

        ksort($fields);

        return implode("\n", array_map(
            static fn (string $k, string $v) => "{$k}={$v}",
            array_keys($fields),
            array_values($fields),
        ));
    }
}

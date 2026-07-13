<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Auth\TelegramAvatarService;
use App\Service\Storage\S3Storage;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Профиль текущего пользователя. Только для залогиненного (не-гостя): гость и
 * аноним → 401 (у них нет TG-профиля). Аватар отдаётся как data-URI (в проекте
 * нет presign, а <img> не умеет слать Bearer) из закешированной в S3 копии.
 */
#[Route('/api/v1')]
class MeController extends AbstractController
{
    /** Потолок на размер аватара для data-URI (байты). Аватары мелкие; крупный — пропускаем. */
    private const AVATAR_MAX_BYTES = 512 * 1024;

    public function __construct(
        private readonly S3Storage $s3,
    ) {
    }

    #[Route('/me', methods: ['GET'])]
    #[OA\Tag(name: 'Auth')]
    #[OA\Get(summary: 'Профиль текущего пользователя (Telegram)')]
    #[OA\Response(
        response: 200,
        description: 'Профиль',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'id', type: 'integer', example: 123),
            new OA\Property(property: 'name', type: 'string', example: 'Иван'),
            new OA\Property(property: 'username', type: 'string', nullable: true, example: 'ivan'),
            new OA\Property(property: 'profile_url', type: 'string', nullable: true, example: 'https://t.me/ivan'),
            new OA\Property(property: 'avatar_url', type: 'string', nullable: true, example: 'data:image/jpeg;base64,...'),
            new OA\Property(property: 'plan', type: 'string', example: 'free'),
            new OA\Property(property: 'is_admin', type: 'boolean', example: false),
        ]),
    )]
    #[OA\Response(response: 401, description: 'Не аутентифицирован (гость/аноним)')]
    public function me(#[CurrentUser] ?User $user): JsonResponse
    {
        // Гость (ROLE_GUEST) и аноним не имеют TG-профиля → 401.
        if ($user === null || $user->isGuest()) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $username = $user->getUsername();
        $userId   = $user->getId();

        return $this->json([
            'id'          => $userId,
            'name'        => $user->getFirstName() ?? $username ?? ('User ' . $userId),
            'username'    => $username,
            'profile_url' => $username !== null && $username !== '' ? 'https://t.me/' . $username : null,
            'avatar_url'  => $this->avatarDataUri($user->getPhotoUrl()),
            'plan'        => $user->getPlan(),
            'is_admin'    => $user->isAdmin(),
        ]);
    }

    /**
     * Строит data-URI из закешированного в S3 аватара по ключу `photoUrl`.
     * null — нет ключа, объект пропал или он крупнее лимита.
     */
    private function avatarDataUri(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        $bytes = $this->s3->getObjectContents($this->s3->resultsBucket(), $key);
        if ($bytes === null || $bytes === '' || \strlen($bytes) > self::AVATAR_MAX_BYTES) {
            return null;
        }

        $ext = strtolower((string) pathinfo($key, PATHINFO_EXTENSION));

        return 'data:' . TelegramAvatarService::mimeForExt($ext) . ';base64,' . base64_encode($bytes);
    }
}

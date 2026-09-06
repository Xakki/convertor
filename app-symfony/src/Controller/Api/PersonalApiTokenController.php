<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Auth\PersonalApiTokenService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/auth/tokens')]
#[\Nelmio\ApiDocBundle\Attribute\Areas(['private_user'])]
#[OA\Tag(name: 'Personal API tokens')]
final class PersonalApiTokenController extends AbstractController
{
    public function __construct(private readonly PersonalApiTokenService $tokens)
    {
    }

    #[Route('', methods: ['POST'])]
    public function issue(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null || $user->isGuest()) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }
        $data  = json_decode($request->getContent(), true);
        $label = is_array($data) && isset($data['label']) && is_string($data['label']) ? $data['label'] : '';

        try {
            $result = $this->tokens->issue($user, $label);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(['id' => $result['token']->getId(), 'label' => $result['token']->getLabel(), 'token_prefix' => $result['token']->getTokenPrefix(), 'token' => $result['secret'], 'created_at' => $result['token']->getCreatedAt()->format(DATE_ATOM)], Response::HTTP_CREATED);
    }

    #[Route('', methods: ['GET'])]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null || $user->isGuest()) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(['items' => array_map(static fn ($token) => ['id' => $token->getId(), 'label' => $token->getLabel(), 'token_prefix' => $token->getTokenPrefix(), 'created_at' => $token->getCreatedAt()->format(DATE_ATOM), 'last_used_at' => $token->getLastUsedAt()?->format(DATE_ATOM)], $this->tokens->list($user))]);
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\\d+'])]
    public function revoke(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null || $user->isGuest()) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }
        $this->tokens->revoke($user, $id);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\ApiAuditRecordRepository;
use Nelmio\ApiDocBundle\Attribute\Areas;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/audit')]
#[Areas(['private_user'])]
#[OA\Tag(name: 'API audit history')]
final class ApiAuditController extends AbstractController
{
    public function __construct(private readonly ApiAuditRecordRepository $records)
    {
    }

    #[Route('/history', methods: ['GET'])]
    #[OA\Get(summary: 'List personal API-token audit history', description: 'Returns only completed personal API-token requests for the current owner. JWT, guest, worker, internal, and webhook requests are excluded.', security: [['PersonalToken' => []]])]
    #[OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20))]
    #[OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string', pattern: '^[A-Za-z0-9_-]+$'))]
    #[OA\Response(response: 200, description: 'Newest-first owner-scoped audit records', content: new OA\JsonContent(properties: [new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'next_cursor', type: 'string', nullable: true)]))]
    #[OA\Response(response: 400, description: 'Invalid cursor')]
    #[OA\Response(response: 401, description: 'Personal API token required')]
    public function history(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (! $user instanceof User || $user->isGuest() || $user->getId() === null) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }
        $limit = max(1, min(100, $request->query->getInt('limit', 20)));

        try {
            [$before, $beforeId] = $this->decodeCursor($request->query->get('cursor'));
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Invalid cursor.'], 400);
        }
        $records = $this->records->findForOwner($user, $limit + 1, $before, $beforeId);
        $hasMore = count($records) > $limit;
        if ($hasMore) {
            array_pop($records);
        }
        $nextCursor = null;
        if ($hasMore && $records !== []) {
            $last       = $records[array_key_last($records)];
            $nextCursor = $this->encodeCursor($last->getCreatedAt(), $last->getId());
        }

        return $this->json(['items' => array_map(static fn ($record): array => $record->toArray(), $records), 'next_cursor' => $nextCursor]);
    }

    /** @return array{?\DateTimeImmutable, ?int} */
    private function decodeCursor(?string $cursor): array
    {
        if ($cursor === null || $cursor === '') {
            return [null, null];
        }

        try {
            if ($cursor !== rtrim($cursor, '=') || preg_match('/^[A-Za-z0-9_-]+$/D', $cursor) !== 1) {
                throw new \InvalidArgumentException('Invalid cursor.');
            }
            $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
            if ($decoded === false) {
                throw new \InvalidArgumentException('Invalid cursor.');
            }
            if ($this->base64UrlEncode($decoded) !== $cursor) {
                throw new \InvalidArgumentException('Invalid cursor.');
            }
            $value = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($value) || array_keys($value) !== ['created_at', 'id'] || ! is_string($value['created_at'] ?? null) || ! is_int($value['id'] ?? null) || $value['id'] < 1) {
                throw new \InvalidArgumentException('Invalid cursor.');
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $value['created_at']) !== 1) {
                throw new \InvalidArgumentException('Invalid cursor.');
            }
            $createdAt = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value['created_at'], new \DateTimeZone('UTC'));
            if (! $createdAt instanceof \DateTimeImmutable || $createdAt->format('Y-m-d\TH:i:s.u\Z') !== $value['created_at']) {
                throw new \InvalidArgumentException('Invalid cursor.');
            }

            return [$createdAt, $value['id']];
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Invalid cursor.');
        }
    }

    private function encodeCursor(\DateTimeImmutable $createdAt, ?int $id): string
    {
        if ($id === null || $id < 1) {
            throw new \InvalidArgumentException('Invalid cursor.');
        }
        $value = json_encode([
            'created_at' => $createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'id'         => $id,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->base64UrlEncode($value);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

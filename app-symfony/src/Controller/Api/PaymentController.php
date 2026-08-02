<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Exception\TopUpNotAllowedException;
use App\Exception\UnknownTopUpPackException;
use App\Repository\BalanceTransactionRepository;
use App\Service\Billing\PaymentTopUpService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Prepaid top-up через Telegram Stars (CNV-28 slice 6).
 */
#[Route('/api/v1/payment')]
#[IsGranted('ROLE_USER')]
class PaymentController extends AbstractController
{
    private const HISTORY_DEFAULT_LIMIT = 50;
    private const HISTORY_MAX_LIMIT     = 100;

    public function __construct(
        private readonly PaymentTopUpService $topUpService,
        private readonly BalanceTransactionRepository $balanceTransactionRepository,
    ) {
    }

    /**
     * Список доступных пакетов пополнения (presets из TOPUP_PACKS_JSON).
     */
    #[Route('/packs', methods: ['GET'])]
    #[OA\Tag(name: 'Payment')]
    #[OA\Get(summary: 'Список пакетов пополнения prepaid-баланса')]
    #[OA\Response(
        response: 200,
        description: 'Пакеты пополнения',
        content: new OA\JsonContent(properties: [
            new OA\Property(
                property: 'packs',
                type: 'array',
                items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'string', example: 'pack_100'),
                    new OA\Property(property: 'usd_cents', type: 'integer', example: 100),
                    new OA\Property(property: 'stars', type: 'integer', example: 100),
                ]),
            ),
        ]),
    )]
    #[OA\Response(response: 401, description: 'Требуется аутентификация')]
    #[OA\Response(response: 403, description: 'Только ROLE_USER (гость недопущен)')]
    public function packs(): JsonResponse
    {
        $packs = array_map(
            static fn ($pack) => [
                'id'        => $pack->id,
                'usd_cents' => $pack->usdCents,
                'stars'     => $pack->stars,
            ],
            $this->topUpService->listPacks(),
        );

        return $this->json(['packs' => $packs]);
    }

    /**
     * Создать invoice-link для пополнения баланса (Telegram Stars).
     *
     * Предпочитаемый путь — `/payment/topup`; алиас `/payment/telegram-stars` — ROADMAP.
     */
    #[Route('/topup', methods: ['POST'])]
    #[Route('/telegram-stars', methods: ['POST'])]
    #[OA\Tag(name: 'Payment')]
    #[OA\Post(
        summary: 'Создать ссылку на оплату пакета (Telegram Stars)',
        description: 'Предпочитаемый путь — `/payment/topup`. Алиас `/payment/telegram-stars` — совместимость с ROADMAP.',
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['pack'],
            properties: [
                new OA\Property(property: 'pack', type: 'string', example: 'pack_100'),
            ],
        ),
    )]
    #[OA\Response(
        response: 200,
        description: 'Ссылка на оплату',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'invoice_link', type: 'string', example: 'https://t.me/$...'),
            new OA\Property(property: 'pack', type: 'string', example: 'pack_100'),
            new OA\Property(property: 'usd_cents', type: 'integer', example: 100),
            new OA\Property(property: 'stars', type: 'integer', example: 100),
        ]),
    )]
    #[OA\Response(response: 401, description: 'Требуется аутентификация')]
    #[OA\Response(response: 403, description: 'Гость / нет привязки Telegram')]
    #[OA\Response(response: 404, description: 'Неизвестный пакет')]
    #[OA\Response(response: 422, description: 'Невалидное тело запроса')]
    public function topUp(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (! is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $packId = $payload['pack'] ?? null;
        if (! is_string($packId) || $packId === '') {
            return $this->json(['error' => 'pack required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->topUpService->createInvoiceLink($user, $packId);
        } catch (TopUpNotAllowedException $e) {
            return $this->json(
                [
                    'error'   => $this->topUpErrorCode($e),
                    'message' => $e->getMessage(),
                ],
                Response::HTTP_FORBIDDEN,
            );
        } catch (UnknownTopUpPackException $e) {
            return $this->json(
                ['error' => 'unknown_pack', 'message' => $e->getMessage()],
                Response::HTTP_NOT_FOUND,
            );
        }

        $metadata = $result['payment']->getMetadata();
        if (! is_array($metadata)) {
            $metadata = [];
        }

        return $this->json([
            'invoice_link' => $result['invoice_link'],
            'pack'         => is_string($metadata['pack_id'] ?? null) ? $metadata['pack_id'] : $packId,
            'usd_cents'    => (int) ($metadata['usd_cents'] ?? 0),
            'stars'        => (int) ($metadata['stars'] ?? 0),
        ]);
    }

    /**
     * История ledger prepaid-баланса текущего пользователя.
     */
    #[Route('/history', methods: ['GET'])]
    #[OA\Tag(name: 'Payment')]
    #[OA\Get(summary: 'История операций prepaid-баланса')]
    #[OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50, maximum: 100))]
    #[OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 0, minimum: 0))]
    #[OA\Response(
        response: 200,
        description: 'История операций',
        content: new OA\JsonContent(properties: [
            new OA\Property(
                property: 'items',
                type: 'array',
                items: new OA\Items(properties: [
                    new OA\Property(property: 'amount_cents', type: 'integer', example: 100),
                    new OA\Property(property: 'type', type: 'string', example: 'credit'),
                    new OA\Property(property: 'source', type: 'string', example: 'payment'),
                    new OA\Property(property: 'ref_id', type: 'string', nullable: true, example: 'charge-abc'),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                ]),
            ),
        ]),
    )]
    #[OA\Response(response: 401, description: 'Требуется аутентификация')]
    #[OA\Response(response: 403, description: 'Только ROLE_USER (гость недопущен)')]
    public function history(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $limit  = min(self::HISTORY_MAX_LIMIT, max(1, $request->query->getInt('limit', self::HISTORY_DEFAULT_LIMIT)));
        $offset = max(0, $request->query->getInt('offset', 0));

        $items = array_map(
            static fn ($tx) => [
                'amount_cents' => $tx->getAmountCents(),
                'type'         => $tx->getType()->value,
                'source'       => $tx->getSource()->value,
                'ref_id'       => $tx->getRefId(),
                'created_at'   => $tx->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
            $this->balanceTransactionRepository->findByUser($user, $limit, $offset),
        );

        return $this->json(['items' => $items]);
    }

    private function topUpErrorCode(TopUpNotAllowedException $e): string
    {
        return str_contains($e->getMessage(), 'Telegram')
            ? 'telegram_link_required'
            : 'topup_not_allowed';
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Entity\User;
use App\Repository\PlanRepository;
use App\Repository\UserRepository;
use App\Service\Quota\QuotaService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Управление пользователями admin-панели (эпик admin-panel, подзадача users).
 *
 * Поиск/список, ban/unban (через `User::isActive`), ручной сброс дневной квоты
 * (через QuotaService, без дублирования логики счётчиков) и смена плана
 * (валидация против таблицы `plans`). Реальная граница — ROLE_ADMIN на
 * JWT-firewall (Option B): для не-админа 403, как и у остальных admin-API.
 */
#[Route('/api/v1/admin/users')]
#[IsGranted('ROLE_ADMIN')]
class UserController extends AbstractController
{
    private const PAGE_SIZE = 20;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PlanRepository $plans,
        private readonly QuotaService $quota,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_api_users_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $q      = $request->query->get('q');
        $q      = is_string($q) ? $q : null;
        $page   = max(1, (int) $request->query->get('page', '1'));
        $offset = ($page - 1) * self::PAGE_SIZE;
        // guest=0|omit → только зарегистрированные; guest=1 → только анонимы.
        $guestOnly = $request->query->getBoolean('guest');

        $result = $this->users->searchPaginated($q, self::PAGE_SIZE, $offset, $guestOnly);
        $total  = $result['total'];

        return $this->json([
            'items'    => array_map($this->serialize(...), $result['items']),
            'page'     => $page,
            'pageSize' => self::PAGE_SIZE,
            'total'    => $total,
            'pages'    => $total > 0 ? (int) ceil($total / self::PAGE_SIZE) : 1,
        ]);
    }

    #[Route('/{id}/ban', name: 'admin_api_users_ban', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ban(int $id): JsonResponse
    {
        return $this->setActive($id, false);
    }

    #[Route('/{id}/unban', name: 'admin_api_users_unban', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unban(int $id): JsonResponse
    {
        return $this->setActive($id, true);
    }

    #[Route('/{id}/reset-quota', name: 'admin_api_users_reset_quota', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resetQuota(int $id): JsonResponse
    {
        $user = $this->users->find($id);
        if ($user === null) {
            return $this->notFound();
        }

        // Дневные счётчики → 0 + bump quotaResetAt. Логика счётчиков живёт в
        // QuotaService (единая точка) — контроллер её не дублирует.
        $this->quota->reset($user);

        return $this->json($this->serialize($user));
    }

    #[Route('/{id}/plan', name: 'admin_api_users_plan', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function changePlan(int $id, Request $request): JsonResponse
    {
        $user = $this->users->find($id);
        if ($user === null) {
            return $this->notFound();
        }

        $plan = $request->getPayload()->get('plan');
        if (! is_string($plan) || $plan === '' || $this->plans->findByName($plan) === null) {
            return $this->json(['error' => 'invalid_plan'], Response::HTTP_BAD_REQUEST);
        }

        $user->setPlan($plan);
        $this->em->flush();

        return $this->json($this->serialize($user));
    }

    private function setActive(int $id, bool $active): JsonResponse
    {
        $user = $this->users->find($id);
        if ($user === null) {
            return $this->notFound();
        }

        // Бан не мгновенный: уже выданный access-JWT (stateless) живёт до
        // истечения (≤1ч). Блокировка срабатывает при следующем refresh —
        // AuthController::refresh проверяет isActive и убивает refresh-семейство.
        $user->setIsActive($active);
        $this->em->flush();

        return $this->json($this->serialize($user));
    }

    private function notFound(): JsonResponse
    {
        return $this->json(['error' => 'user_not_found'], Response::HTTP_NOT_FOUND);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(User $user): array
    {
        return [
            'id'            => $user->getId(),
            'email'         => $user->getEmail(),
            'telegramId'    => $user->getTelegramId(),
            'plan'          => $user->getPlan(),
            'isActive'      => $user->isActive(),
            'isGuest'       => $user->isGuest(),
            'isAdmin'       => $user->isAdmin(),
            'quotaCounters' => [
                'light'  => ['daily' => $user->getLightDailyConversions(), 'monthly' => $user->getLightMonthlyConversions()],
                'medium' => ['daily' => $user->getMediumDailyConversions(), 'monthly' => $user->getMediumMonthlyConversions()],
                'heavy'  => ['daily' => $user->getHeavyDailyConversions(), 'monthly' => $user->getHeavyMonthlyConversions()],
                'ai'     => ['daily' => $user->getAiDailyConversions(), 'monthly' => $user->getAiMonthlyConversions()],
            ],
            'quotaResetAt'   => $user->getQuotaResetAt()->format(\DateTimeInterface::ATOM),
            'monthlyResetAt' => $user->getMonthlyResetAt()->format(\DateTimeInterface::ATOM),
            'createdAt'      => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}

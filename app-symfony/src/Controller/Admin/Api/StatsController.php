<?php

declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Repository\ConversionRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Stats-эндпоинт admin-панели (эпик admin-panel, подзадача stats).
 *
 * Отдаёт агрегаты конвертаций и пользователей одним JSON'ом; UI рендерит их
 * клиентом (Alpine + Chart.js). Реальная граница безопасности — ROLE_ADMIN на
 * JWT-firewall (Option B), как и у AdminApiController: для не-админа 403.
 *
 * Revenue — плейсхолдер (Payment сейчас не персистится, реальную выручку
 * посчитать нельзя): плитка отдаёт 0/«n/a».
 */
#[Route('/api/v1/admin')]
#[IsGranted('ROLE_ADMIN')]
class StatsController extends AbstractController
{
    /** Границы окна графика (суток), чтобы не строить произвольно большой ряд. */
    private const DAYS_MIN     = 1;
    private const DAYS_MAX     = 90;
    private const DAYS_DEFAULT = 7;

    public function __construct(
        private readonly ConversionRepository $conversions,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('/stats', name: 'admin_api_stats', methods: ['GET'])]
    public function stats(Request $request): JsonResponse
    {
        $days = (int) $request->query->get('days', (string) self::DAYS_DEFAULT);
        $days = max(self::DAYS_MIN, min(self::DAYS_MAX, $days));

        $series = $this->conversions->seriesByDay($days);
        $ai     = $this->conversions->countByAi();

        return $this->json([
            'totals' => [
                'conversions'      => $this->conversions->countTotal(),
                'conversionsToday' => $this->conversions->countToday(),
                'avgProcessingMs'  => $this->conversions->avgProcessingMs(),
                'errorRate'        => $this->conversions->errorRate(),
                'users'            => $this->users->countAll(),
                'activeUsers'      => $this->users->countActive(),
                'guestUsers'       => $this->users->countGuests(),
            ],
            // Revenue — плейсхолдер: реальная выручка вернётся в Стадию 6.
            'revenue' => [
                'value'       => 0,
                'label'       => 'n/a',
                'placeholder' => true,
            ],
            'byStatus' => $this->conversions->countByStatus(),
            'byFormat' => $this->conversions->topFormatPairs(10),
            'ai'       => $ai,
            'chart'    => $series,
            'signups'  => $this->users->signupsByDay($days),
            'days'     => $days,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Repository\HostTelemetrySnapshotRepository;
use App\Repository\WorkerCapabilityRepository;
use App\Service\Admin\WorkerStatsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Обзор зарегистрированных воркеров admin-панели (registry-07, финальный шаг
 * эпика `registry-00-self-registration`; CNV-61 добавил per-host агрегат для
 * ленивой загрузки страницы).
 *
 * Отдаёт список capability-строк (`worker_capabilities`) одним JSON'ом; UI
 * поллит клиентом (Alpine + `window.admin.fetch`), как `/admin/queues`.
 * Реальная граница — ROLE_ADMIN на JWT-firewall (Option B): для не-админа 403.
 */
#[Route('/api/v1/admin')]
#[IsGranted('ROLE_ADMIN')]
class WorkerController extends AbstractController
{
    public function __construct(
        private readonly WorkerStatsProvider $stats,
        private readonly WorkerCapabilityRepository $workerCapabilities,
        private readonly HostTelemetrySnapshotRepository $hostSnapshots,
    ) {
    }

    /**
     * `?host=<name>` / `?hostNull=1` — два ВЗАИМОИСКЛЮЧАЮЩИХ опциональных
     * фильтра (CNV-61 review, finding #2). Раньше был единый sentinel-литерал
     * `?host=__none__` для легacy-бакета `host IS NULL` — коллизионноопасный
     * НА КЛИЕНТЕ: реальный хост, буквально названный `__none__`, и null-бакет
     * мапились бы в один и тот же Alpine `:key`, ломая `x-for`
     * ({@see \App\Service\Admin\WorkerStatsProvider::NULL_HOST_BUCKET_KEY}
     * коллизионно-безопасен на бэке через NUL-байт, но wire-контракт `?host=`
     * был обычной строкой — коллизия была возможна там). Теперь:
     *  - `?host=<name>` — только воркеры этого РЕАЛЬНОГО хоста, включая хост,
     *    буквально названный `__none__` (это просто имя, больше не sentinel);
     *  - `?hostNull=1` — легacy-бакет `host IS NULL`;
     *  - оба параметра одновременно → 400;
     *  - ни один параметр → полный список, форма ответа не меняется
     *    (аддитивная правка, старые вызовы без параметров не ломаются).
     */
    #[Route('/workers', name: 'admin_api_workers', methods: ['GET'])]
    public function workers(Request $request): JsonResponse
    {
        $data = $this->stats->collect();

        $hostParam = $request->query->get('host');
        $hasHost   = is_string($hostParam) && $hostParam !== '';
        $hostNull  = $request->query->getBoolean('hostNull');

        if ($hasHost && $hostNull) {
            return $this->json(['error' => 'Параметры host и hostNull взаимоисключающие'], 400);
        }

        if ($hasHost || $hostNull) {
            $wanted          = $hasHost ? $hostParam : null;
            $data['workers'] = array_values(array_filter(
                $data['workers'],
                static fn (array $row): bool => $row['host'] === $wanted,
            ));
        }

        return $this->json($data);
    }

    /**
     * Per-host агрегат (CNV-61) — сначала грузится этот, детальный список
     * воркеров конкретного хоста подгружается лениво через `?host=` выше.
     */
    #[Route('/workers/hosts', name: 'admin_api_workers_hosts', methods: ['GET'])]
    public function hosts(): JsonResponse
    {
        return $this->json($this->stats->collectHosts());
    }

    #[Route('/workers/telemetry', name: 'admin_api_workers_telemetry', methods: ['GET'])]
    public function telemetry(): JsonResponse
    {
        $now  = new \DateTimeImmutable();
        $rows = [];
        foreach ($this->hostSnapshots->findAllSnapshots() as $snapshot) {
            $data          = $snapshot->getData();
            $data['stale'] = $snapshot->getObservedAt() < $now->modify('-20 minutes');
            $rows[]        = $data;
        }

        return $this->json(['contractVersion' => 1, 'snapshots' => $rows]);
    }

    /**
     * CNV-61: ручное удаление ЗАСТРЯВШИХ рядов `worker_capabilities` по
     * статусу (`disconnected`/`unknown`), одной глобальной кнопкой admin-
     * страницы — не заменяет и не трогает расписание/поведение
     * {@see \App\Service\Worker\WorkerCapabilityGcService} (та чистит по
     * возрасту `last_seen`, эта — по статусу, независимо от возраста).
     *
     * Ничего не совпало → честный `{"deleted": 0}`, HTTP 200 (не ошибка).
     *
     * CNV-71-02: больше НЕ трогает роутинг-матрицу (`ConversionRegistry`) — та
     * строится из статического каталога, не из `worker_capabilities`, и не
     * реагирует на удаление рядов этой таблицы. `/formats` не меняется.
     */
    #[Route('/workers/stale', name: 'admin_api_workers_delete_stale', methods: ['DELETE'])]
    public function deleteStale(): JsonResponse
    {
        $deleted = $this->workerCapabilities->deleteStaleByStatus();

        return $this->json(['deleted' => $deleted]);
    }
}

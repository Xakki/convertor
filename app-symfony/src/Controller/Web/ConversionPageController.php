<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Service\Conversion\ConversionRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * SEO-страницы под конкретную пару конвертации (home-09-seo-conversion-pages):
 * `/convert/{source}-to-{target}` — уникальный H1/title/meta description/
 * canonical под пару + форма converterApp() из templates/conversion/index.html.twig,
 * зафиксированная на этой паре (см. templates/conversion/pair.html.twig и
 * параметризацию converterApp() в templates/partials/_converter_app_script.html.twig).
 *
 * Слаг парсится по разделителю `-to-`; пара валидируется через
 * {@see ConversionRegistry::isSupported()} — тот же реестр, что отдаёт
 * GET /api/v1/formats, БЕЗ отдельного HTTP-вызова к своему же API (читаем
 * сервис напрямую, как и остальные Web-контроллеры проекта).
 */
class ConversionPageController extends AbstractController
{
    public function __construct(
        private readonly ConversionRegistry $registry,
    ) {
    }

    #[Route('/convert/{pair}', name: 'app_conversion_pair', methods: ['GET'])]
    public function show(string $pair): Response
    {
        $parts = explode('-to-', $pair, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new NotFoundHttpException('Malformed conversion pair slug');
        }

        [$source, $target] = $parts;

        if (! $this->registry->isSupported($source, $target)) {
            throw new NotFoundHttpException("Unsupported conversion pair: {$source} → {$target}");
        }

        return $this->render('conversion/pair.html.twig', [
            'source'   => $source,
            'target'   => $target,
            'category' => $this->registry->getCategory($source, $target)->value,
        ]);
    }
}

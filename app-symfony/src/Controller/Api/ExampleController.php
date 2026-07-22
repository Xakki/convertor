<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Example;
use App\Repository\ExampleRepository;
use App\Service\Storage\S3Storage;
use AsyncAws\S3\Exception\NoSuchKeyException;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Публичная витрина живых примеров конвертаций (home-04, часть B). Без auth:
 * примеры лежат в стабильном префиксе `examples/` бакета результатов.
 *
 * ИСТОЧНИК ДАННЫХ — таблица {@see Example} (карточка admin-managed-examples),
 * НЕ захардкоженный {@see \App\Service\Examples\ExampleCatalog} (тот остаётся
 * только seed-источником для {@see \App\Command\SeedExamplesCommand} и
 * {@see \App\Service\Examples\ExamplePromotionService} для admin-промо).
 * ПУБЛИЧНЫЙ КОНТРАКТ (поля JSON, URL-шаблоны) НЕ ИЗМЕНИЛСЯ — фронт home-04/home-10
 * не тронут.
 *
 * Видимость решена БЭК-стримингом (а не presigned-URL и не anonymous-бакетом):
 * `ConversionController::download()` в проекте тоже НЕ presign'ит, а стримит
 * объект через authenticated-прокси ({@see S3Storage::downloadResponse}) —
 * поэтому публичный prefix-scoped стриминг = переиспользование СУЩЕСТВУЮЩЕГО
 * паттерна, а не отход от него. MinIO-политику бакета не трогаем.
 *
 * Ключ строится ТОЛЬКО из найденной DB-строки (whitelist — тот же принцип, что и
 * раньше был у ExampleCatalog, теперь источник whitelist — таблица `examples`),
 * а `name` дополнительно ограничен `[a-z0-9._-]` (нет `/` → нет path-traversal
 * за пределы `examples/<category>/`).
 *
 * home-10: исходный sample-файл теперь ТОЖЕ отдаётся из S3 (не с локального
 * диска, как раньше) — {@see \App\Command\SeedExamplesCommand} загружает его
 * копию в тот же `examples/`-префикс, что унифицирует код с admin-промо
 * (у промо-примеров исходник — S3-объект конвертации, диска вообще нет).
 */
#[Route('/api/v1/examples')]
final class ExampleController extends AbstractController
{
    public function __construct(
        private readonly ExampleRepository $repository,
        private readonly S3Storage $s3,
    ) {
    }

    #[Route('', name: 'api_examples_list', methods: ['GET'])]
    #[OA\Tag(name: 'Examples')]
    #[OA\Get(summary: 'Список живых примеров конвертаций для лендинга (публичный)')]
    #[OA\Response(
        response: 200,
        description: 'Примеры, для которых результат реально существует в S3',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'examples', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'category', type: 'string', example: 'image'),
                new OA\Property(property: 'from', type: 'string', example: 'png'),
                new OA\Property(property: 'to', type: 'string', example: 'jpg'),
                new OA\Property(property: 'filename', type: 'string', example: 'png-to-jpg.jpg'),
                new OA\Property(property: 'mime', type: 'string', example: 'image/jpeg'),
                new OA\Property(property: 'size', type: 'integer', example: 20481),
                new OA\Property(property: 'previewable', type: 'boolean', example: false),
                new OA\Property(property: 'url', type: 'string', example: '/api/v1/examples/file/image/png-to-jpg.jpg'),
                new OA\Property(property: 'source_format', type: 'string', example: 'png'),
                new OA\Property(property: 'source_mime', type: 'string', example: 'image/png'),
                new OA\Property(property: 'source_url', type: 'string', example: '/api/v1/examples/source/image/image.png'),
            ])),
        ]),
    )]
    public function list(): JsonResponse
    {
        $bucket = $this->s3->resultsBucket();
        $items  = [];

        foreach ($this->repository->findAllOrdered() as $example) {
            // Показываем только собранные примеры: objectStat (один HEAD) сразу
            // и проверяет наличие (null → пропускаем несобранный/удалённый мимо
            // приложения объект), и даёт актуальный размер — без второго
            // round-trip к S3.
            $stat = $this->s3->objectStat($bucket, $example->getResultKey());
            if ($stat === null) {
                continue;
            }

            $items[] = [
                'category'    => $example->getCategory(),
                'from'        => $example->getFromFormat(),
                'to'          => $example->getToFormat(),
                'filename'    => $example->getFilename(),
                'mime'        => $example->getMime(),
                'size'        => $stat['size'],
                'previewable' => $example->isPreviewable(),
                'url'         => $this->fileUrl($example),
                // home-10: исходный sample-файл — отдаётся публично отдельным
                // inline-эндпоинтом, карточка примера кликабельна с обеих сторон
                // (source→result), не только по результату.
                'source_format' => $example->getSourceFormat(),
                'source_mime'   => $example->getSourceMime(),
                'source_url'    => $this->sourceUrl($example),
            ];
        }

        return $this->json(['examples' => $items]);
    }

    #[Route('/file/{category}/{name}', name: 'api_examples_file', methods: ['GET'], requirements: [
        'category' => '[a-z]+',
        'name'     => '[a-z0-9._-]+',
    ])]
    #[OA\Tag(name: 'Examples')]
    #[OA\Get(summary: 'Отдать (inline) результат примера из S3 (публичный)')]
    #[OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'name', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Бинарный результат примера (inline)')]
    #[OA\Response(response: 404, description: 'Такого примера нет либо объект отсутствует')]
    public function serve(string $category, string $name): Response
    {
        // Whitelist: ключ строится из найденной строки, а не из ввода —
        // произвольный S3-ключ подсунуть нельзя.
        $example = $this->repository->findOneByCategoryAndFilename($category, $name);
        if ($example === null) {
            throw new NotFoundHttpException('Unknown example');
        }

        try {
            return $this->s3->inlineResponse(
                $this->s3->resultsBucket(),
                $example->getResultKey(),
                $example->getFilename(),
                $example->getMime(),
            );
        } catch (NoSuchKeyException) {
            throw new NotFoundHttpException('Example result not found');
        }
    }

    #[Route('/source/{category}/{name}', name: 'api_examples_source', methods: ['GET'], requirements: [
        'category' => '[a-z]+',
        'name'     => '[a-z0-9._-]+',
    ])]
    #[OA\Tag(name: 'Examples')]
    #[OA\Get(summary: 'Отдать (inline) исходный sample-файл примера из S3 (публичный)')]
    #[OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'name', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Бинарный исходник примера (inline)')]
    #[OA\Response(response: 404, description: 'Такого примера нет либо файл-исходник отсутствует')]
    public function source(string $category, string $name): Response
    {
        // Whitelist: ключ строится ТОЛЬКО из найденной строки (name сверяется с
        // Example::sourceFilename) — та же логика, что и у serve() для результата.
        $example = $this->repository->findOneByCategoryAndSourceFilename($category, $name);
        if ($example === null) {
            throw new NotFoundHttpException('Unknown example source');
        }

        try {
            return $this->s3->inlineResponse(
                $this->s3->resultsBucket(),
                $example->getSourceKey(),
                $example->getSourceFilename(),
                $example->getSourceMime(),
            );
        } catch (NoSuchKeyException) {
            throw new NotFoundHttpException('Example source file not found');
        }
    }

    private function fileUrl(Example $example): string
    {
        return $this->generateUrl('api_examples_file', [
            'category' => $example->getCategory(),
            'name'     => $example->getFilename(),
        ]);
    }

    private function sourceUrl(Example $example): string
    {
        return $this->generateUrl('api_examples_source', [
            'category' => $example->getCategory(),
            'name'     => $example->getSourceFilename(),
        ]);
    }
}

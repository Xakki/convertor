<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\ConversionStatus;
use App\Exception\AuthRequiredException;
use App\Exception\ConversionDisabledException;
use App\Repository\ConversionRepository;
use App\Service\Conversion\ConversionManager;
use App\Service\Conversion\ConversionRegistry;
use App\Service\Quota\QuotaService;
use App\Service\Storage\S3Storage;
use AsyncAws\S3\Exception\NoSuchKeyException;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1')]
class ConversionController extends AbstractController
{
    /** Потолок чтения результата для inline-превью (64 KiB) — не тянем весь объект. */
    private const PREVIEW_MAX_BYTES = 65536;

    /** Целевые форматы, чей результат отдаётся текстовым превью. */
    private const PREVIEWABLE_FORMATS = ['md', 'txt', 'json', 'csv', 'html'];

    /** MIME результата, пригодные для текстового превью (сверх любого `text/*`). */
    private const PREVIEWABLE_MIMES = ['application/json', 'text/csv', 'text/markdown', 'text/html', 'text/plain'];

    public function __construct(
        private readonly ConversionManager $conversionManager,
        private readonly ConversionRegistry $registry,
        private readonly ConversionRepository $conversionRepository,
        private readonly QuotaService $quotaService,
        private readonly S3Storage $s3,
        #[Autowire(service: 'limiter.anon_convert')]
        private readonly RateLimiterFactory $anonConvertLimiter,
        #[Autowire(service: 'limiter.anon_quota')]
        private readonly RateLimiterFactory $anonQuotaLimiter,
    ) {
    }

    #[Route('/convert', methods: ['POST'])]
    #[OA\Tag(name: 'Conversion')]
    #[OA\Post(
        summary: 'Поставить файл в очередь на конвертацию',
        description: 'Загружает файл (multipart/form-data) и создаёт задачу конвертации. Списывает квоту и ставит задачу в очередь.',
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['file', 'to_format'],
                properties: [
                    new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'Исходный файл'),
                    new OA\Property(property: 'to_format', type: 'string', example: 'pdf', description: 'Целевой формат'),
                    new OA\Property(property: 'ocr', type: 'boolean', default: false, description: 'Использовать OCR (для неоднозначных пар, напр. pdf→txt)'),
                ],
            ),
        ),
    )]
    #[OA\Response(
        response: 202,
        description: 'Задача принята в обработку',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'conversion_id', type: 'integer', example: 123),
            new OA\Property(property: 'status', type: 'string', example: 'pending'),
        ]),
    )]
    #[OA\Response(response: 400, description: 'Некорректный запрос (нет файла / to_format)')]
    #[OA\Response(response: 401, description: 'Требуется аутентификация')]
    #[OA\Response(response: 409, description: 'Конвертация отключена админом')]
    #[OA\Response(response: 413, description: 'Файл превышает лимит размера')]
    #[OA\Response(response: 415, description: 'Неподдерживаемый тип содержимого')]
    #[OA\Response(response: 422, description: 'Неподдерживаемая конвертация')]
    #[OA\Response(response: 429, description: 'Превышена квота / слишком много запросов')]
    public function convert(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $file     = $request->files->get('file');
        $toFormat = $request->request->get('to_format');
        $ocr      = $request->request->getBoolean('ocr');

        if ($file === null) {
            return $this->json(['error' => 'File required'], Response::HTTP_BAD_REQUEST);
        }

        if (! $toFormat) {
            return $this->json(['error' => 'to_format required'], Response::HTTP_BAD_REQUEST);
        }

        // ROLE_USER = полный логин; гость его не имеет (role_hierarchy даёт
        // залогиненному пройти guest-роуты, но НЕ наоборот).
        $privileged = $this->isGranted('ROLE_USER');

        // Rate-limit гостя по IP (залогиненные ограничены только квотой).
        if (! $privileged) {
            $limit = $this->anonConvertLimiter->create($request->getClientIp())->consume(1);
            if (! $limit->isAccepted()) {
                return $this->json(
                    ['error' => 'Too many anonymous conversions, please try later or log in'],
                    Response::HTTP_TOO_MANY_REQUESTS,
                );
            }
        }

        try {
            // createConversion now enqueues (dispatch) + charges quota internally,
            // so the whole charge→submit→enqueue path is atomic in one place.
            $conversion = $this->conversionManager->createConversion($user, $file, strtolower((string) $toFormat), $ocr, $privileged);

            return $this->json([
                'conversion_id' => $conversion->getId(),
                'status'        => $conversion->getStatus()->value,
            ], Response::HTTP_ACCEPTED);
        } catch (AuthRequiredException $e) {
            // Гейт ai/video для гостя.
            return $this->json(
                ['error' => 'auth_required', 'message' => $e->getMessage()],
                Response::HTTP_FORBIDDEN,
            );
        } catch (ConversionDisabledException $e) {
            // Пара отключена админом (валидна, но временно выключена) → 409.
            return $this->json(
                ['error' => 'conversion_disabled', 'message' => $e->getMessage()],
                Response::HTTP_CONFLICT,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (UnprocessableEntityHttpException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (TooManyRequestsHttpException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (HttpException $e) {
            // Size (413) + content-type (415) gates from ConversionManager. The
            // specific catches above already handled 422/429; this maps the
            // remaining HTTP exceptions to their own status code.
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }

    #[Route('/convert/{id}/status', methods: ['GET'])]
    #[OA\Tag(name: 'Conversion')]
    #[OA\Get(summary: 'Статус задачи конвертации')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID задачи', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Текущий статус',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'conversion_id', type: 'integer', example: 123),
            new OA\Property(property: 'status', type: 'string', example: 'processing'),
            new OA\Property(property: 'error', type: 'string', nullable: true),
        ]),
    )]
    #[OA\Response(response: 401, description: 'Требуется аутентификация')]
    #[OA\Response(response: 404, description: 'Задача не найдена')]
    public function status(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $result = $this->conversionManager->getStatus($id, $user);

            return $this->json([
                'conversion_id' => $result->conversionId,
                'status'        => $result->status->value,
                'error'         => $result->errorMessage,
            ]);
        } catch (\RuntimeException) {
            return $this->json(['error' => 'Conversion not found'], Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/convert/{id}/download', methods: ['GET'])]
    #[OA\Tag(name: 'Conversion')]
    #[OA\Get(summary: 'Скачать результат конвертации')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID задачи', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Бинарный файл результата',
        content: new OA\MediaType(
            mediaType: 'application/octet-stream',
            schema: new OA\Schema(type: 'string', format: 'binary'),
        ),
    )]
    #[OA\Response(response: 401, description: 'Требуется аутентификация')]
    #[OA\Response(response: 404, description: 'Задача или файл результата не найдены')]
    public function download(int $id, #[CurrentUser] ?User $user): Response
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $conversion = $this->conversionRepository->find($id);

        if ($conversion === null || $conversion->getUser()->getId() !== $user->getId()) {
            throw new NotFoundHttpException('Conversion not found');
        }

        $outputFile = $conversion->getOutputFile();
        if ($outputFile === null) {
            return $this->json(['error' => 'Output file not available'], Response::HTTP_NOT_FOUND);
        }

        // storagePath holds the S3 object key for results; bucket is config-derived.
        return $this->s3->downloadResponse(
            $outputFile->getStoragePath(),
            $outputFile->getOriginalName(),
            $outputFile->getMimeType(),
        );
    }

    #[Route('/convert/{id}/source', methods: ['GET'])]
    #[OA\Tag(name: 'Conversion')]
    #[OA\Get(summary: 'Скачать исходный (входной) файл конвертации')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID задачи', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Бинарный входной файл',
        content: new OA\MediaType(
            mediaType: 'application/octet-stream',
            schema: new OA\Schema(type: 'string', format: 'binary'),
        ),
    )]
    #[OA\Response(response: 401, description: 'Требуется аутентификация')]
    #[OA\Response(response: 404, description: 'Задача не найдена')]
    #[OA\Response(response: 410, description: 'Входной файл удалён по ретеншену')]
    public function source(int $id, #[CurrentUser] ?User $user): Response
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $conversion = $this->conversionRepository->find($id);

        // 404 (не 403) на чужую/несуществующую — не палим факт существования.
        if ($conversion === null || $conversion->getUser()->getId() !== $user->getId()) {
            throw new NotFoundHttpException('Conversion not found');
        }

        // inputFile — non-nullable relation (см. Conversion::$inputFile), поэтому
        // «relation null» недостижим по типам; ветку 410 держит NoSuchKey (вход
        // мог быть удалён FileCleanupService, а строка ещё живёт при сбое S3-delete).
        $inputFile = $conversion->getInputFile();

        try {
            return $this->s3->attachmentResponse(
                $this->s3->inputsBucket(),
                $inputFile->getStoragePath(),
                $inputFile->getOriginalName(),
                $inputFile->getMimeType(),
            );
        } catch (NoSuchKeyException) {
            return $this->json(
                ['error' => 'gone', 'message' => 'Input file expired or no longer available'],
                Response::HTTP_GONE,
            );
        }
    }

    #[Route('/convert/{id}/preview', methods: ['GET'])]
    #[OA\Tag(name: 'Conversion')]
    #[OA\Get(summary: 'Inline текстовое превью результата (первые 64 KiB)')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID задачи', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Текст результата (усечён до 64 KiB; заголовок X-Preview-Truncated при усечении)',
        content: new OA\MediaType(mediaType: 'text/plain', schema: new OA\Schema(type: 'string')),
    )]
    #[OA\Response(response: 401, description: 'Требуется аутентификация')]
    #[OA\Response(response: 404, description: 'Задача/результат не найдены')]
    #[OA\Response(response: 409, description: 'Конвертация ещё не завершена')]
    #[OA\Response(response: 410, description: 'Результат удалён по ретеншену')]
    #[OA\Response(response: 415, description: 'Результат не является текстом для превью')]
    public function preview(int $id, #[CurrentUser] ?User $user): Response
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $conversion = $this->conversionRepository->find($id);

        if ($conversion === null || $conversion->getUser()->getId() !== $user->getId()) {
            throw new NotFoundHttpException('Conversion not found');
        }

        if ($conversion->getStatus() !== ConversionStatus::Completed) {
            return $this->json(
                ['error' => 'not_ready', 'message' => 'Conversion is not completed'],
                Response::HTTP_CONFLICT,
            );
        }

        $outputFile = $conversion->getOutputFile();
        if ($outputFile === null) {
            return $this->json(['error' => 'Result not available'], Response::HTTP_NOT_FOUND);
        }

        if (! self::isPreviewable($outputFile, $conversion->getToFormat())) {
            return $this->json(
                ['error' => 'unsupported', 'message' => 'Result is not text-previewable'],
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            );
        }

        // Пустой (0-байт) результат: Range `bytes=0-…` на нём отдаёт 416 (не
        // NoSuchKey) → был бы 500. Отдаём пустое превью, не дёргая S3.
        if ($outputFile->getSizeBytes() === 0) {
            $bytes = '';
        } else {
            try {
                $bytes = $this->s3->readCapped(
                    $this->s3->resultsBucket(),
                    $outputFile->getStoragePath(),
                    self::PREVIEW_MAX_BYTES,
                );
            } catch (NoSuchKeyException) {
                return $this->json(
                    ['error' => 'gone', 'message' => 'Result expired or no longer available'],
                    Response::HTTP_GONE,
                );
            }
        }

        // Возвращаем как text/plain (НЕ text/html и НЕ исходный mime): байты — просто
        // текст, экранирование/рендер — на фронте. Content-Disposition inline.
        $response = new Response($bytes, Response::HTTP_OK, [
            'Content-Type'        => 'text/plain; charset=utf-8',
            'Content-Disposition' => S3Storage::contentDisposition(
                $outputFile->getOriginalName(),
                HeaderUtils::DISPOSITION_INLINE,
            ),
            // Не даём браузеру MIME-sniff'ить недоверенные байты результата в HTML (defense-in-depth на XSS).
            'X-Content-Type-Options' => 'nosniff',
        ]);

        if ($outputFile->getSizeBytes() > self::PREVIEW_MAX_BYTES) {
            $response->headers->set('X-Preview-Truncated', '1');
        }

        return $response;
    }

    #[Route('/convert/history', methods: ['GET'])]
    #[OA\Tag(name: 'Conversion')]
    #[OA\Get(summary: 'История конвертаций пользователя')]
    #[OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Кол-во записей (макс. 100)', schema: new OA\Schema(type: 'integer', default: 20))]
    #[OA\Parameter(name: 'offset', in: 'query', required: false, description: 'Смещение', schema: new OA\Schema(type: 'integer', default: 0))]
    #[OA\Response(
        response: 200,
        description: 'Список задач',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'from_format', type: 'string'),
                new OA\Property(property: 'to_format', type: 'string'),
                new OA\Property(property: 'status', type: 'string'),
                new OA\Property(property: 'is_ai', type: 'boolean'),
                new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                new OA\Property(property: 'processing_ms', type: 'integer', nullable: true),
                new OA\Property(property: 'source_name', type: 'string'),
                new OA\Property(property: 'source_size', type: 'integer'),
                new OA\Property(property: 'result_size', type: 'integer', nullable: true),
                new OA\Property(property: 'result_mime', type: 'string', nullable: true),
                new OA\Property(property: 'previewable', type: 'boolean'),
            ])),
        ]),
    )]
    #[OA\Response(response: 401, description: 'Требуется аутентификация')]
    public function history(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        // Транзиентный гость (ещё не конвертировал) не владеет ничем — пустая история
        // без запроса по null-id.
        if ($user->getId() === null) {
            return $this->json(['items' => []]);
        }

        $limit  = min((int) $request->query->get('limit', 20), 100);
        $offset = (int) $request->query->get('offset', 0);

        $conversions = $this->conversionRepository->findByUser($user, $limit, $offset);

        return $this->json([
            'items' => array_map(self::serializeHistoryItem(...), $conversions),
        ]);
    }

    /**
     * Сериализация строки истории. Связи input/output приходят fetch-join'ом
     * (ConversionRepository::findByUser) — доп. запросов на элемент нет.
     *
     * @return array<string, mixed>
     */
    private static function serializeHistoryItem(Conversion $c): array
    {
        $input  = $c->getInputFile();
        $output = $c->getOutputFile();

        return [
            'id'            => $c->getId(),
            'from_format'   => $c->getFromFormat(),
            'to_format'     => $c->getToFormat(),
            'status'        => $c->getStatus()->value,
            'is_ai'         => $c->isAi(),
            'created_at'    => $c->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'processing_ms' => $c->getProcessingMs(),
            'source_name'   => $input->getOriginalName(),
            'source_size'   => $input->getSizeBytes(),
            'result_size'   => $output?->getSizeBytes(),
            'result_mime'   => $output?->getMimeType(),
            'previewable'   => self::isPreviewable($output, $c->getToFormat()),
        ];
    }

    /**
     * Результат пригоден к текстовому превью, когда он ЕСТЬ и его mime/формат
     * текстовый: любой `text/*`, либо application/json, либо целевой формат из
     * {md,txt,json,csv,html}. Единый критерий для флага `previewable` в истории и
     * для 415-гейта в preview().
     */
    private static function isPreviewable(?FileStorage $output, string $toFormat): bool
    {
        if ($output === null) {
            return false;
        }

        // Отсекаем возможные параметры (`text/plain; charset=utf-8`).
        $mime = strtolower(trim(explode(';', $output->getMimeType())[0]));

        if (str_starts_with($mime, 'text/')) {
            return true;
        }

        if (in_array($mime, self::PREVIEWABLE_MIMES, true)) {
            return true;
        }

        return in_array(strtolower($toFormat), self::PREVIEWABLE_FORMATS, true);
    }

    #[Route('/formats', methods: ['GET'])]
    #[OA\Tag(name: 'Conversion')]
    #[OA\Get(summary: 'Список поддерживаемых конвертаций', security: [])]
    #[OA\Response(
        response: 200,
        description: 'Матрица поддерживаемых пар форматов',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'formats', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'from', type: 'string', example: 'docx'),
                new OA\Property(property: 'to', type: 'string', example: 'pdf'),
                new OA\Property(property: 'category', type: 'string', example: 'document'),
                new OA\Property(property: 'isAi', type: 'boolean'),
                new OA\Property(property: 'ocrCapable', type: 'boolean'),
            ])),
        ]),
    )]
    public function formats(): JsonResponse
    {
        return $this->json([
            'formats' => $this->registry->getSupportedFormats(),
        ]);
    }

    #[Route('/quota', methods: ['GET'])]
    #[OA\Tag(name: 'Conversion')]
    #[OA\Get(summary: 'Остаток квоты пользователя')]
    #[OA\Response(
        response: 200,
        description: 'Остаток дневной квоты',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'conversions', type: 'integer', description: '-1 = безлимит', example: 42),
            new OA\Property(property: 'ai_conversions', type: 'integer', description: '-1 = безлимит', example: 5),
            new OA\Property(property: 'plan', type: 'string', example: 'free'),
        ]),
    )]
    #[OA\Response(response: 401, description: 'Требуется аутентификация')]
    public function quota(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        // Ленивая guest-модель уже убрала рост `users` от флуда /quota; этот
        // лимитер — общая защита от request-флуда на дешёвом эндпоинте
        // (defense-in-depth), только для гостя (залогиненных не трогаем).
        if (! $this->isGranted('ROLE_USER')) {
            $limit = $this->anonQuotaLimiter->create($request->getClientIp())->consume(1);
            if (! $limit->isAccepted()) {
                return $this->json(
                    ['error' => 'Too many requests, please try later'],
                    Response::HTTP_TOO_MANY_REQUESTS,
                );
            }
        }

        $quota = $this->quotaService->getRemainingQuota($user);

        // Для гостя переопределяем: ai недоступен (0), план — "guest". Не полагаемся
        // на User.plan гостя (free-fallback дал бы ai_conversions:1).
        if (! $this->isGranted('ROLE_USER')) {
            $quota['ai_conversions'] = 0;
            $quota['plan']           = 'guest';
        }

        return $this->json($quota);
    }
}

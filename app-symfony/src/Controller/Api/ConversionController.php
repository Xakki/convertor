<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\ConversionRequestDTO;
use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\ConversionStatus;
use App\Exception\AuthRequiredException;
use App\Exception\ConversionDisabledException;
use App\Exception\InsufficientBalanceException;
use App\Exception\WorkerUnavailableException;
use App\Repository\ConversionRepository;
use App\Service\Conversion\ConversionManager;
use App\Service\Conversion\ConversionRegistry;
use App\Service\Quota\QuotaService;
use App\Service\RateLimit\ApiRateLimiter;
use App\Service\Storage\S3Storage;
use AsyncAws\S3\Exception\NoSuchKeyException;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
        private readonly ApiRateLimiter $apiRateLimiter,
    ) {
    }

    #[Route('/convert', methods: ['POST'])]
    #[OA\Tag(name: 'Conversion')]
    #[OA\Post(
        summary: 'Поставить файл ИЛИ текст в очередь на конвертацию',
        description: 'Принимает multipart/form-data с РОВНО ОДНИМ входом: либо `file` (загруженный файл), '
            . 'либо `text` + `source_format` (вставленный текст без файла — сервер материализует его во '
            . 'временный файл с расширением `source_format` и дальше ведёт по тому же пайплайну). '
            . 'Оба сразу или ни одного — 400. Для text-входа `source_format` обязателен и должен быть '
            . 'поддерживаемым текстовым источником реестра (не бинарный формат) — иначе 422.',
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['to_format'],
                properties: [
                    new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'Исходный файл (взаимоисключимо с `text`)'),
                    new OA\Property(property: 'text', type: 'string', description: 'Вставленный текст без файла (взаимоисключимо с `file`); требует `source_format`'),
                    new OA\Property(property: 'source_format', type: 'string', example: 'md', description: 'Формат исходного текста (обязателен вместе с `text`; текстовый источник реестра)'),
                    new OA\Property(property: 'to_format', type: 'string', example: 'pdf', description: 'Целевой формат'),
                    new OA\Property(property: 'ocr', type: 'boolean', default: false, description: 'Использовать OCR (только для file-входа; для неоднозначных пар, напр. pdf→txt)'),
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
    #[OA\Response(response: 400, description: 'Некорректный запрос: нет ни file, ни text; ОБА file и text сразу; нет to_format/source_format')]
    #[OA\Response(response: 401, description: 'Требуется аутентификация')]
    #[OA\Response(response: 409, description: 'Конвертация отключена админом')]
    #[OA\Response(response: 413, description: 'Файл/текст превышает лимит размера')]
    #[OA\Response(response: 415, description: 'Неподдерживаемый тип содержимого')]
    #[OA\Response(response: 422, description: 'Неподдерживаемая конвертация (в т.ч. бинарный source_format в text-режиме)')]
    #[OA\Response(response: 429, description: 'Превышена квота / слишком много запросов')]
    #[OA\Response(response: 503, description: 'Воркер такого типа никогда не регистрировался (конвертация временно недоступна)')]
    public function convert(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $file         = $request->files->get('file');
        $text         = $request->request->get('text');
        $sourceFormat = $request->request->get('source_format');
        $toFormat     = $request->request->get('to_format');
        $ocr          = $request->request->getBoolean('ocr');
        $options      = $request->request->all('options');

        $hasFile = $file !== null;
        // Пустой text (не отправлен ИЛИ пустая строка) неотличим от «нет text» —
        // AC требует того же 400 «neither», что и для полного отсутствия входа.
        $hasText = is_string($text) && $text !== '';

        if ($hasFile && $hasText) {
            return $this->json(['error' => 'Provide either file or text, not both'], Response::HTTP_BAD_REQUEST);
        }

        if (! $hasFile && ! $hasText) {
            return $this->json(['error' => 'Either file or text is required'], Response::HTTP_BAD_REQUEST);
        }
        if ($hasText && $options !== []) {
            return $this->json(['error' => 'Image options are only supported for file conversions'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $toFormat) {
            return $this->json(['error' => 'to_format required'], Response::HTTP_BAD_REQUEST);
        }

        $toFormatLower = strtolower((string) $toFormat);

        try {
            $options = $this->validateImageOptions($options, $toFormatLower);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Text-вход: source_format обязателен и валидируется КАК ТЕКСТОВЫЙ
        // источник реестра — ДО createConversion(), т.к. у пасченого текста
        // нет MIME-sniff подстраховки (unlike a real upload), поэтому бинарный
        // source_format (docx/pdf/картинки/аудио/видео) отклоняем здесь с 422,
        // отдельно от общего 400 «unsupported pair» файлового пути.
        $sourceFormatLower = null;
        if ($hasText) {
            if (! is_string($sourceFormat) || $sourceFormat === '') {
                return $this->json(['error' => 'source_format required for text input'], Response::HTTP_BAD_REQUEST);
            }

            $sourceFormatLower = strtolower($sourceFormat);

            if (! $this->registry->isTextSourceSupported($sourceFormatLower, $toFormatLower)) {
                return $this->json(
                    ['error' => "Unsupported text source_format: {$sourceFormatLower} → {$toFormatLower}"],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        // ROLE_USER = полный логин; гость его не имеет (role_hierarchy даёт
        // залогиненному пройти guest-роуты, но НЕ наоборот).
        $privileged = $this->isGranted('ROLE_USER');

        // Per-IP + per-user/guest rate limit (CNV-34). Гость: anon_*; ROLE_USER: user_*.
        $rateLimited = $this->apiRateLimiter->enforceConvert($request, $user, $privileged);
        if ($rateLimited !== null) {
            return $rateLimited;
        }

        // Text-вход материализуется во временный файл (fromText()) и идёт по
        // ТОЙ ЖЕ цепочке ConversionManager, что и файл — temp-файл подчищаем
        // в finally независимо от исхода (см. ConversionRequestDTO::cleanupTempFile()).
        if ($hasText) {
            $conversionRequest = ConversionRequestDTO::fromText($user, (string) $text, (string) $sourceFormatLower, $toFormatLower, $privileged);
        } elseif ($file !== null) {
            $conversionRequest = new ConversionRequestDTO($user, $file, $toFormatLower, $ocr, $privileged, $options);
        } else {
            // Недостижимо: hasFile/hasText провалидированы выше (ровно один
            // вход присутствует) — узкая ветка только чтобы PHPStan видел
            // $file как non-null в ConversionRequestDTO-конструкторе.
            throw new \LogicException('Unreachable: neither file nor text present');
        }

        try {
            // createConversion now enqueues (dispatch) + charges quota internally,
            // so the whole charge→submit→enqueue path is atomic in one place.
            $conversion = $this->conversionManager->createConversion($conversionRequest);

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
        } catch (WorkerUnavailableException $e) {
            // CNV-71-03: воркер такого типа никогда не регистрировался (нет
            // строки в worker_capabilities) → временная недоступность сервиса,
            // не ошибка клиента.
            return $this->json(
                ['error' => 'worker_unavailable', 'message' => $e->getMessage()],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (UnprocessableEntityHttpException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (TooManyRequestsHttpException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (InsufficientBalanceException) {
            return $this->json(['error' => 'insufficient_balance'], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (HttpException $e) {
            // Size (413) + content-type (415) gates from ConversionManager. The
            // specific catches above already handled 422/429; this maps the
            // remaining HTTP exceptions to their own status code.
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        } finally {
            $conversionRequest->cleanupTempFile();
        }
    }

    /**
     * @param mixed $options
     * @return array<string, int|string>
     */
    private function validateImageOptions(mixed $options, string $toFormat): array
    {
        if ($options === [] || $options === null) {
            return [];
        }
        if (! is_array($options)) {
            throw new \InvalidArgumentException('options must be an object');
        }

        $allowed = ['width', 'height'];
        if (in_array($toFormat, ['jpg', 'jpeg', 'webp'], true)) {
            $allowed[] = 'quality';
        }
        if (in_array($toFormat, ['jpg', 'jpeg'], true)) {
            $allowed[] = 'background';
        }
        foreach ($options as $key => $_) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException('Unsupported image option');
            }
        }

        $normalized = [];
        foreach (['width', 'height', 'quality'] as $key) {
            if (! array_key_exists($key, $options)) {
                continue;
            }
            $value = $options[$key];
            if (! is_int($value) && (! is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1)) {
                throw new \InvalidArgumentException("{$key} must be an integer");
            }
            $int     = (int) $value;
            $maximum = $key === 'quality' ? 100 : 10000;
            if ($int < 1 || $int > $maximum) {
                throw new \InvalidArgumentException("{$key} must be between 1 and {$maximum}");
            }
            $normalized[$key] = $int;
        }
        if (array_key_exists('background', $options)) {
            $background = $options['background'];
            if (! is_string($background) || preg_match('/^#[0-9a-fA-F]{6}$/', $background) !== 1) {
                throw new \InvalidArgumentException('background must be a #RRGGBB colour');
            }
            $normalized['background'] = strtoupper($background);
        }

        return $normalized;
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

            $payload = [
                'conversion_id' => $result->conversionId,
                'status'        => $result->status->value,
                'error'         => $result->errorMessage,
                'from_format'   => $result->fromFormat,
                'to_format'     => $result->toFormat,
            ];
            if ($result->chainId !== null) {
                $payload['chain_id']        = $result->chainId;
                $payload['sequence']        = $result->sequence;
                $payload['final_to_format'] = $result->finalToFormat;
                $payload['chain_length']    = $result->chainLength;
            }

            return $this->json($payload);
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

        try {
            $conversion = $this->conversionManager->resolveDownloadConversion($id, $user);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'Output file not available') {
                return $this->json(['error' => 'Output file not available'], Response::HTTP_NOT_FOUND);
            }

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
    #[OA\Get(summary: 'Inline текстовое превью результата или исходника (первые 64 KiB)')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID задачи', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'side', in: 'query', required: false, description: '`result` (по умолчанию) — превью результата; `source` — превью исходника', schema: new OA\Schema(type: 'string', default: 'result', enum: ['result', 'source']))]
    #[OA\Response(
        response: 200,
        description: 'Текст результата/исходника (усечён до 64 KiB; заголовок X-Preview-Truncated при усечении)',
        content: new OA\MediaType(mediaType: 'text/plain', schema: new OA\Schema(type: 'string')),
    )]
    #[OA\Response(response: 400, description: 'Некорректное значение `side`')]
    #[OA\Response(response: 401, description: 'Требуется аутентификация')]
    #[OA\Response(response: 404, description: 'Задача/результат не найдены')]
    #[OA\Response(response: 409, description: 'Конвертация ещё не завершена (только side=result)')]
    #[OA\Response(response: 410, description: 'Файл удалён по ретеншену')]
    #[OA\Response(response: 415, description: 'Файл не является текстом для превью')]
    public function preview(int $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $side = $request->query->get('side', 'result');
        if ($side !== 'result' && $side !== 'source') {
            return $this->json(
                ['error' => 'bad_request', 'message' => 'side must be "result" or "source"'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $conversion = $this->conversionRepository->find($id);

        if ($conversion === null || $conversion->getUser()->getId() !== $user->getId()) {
            throw new NotFoundHttpException('Conversion not found');
        }

        if ($side === 'source') {
            // Исходник существует с момента создания задачи — превью доступно
            // ДАЖЕ для pending/failed конверсии (в отличие от result-ветки ниже),
            // поэтому здесь намеренно НЕТ гейта по статусу.
            $inputFile = $conversion->getInputFile();

            if (! self::isPreviewable($inputFile, $conversion->getFromFormat())) {
                return $this->json(
                    ['error' => 'unsupported', 'message' => 'Source is not text-previewable'],
                    Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
                );
            }

            return $this->readPreviewResponse($inputFile, $this->s3->inputsBucket(), 'Source expired or no longer available');
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

        return $this->readPreviewResponse($outputFile, $this->s3->resultsBucket(), 'Result expired or no longer available');
    }

    /**
     * Общий хвост preview(): читает НЕ БОЛЕЕ PREVIEW_MAX_BYTES объекта из указанного
     * бакета и отдаёт его как text/plain inline. Используется ОБЕИМИ ветками
     * preview() (result и source) — единая логика 0-байтового short-circuit,
     * усечения и 410 на истёкший объект.
     */
    private function readPreviewResponse(FileStorage $file, string $bucket, string $goneMessage): Response
    {
        // Пустой (0-байт) файл: Range `bytes=0-…` на нём отдаёт 416 (не
        // NoSuchKey) → был бы 500. Отдаём пустое превью, не дёргая S3.
        if ($file->getSizeBytes() === 0) {
            $bytes = '';
        } else {
            try {
                $bytes = $this->s3->readCapped($bucket, $file->getStoragePath(), self::PREVIEW_MAX_BYTES);
            } catch (NoSuchKeyException) {
                return $this->json(['error' => 'gone', 'message' => $goneMessage], Response::HTTP_GONE);
            }
        }

        // Возвращаем как text/plain (НЕ text/html и НЕ исходный mime): байты — просто
        // текст, экранирование/рендер — на фронте. Content-Disposition inline.
        $response = new Response($bytes, Response::HTTP_OK, [
            'Content-Type'        => 'text/plain; charset=utf-8',
            'Content-Disposition' => S3Storage::contentDisposition(
                $file->getOriginalName(),
                HeaderUtils::DISPOSITION_INLINE,
            ),
            // Не даём браузеру MIME-sniff'ить недоверенные байты в HTML (defense-in-depth на XSS).
            'X-Content-Type-Options' => 'nosniff',
        ]);

        if ($file->getSizeBytes() > self::PREVIEW_MAX_BYTES) {
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
                new OA\Property(property: 'category', type: 'string', example: 'document'),
                new OA\Property(property: 'source_name', type: 'string'),
                new OA\Property(property: 'source_size', type: 'integer'),
                new OA\Property(property: 'source_mime', type: 'string', nullable: true),
                new OA\Property(property: 'source_previewable', type: 'boolean'),
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
     * Повтор конверсии (CNV-8): новая строка + копия исходника в S3 + квота.
     * Только ROLE_USER (firewall + IsGranted); гость → 403.
     */
    #[Route('/convert/{id}/retry', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Tag(name: 'Conversion')]
    #[OA\Post(summary: 'Повторить конверсию (новая задача из того же исходника)')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID исходной задачи', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 202,
        description: 'Новая задача принята',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'conversion_id', type: 'integer', example: 456),
            new OA\Property(property: 'status', type: 'string', example: 'pending'),
        ]),
    )]
    #[OA\Response(response: 401, description: 'Требуется аутентификация')]
    #[OA\Response(response: 403, description: 'Только ROLE_USER (гость недопущен)')]
    #[OA\Response(response: 404, description: 'Задача не найдена / чужая')]
    #[OA\Response(response: 409, description: 'Конвертация отключена админом')]
    #[OA\Response(response: 410, description: 'Исходник истёк в S3')]
    #[OA\Response(response: 429, description: 'Превышена квота')]
    #[OA\Response(response: 503, description: 'Воркер такого типа никогда не регистрировался')]
    public function retry(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $conversion = $this->conversionManager->retryConversion($id, $user);

            return $this->json([
                'conversion_id' => $conversion->getId(),
                'status'        => $conversion->getStatus()->value,
            ], Response::HTTP_ACCEPTED);
        } catch (ConversionDisabledException $e) {
            return $this->json(
                ['error' => 'conversion_disabled', 'message' => $e->getMessage()],
                Response::HTTP_CONFLICT,
            );
        } catch (WorkerUnavailableException $e) {
            // CNV-71-03: тот же гейт "воркер существует", что и в /convert (см.
            // catch выше в convert()) — теперь и у retry.
            return $this->json(
                ['error' => 'worker_unavailable', 'message' => $e->getMessage()],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        } catch (GoneHttpException $e) {
            return $this->json(
                ['error' => 'gone', 'message' => $e->getMessage()],
                Response::HTTP_GONE,
            );
        } catch (TooManyRequestsHttpException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (InsufficientBalanceException) {
            return $this->json(['error' => 'insufficient_balance'], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (\RuntimeException) {
            return $this->json(['error' => 'Conversion not found'], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Hard-delete конверсии (CNV-8): строка БД + объекты S3 (inputs/results).
     * Только ROLE_USER; гость → 403.
     */
    #[Route('/convert/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Tag(name: 'Conversion')]
    #[OA\Delete(summary: 'Удалить конверсию (hard delete + S3)')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID задачи', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 204, description: 'Удалено')]
    #[OA\Response(response: 401, description: 'Требуется аутентификация')]
    #[OA\Response(response: 403, description: 'Только ROLE_USER (гость недопущен)')]
    #[OA\Response(response: 404, description: 'Задача не найдена / чужая')]
    public function delete(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->conversionManager->deleteConversion($id, $user);

            return $this->json(null, Response::HTTP_NO_CONTENT);
        } catch (\RuntimeException) {
            return $this->json(['error' => 'Conversion not found'], Response::HTTP_NOT_FOUND);
        }
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
            'category'      => $c->getCategory()->value,
            'source_name'   => $input->getOriginalName(),
            'source_size'   => $input->getSizeBytes(),
            'source_mime'   => $input->getMimeType(),
            // inputFile — non-nullable relation (см. Conversion::$inputFile), поэтому
            // «исходник существует» верно всегда; критерий тут чисто format-based —
            // тот же isPreviewable(), что и в 415-гейте preview(side=source), и БЕЗ
            // требования к статусу (source-превью доступно и для pending/failed).
            'source_previewable' => self::isPreviewable($input, $c->getFromFormat()),
            'result_size'        => $output?->getSizeBytes(),
            'result_mime'        => $output?->getMimeType(),
            // previewable требует ЕЩЁ и status===completed: preview() отдаёт 409 для
            // незавершённой конвертации, даже если формат текстовый — флаг в истории
            // должен отражать это (hardening-09/nit-3), а не только format-критерий
            // isPreviewable() (тот же критерий используется для 415-гейта в preview(),
            // где completed уже проверен раньше отдельным условием — см. выше).
            'previewable' => $c->getStatus() === ConversionStatus::Completed
                && self::isPreviewable($output, $c->getToFormat()),
        ];
    }

    /**
     * Файл (результат ИЛИ исходник) ФОРМАТОМ пригоден к текстовому превью: любой
     * `text/*`, либо application/json, либо соответствующий формат (to_format для
     * результата, from_format для исходника) из {md,txt,json,csv,html}. Это только
     * format-критерий — НЕ включает статус конвертации. Используется:
     *  - в 415-гейте preview() для обеих сторон (side=result требует completed —
     *    проверено отдельным условием ДО вызова этого метода; side=source статус
     *    не проверяет вовсе);
     *  - в serializeHistoryItem() для флагов `previewable` (доп. AND со
     *    status===Completed на вызывающей стороне) и `source_previewable`
     *    (без доп. условия — как и side=source в preview()).
     */
    private static function isPreviewable(?FileStorage $file, string $format): bool
    {
        if ($file === null) {
            return false;
        }

        // Отсекаем возможные параметры (`text/plain; charset=utf-8`).
        $mime = strtolower(trim(explode(';', $file->getMimeType())[0]));

        if (str_starts_with($mime, 'text/')) {
            return true;
        }

        if (in_array($mime, self::PREVIEWABLE_MIMES, true)) {
            return true;
        }

        return in_array(strtolower($format), self::PREVIEWABLE_FORMATS, true);
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
    #[OA\Get(summary: 'Остаток квоты пользователя (4 тира × 2 окна)')]
    #[OA\Response(
        response: 200,
        description: 'Пер-тир daily+monthly used/limit/remaining + max_upload_bytes (CNV-30)',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'plan', type: 'string', example: 'free'),
            new OA\Property(
                property: 'tiers',
                type: 'object',
                description: 'Ключи: light, medium, heavy, ai',
                additionalProperties: new OA\AdditionalProperties(
                    properties: [
                        new OA\Property(
                            property: 'daily',
                            properties: [
                                new OA\Property(property: 'used', type: 'integer', example: 0),
                                new OA\Property(property: 'limit', type: 'integer', description: '-1 = безлимит', example: 3),
                                new OA\Property(property: 'remaining', type: 'integer', example: 3),
                            ],
                            type: 'object',
                        ),
                        new OA\Property(
                            property: 'monthly',
                            properties: [
                                new OA\Property(property: 'used', type: 'integer', example: 0),
                                new OA\Property(property: 'limit', type: 'integer', description: '-1 = безлимит', example: 30),
                                new OA\Property(property: 'remaining', type: 'integer', example: 30),
                            ],
                            type: 'object',
                        ),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Property(property: 'max_upload_bytes', type: 'integer', description: 'Макс. размер файла для загрузки (байт)', example: 52428800),
        ]),
    )]
    #[OA\Response(response: 401, description: 'Требуется аутентификация')]
    public function quota(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        // Per-IP + per-user/guest anti-burst (CNV-34); дневная квота — отдельно в QuotaService.
        $privileged  = $this->isGranted('ROLE_USER');
        $rateLimited = $this->apiRateLimiter->enforceQuota($request, $user, $privileged);
        if ($rateLimited !== null) {
            return $rateLimited;
        }

        $quota                     = $this->quotaService->getRemainingQuota($user);
        $quota['max_upload_bytes'] = $this->quotaService->maxUploadBytes($user);

        // Для гостя: ai/heavy недоступны (0/0), план — "guest".
        if (! $privileged) {
            $quota['plan'] = 'guest';
            foreach (['ai', 'heavy'] as $tierKey) {
                $quota['tiers'][$tierKey]['daily']['limit']       = 0;
                $quota['tiers'][$tierKey]['daily']['remaining']   = 0;
                $quota['tiers'][$tierKey]['monthly']['limit']     = 0;
                $quota['tiers'][$tierKey]['monthly']['remaining'] = 0;
            }
        }

        return $this->json($quota);
    }
}

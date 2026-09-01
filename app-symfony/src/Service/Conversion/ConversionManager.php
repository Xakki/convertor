<?php

declare(strict_types=1);

namespace App\Service\Conversion;

use App\DTO\ConversionRequestDTO;
use App\DTO\ConversionResultDTO;
use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use App\Enum\WorkerType;
use App\EventListener\ConversionChainListener;
use App\Exception\AuthRequiredException;
use App\Exception\ConversionDisabledException;
use App\Exception\InsufficientBalanceException;
use App\Exception\InvalidConversionOptionException;
use App\Exception\WorkerUnavailableException;
use App\Message\ConversionMessage;
use App\Repository\ConversionRepository;
use App\Repository\WorkerCapabilityRepository;
use App\Service\Conversion\Settings\ApiModelAvailability;
use App\Service\Conversion\Settings\ConversionOptionsValidator;
use App\Service\Conversion\Settings\SettingsAccessLevel;
use App\Service\Queue\ConversionStatusReader;
use App\Service\Quota\QuotaService;
use App\Service\Storage\S3Storage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

class ConversionManager
{
    public function __construct(
        private readonly ConversionRegistry $registry,
        private readonly ConversionRepository $conversionRepository,
        private readonly QuotaService $quotaService,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly ConversionStatusReader $statusReader,
        private readonly S3Storage $s3,
        // Required: post-flush createChain abort must always fail-propagate
        // sibling hops (no silent skip when optional null).
        private readonly ConversionChainFailPropagator $chainFailPropagator,
        // Опционален (как nullable-зависимости ConversionRegistry): в проде
        // autowiring инжектит сервис, unit-тесты без БД получают null →
        // toggle-проверка пропускается (поведение по умолчанию = всё включено).
        private readonly ?ConversionToggleService $toggleService = null,
        // В проде Symfony инжектит monolog; unit-тесты без логгера → null,
        // warning при сбое S3 просто не пишется (поведение delete не меняется).
        private readonly ?LoggerInterface $logger = null,
        // Allowlist финальных пар для chaining (CNV-5). null в unit-тестах =
        // пустой allowlist (цепочки выключены), как дефолт CHAIN_ENABLED_PAIRS.
        private readonly ?ChainEnablement $chainEnablement = null,
        // Durable worker-type admission. Short liveness changes do not globally
        // stop normal queues; API model admission is narrowed below.
        private readonly ?WorkerCapabilityRepository $workerCapabilities = null,
        // CNV-85 repair round: re-validates STORED options on retry against the
        // retrying user's CURRENT plan (see retryConversion()). Опционален, как
        // toggleService/workerCapabilities выше — в проде autowiring инжектит
        // реальный сервис; unit-тесты без него получают null → re-validation
        // пропускается (поведение по умолчанию до этой карточки = опции retry
        // не проверялись вовсе).
        private readonly ?ConversionOptionsValidator $optionsValidator = null,
        // API jobs дополнительно требуют доступную модель из свежей регистрации.
        private readonly ?ApiModelAvailability $apiModels = null,
    ) {
    }

    /**
     * @param ConversionRequestDTO $request входной контракт Controller → Manager;
     *                                      `$request->privileged` — есть ли у
     *                                      пользователя ROLE_USER (полный логин).
     *                                      Гость (false) не допускается к
     *                                      ai/video-парам.
     */
    public function createConversion(ConversionRequestDTO $request): Conversion
    {
        $user       = $request->user;
        $file       = $request->file;
        $toFormat   = $request->toFormat;
        $ocr        = $request->ocr;
        $privileged = $request->privileged;
        $options    = $request->options;

        $fromFormat = strtolower($file->getClientOriginalExtension());

        // Explicit OCR intent: single-hop only (OCR never via chain BFS).
        if ($ocr) {
            // CNV-85: у OCR-маршрута профиля настроек нет — все правила
            // `assignments` в config/catalog/conversion_settings.json объявлены
            // с `"ocr": false`, поэтому контроллер отвергает опции ещё до
            // Manager'а (422). Этот guard остаётся вторым рубежом; карточка,
            // которая заведёт OCR-профиль, снимает ИМЕННО его.
            if ($options !== []) {
                throw new \InvalidArgumentException('Conversion options are not supported for OCR conversions');
            }
            if (! $this->registry->isOcrSupported($fromFormat, $toFormat)) {
                throw new \InvalidArgumentException("Unsupported OCR conversion: {$fromFormat} → {$toFormat}");
            }

            return $this->createSingleHop(
                $user,
                $file,
                $fromFormat,
                $toFormat,
                FileCategory::Image,
                isAi: false,
                ocr: true,
                privileged: $privileged,
                options: $options,
            );
        }

        // Direct single-worker pair ALWAYS preferred over chaining.
        if ($this->registry->isSupported($fromFormat, $toFormat)) {
            // CNV-85: какие пары вообще имеют настраиваемые опции, решает
            // каталог профилей (config/catalog/conversion_settings.json), а не
            // хардкод «только image» — иначе доменные профили CNV-97/100/103
            // отвергались бы здесь. Значения уже нормализованы и whitelisted
            // валидатором на HTTP-границе.
            $category = $this->registry->getCategory($fromFormat, $toFormat);

            return $this->createSingleHop(
                $user,
                $file,
                $fromFormat,
                $toFormat,
                $category,
                $this->registry->isAi($fromFormat, $toFormat),
                ocr: false,
                privileged: $privileged,
                options: $options,
            );
        }

        // Цепочка (multi-hop): профиль настроек назначается ТОЧНОЙ паре
        // from→to одного воркера, у составного маршрута такой пары нет —
        // опции здесь не поддерживаются (CNV-85 не меняет это правило).
        if ($options !== []) {
            throw new \InvalidArgumentException('Conversion options are only supported for direct conversions');
        }

        $path = $this->registry->findPath($fromFormat, $toFormat);
        if ($path === null || count($path) < 2) {
            throw new \InvalidArgumentException("Unsupported conversion: {$fromFormat} → {$toFormat}");
        }

        // Enablement allowlist (final user-facing pair). Empty default → reject
        // with the same unsupported message (do not leak chain internals).
        $enabled = $this->chainEnablement?->isFinalPairEnabled($fromFormat, $toFormat) ?? false;
        if (! $enabled) {
            throw new \InvalidArgumentException("Unsupported conversion: {$fromFormat} → {$toFormat}");
        }

        return $this->createChain($user, $file, $fromFormat, $toFormat, $path, $privileged);
    }

    /**
     * Обычная одношаговая конверсия (OCR / прямая isSupported-пара).
     *
     * @param array<string, bool|int|string> $options
     */
    private function createSingleHop(
        User $user,
        UploadedFile $file,
        string $fromFormat,
        string $toFormat,
        FileCategory $category,
        bool $isAi,
        bool $ocr,
        bool $privileged,
        array $options = [],
    ): Conversion {
        // Toggle-гейт: пара, отключённая админом, режется ДО любых quota/S3-
        // эффектов и до постановки в очередь. Проверка по паре (from→to) —
        // независимо от ocr-флага (та же пара). Отсутствие ряда = включено.
        if ($this->toggleService !== null && ! $this->toggleService->isEnabled($fromFormat, $toFormat)) {
            throw new ConversionDisabledException('Конвертация временно отключена');
        }

        // Normal queues require durable type registration. API jobs instead
        // require a fresh alive registration with a validated model contract.
        $workerType = $this->registry->streamFor($fromFormat, $toFormat, $ocr);
        if (! $this->isWorkerTypeAdmitted($workerType)) {
            throw new WorkerUnavailableException('Конвертация временно недоступна');
        }

        // Гейт ai/video: пара, требующая полного логина (isAi ИЛИ category=Video),
        // недоступна гостю. Проверяем СРАЗУ после вычисления isAi/category и ДО
        // любых size/quota/S3-эффектов — 403 отдаётся дёшево и без сайд-эффектов.
        // (OCR-ветка форсит isAi=false/Image, поэтому под гейт не попадает.)
        if (! $privileged && ($isAi || $category === FileCategory::Video)) {
            throw new AuthRequiredException('Для ai/video конвертаций нужен вход.');
        }

        // Read metadata BEFORE the upload is consumed — keep size/mime from the
        // live tmp upload. getMimeType() sniffs the real bytes (finfo), NOT the
        // client-sent Content-Type header.
        $originalName = $file->getClientOriginalName() ?: 'upload';
        $mimeType     = $file->getMimeType() ?? 'application/octet-stream';
        $sizeBytes    = (int) $file->getSize();

        // Size and content-type gates, grouped BEFORE any quota/S3 side-effect:
        // both precede check()/charge()/storeInput().
        $this->assertWithinSizeLimit($user, $sizeBytes);
        $this->assertMimeAllowed($mimeType, $category, $ocr);

        $billingMode = $this->quotaService->check($user, $category, $isAi);

        $storagePath = $this->storeInput($file, $fromFormat, $mimeType);

        $inputFile = new FileStorage();
        $inputFile->setOriginalName($originalName);
        $inputFile->setStoragePath($storagePath);
        $inputFile->setMimeType($mimeType);
        $inputFile->setSizeBytes($sizeBytes);
        $inputFile->setExpiresAt(new \DateTimeImmutable('+48 hours'));

        $this->em->persist($inputFile);

        // Ленивая материализация гостя: строка в `users` создаётся ТОЛЬКО здесь,
        // когда конвертация прошла все гейты (ai/video, size, mime, quota) и вход
        // уже в S3. Транзиентный гость (GuestAuthenticator, no-cookie) до этого
        // момента не персистится — unauth-флуд /quota и отклонённые convert не
        // плодят guest-строк. Персист присваивает id → GuestCookieResponseListener
        // увидит id!==null и выставит cookie `guest_id`.
        if ($user->getId() === null) {
            $this->em->persist($user);
        }

        $conversion = new Conversion();
        $conversion->setUser($user);
        $conversion->setInputFile($inputFile);
        $conversion->setFromFormat($fromFormat);
        $conversion->setToFormat($toFormat);
        $conversion->setCategory($category);
        $conversion->setIsAi($isAi);
        $conversion->setIsOcr($ocr);
        $conversion->setOptions($options);
        $conversion->setBillingMode($billingMode);

        $this->em->persist($conversion);
        $this->em->flush();

        $this->chargePrepaidOrFail($conversion, $user, $category, $isAi, $billingMode);
        $this->dispatchOrRollbackPrepaid($conversion, $user, $category, $isAi, $billingMode);

        if ($billingMode === BillingMode::PlanQuota) {
            $this->quotaService->charge($user, $category, $isAi, $billingMode);
        }

        return $conversion;
    }

    /**
     * Multi-hop chain (CNV-5): materialize ALL hops, dispatch hop-1 only.
     *
     * @param list<array{from: string, to: string, category: FileCategory, isAi: bool}> $path
     */
    private function createChain(
        User $user,
        UploadedFile $file,
        string $fromFormat,
        string $toFormat,
        array $path,
        bool $privileged,
    ): Conversion {
        foreach ($path as $hop) {
            if ($this->toggleService !== null && ! $this->toggleService->isEnabled($hop['from'], $hop['to'])) {
                throw new ConversionDisabledException('Конвертация временно отключена');
            }
            // The same durable per-hop gate as createSingleHop(); an API hop is
            // the narrow live-model exception.
            $hopWorkerType = $this->registry->streamFor($hop['from'], $hop['to'], false);
            if (! $this->isWorkerTypeAdmitted($hopWorkerType)) {
                throw new WorkerUnavailableException('Конвертация временно недоступна');
            }
            if (! $privileged && ($hop['isAi'] || $hop['category'] === FileCategory::Video)) {
                throw new AuthRequiredException('Для ai/video конвертаций нужен вход.');
            }
        }

        $originalName = $file->getClientOriginalName() ?: 'upload';
        $mimeType     = $file->getMimeType() ?? 'application/octet-stream';
        $sizeBytes    = (int) $file->getSize();

        // Size/MIME gates use hop-1 category (uploaded bytes belong to source).
        $firstCategory = $path[0]['category'];
        $this->assertWithinSizeLimit($user, $sizeBytes);
        $this->assertMimeAllowed($mimeType, $firstCategory, false);

        $planHops = array_map(
            static fn (array $hop): array => ['category' => $hop['category'], 'isAi' => $hop['isAi']],
            $path,
        );
        $billingModes = $this->quotaService->checkPlan($user, $planHops);

        $storagePath = $this->storeInput($file, $fromFormat, $mimeType);

        $inputFile = new FileStorage();
        $inputFile->setOriginalName($originalName);
        $inputFile->setStoragePath($storagePath);
        $inputFile->setMimeType($mimeType);
        $inputFile->setSizeBytes($sizeBytes);
        $inputFile->setExpiresAt(new \DateTimeImmutable('+48 hours'));
        $this->em->persist($inputFile);

        if ($user->getId() === null) {
            $this->em->persist($user);
        }

        $chainId = $this->newChainId();
        /** @var list<Conversion> $conversions */
        $conversions = [];

        foreach ($path as $i => $hop) {
            $seq        = $i + 1;
            $conversion = new Conversion();
            $conversion->setUser($user);
            $conversion->setFromFormat($hop['from']);
            $conversion->setToFormat($hop['to']);
            $conversion->setCategory($hop['category']);
            $conversion->setIsAi($hop['isAi']);
            $conversion->setIsOcr(false);
            $conversion->setBillingMode($billingModes[$i]);
            $conversion->setChainId($chainId);
            $conversion->setSequence($seq);
            $conversion->setFinalToFormat($toFormat);

            if ($seq === 1) {
                $conversion->setInputFile($inputFile);
            } else {
                $placeholder = new FileStorage();
                $placeholder->setOriginalName('pending');
                $placeholder->setStoragePath(
                    ConversionChainListener::PENDING_INPUT_PREFIX . $chainId . '/' . $seq,
                );
                $placeholder->setMimeType('application/octet-stream');
                $placeholder->setSizeBytes(0);
                $placeholder->setExpiresAt(new \DateTimeImmutable('+48 hours'));
                $this->em->persist($placeholder);
                $conversion->setInputFile($placeholder);
            }

            $this->em->persist($conversion);
            $conversions[] = $conversion;
        }

        $this->em->flush();

        $hop1        = $conversions[0];
        $category    = $hop1->getCategory();
        $isAi        = $hop1->isAi();
        $billingMode = $hop1->getEffectiveBillingMode();

        try {
            $this->chargePrepaidOrFail($hop1, $user, $category, $isAi, $billingMode);
            $this->dispatchOrRollbackPrepaid($hop1, $user, $category, $isAi, $billingMode);
        } catch (\Throwable $e) {
            // PlanQuota dispatch abort leaves hop-1 Pending; Prepaid paths may
            // already have marked Failed. Always Fail + fail-propagate siblings.
            if ($hop1->getStatus() === ConversionStatus::Pending) {
                $hop1->setStatus(ConversionStatus::Failed);
                $this->em->flush();
            }
            $this->chainFailPropagator->failPropagateFrom($hop1);

            throw $e;
        }

        if ($billingMode === BillingMode::PlanQuota) {
            $this->quotaService->chargeHop($user, $category, $isAi, $billingMode, $hop1->getId());
        }

        return $hop1;
    }

    private function isWorkerTypeAdmitted(string $workerType): bool
    {
        if ($workerType === WorkerType::Api->value) {
            return $this->apiModels?->current() !== null;
        }

        return $this->workerCapabilities === null
            || $this->workerCapabilities->existsForWorkerType($workerType);
    }

    private function newChainId(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Повтор конверсии из кабинета: новая строка Conversion (не reuse), копия
     * исходника в S3 (независимый lifecycle от исходной строки), квота как у
     * обычного submit. Исходник уже в `-inputs` — клиент файл не грузит.
     *
     * Порядок гейтов до side-эффектов: owner → path-safe key → S3 exists (410) →
     * toggle → worker-availability (CNV-71-03) → quota.check; затем copy →
     * persist → dispatch → charge.
     *
     * @throws \RuntimeException     чужая / несуществующая (контроллер → 404)
     * @throws GoneHttpException     исходник истёк / вычищен (→ 410)
     * @throws ConversionDisabledException пара отключена админом (→ 409)
     * @throws WorkerUnavailableException  workerType не зарегистрирован; для API нет fresh alive model (→ 503)
     * @throws InvalidConversionOptionException сохранённая опция недоступна на ТЕКУЩЕМ
     *                                          плане пользователя (→ 422, см. re-validation ниже)
     */
    public function retryConversion(int $id, User $user): Conversion
    {
        $source = $this->requireOwnedConversion($id, $user);
        $input  = $source->getInputFile();
        $srcKey = $input->getStoragePath();

        $this->assertSafeObjectKey($srcKey, 'inputs/');

        if (! $this->s3->objectExists($this->s3->inputsBucket(), $srcKey)) {
            throw new GoneHttpException('Input file expired or no longer available');
        }

        $fromFormat = $source->getFromFormat();
        $toFormat   = $source->getToFormat();
        $isAi       = $source->isAi();
        $ocr        = $source->isOcr();
        $category   = $source->getCategory();

        // CNV-85 repair round: re-validate the STORED options through the same
        // validator/path POST /convert uses, against the retrying user's
        // CURRENT plan — not the plan at original creation time. A plan-gated
        // value from a higher plan the user no longer has (downgrade, or the
        // pair's profile changed/disappeared since) is rejected explicitly
        // (422 via InvalidConversionOptionException, e.g. `option_plan_required`)
        // — NEVER silently dropped to a default, which would replay a
        // different result with no signal to the user. $optionsValidator is
        // only null in unit tests that don't exercise options (same optional-
        // dependency convention as $toggleService/$workerCapabilities above);
        // the real service is always autowired in prod.
        $options = $source->getOptions();
        if ($this->optionsValidator !== null) {
            $options = $this->optionsValidator->validate(
                $fromFormat,
                $toFormat,
                $options,
                SettingsAccessLevel::fromPlanName($user->getPlan()),
                $ocr,
            );
        }

        if ($this->toggleService !== null && ! $this->toggleService->isEnabled($fromFormat, $toFormat)) {
            throw new ConversionDisabledException('Конвертация временно отключена');
        }

        // Same durable admission as createSingleHop()/createChain(); API retry
        // remains the live-model exception.
        $workerType = $this->registry->streamFor($fromFormat, $toFormat, $ocr);
        if (! $this->isWorkerTypeAdmitted($workerType)) {
            throw new WorkerUnavailableException('Конвертация временно недоступна');
        }

        $billingMode = $this->quotaService->check($user, $category, $isAi);

        // Серверная копия в новый ключ — delete одной строки не затронет другую.
        $dstKey = 'inputs/' . date('Y/m/d') . '/' . bin2hex(random_bytes(16)) . '.' . $fromFormat;
        $this->assertSafeObjectKey($dstKey, 'inputs/');
        $this->s3->copyObject(
            $this->s3->inputsBucket(),
            $srcKey,
            $this->s3->inputsBucket(),
            $dstKey,
            $input->getMimeType(),
        );

        $inputFile = new FileStorage();
        $inputFile->setOriginalName($input->getOriginalName());
        $inputFile->setStoragePath($dstKey);
        $inputFile->setMimeType($input->getMimeType());
        $inputFile->setSizeBytes($input->getSizeBytes());
        $inputFile->setExpiresAt(new \DateTimeImmutable('+48 hours'));

        $conversion = new Conversion();
        $conversion->setUser($user);
        $conversion->setInputFile($inputFile);
        $conversion->setFromFormat($fromFormat);
        $conversion->setToFormat($toFormat);
        $conversion->setCategory($category);
        $conversion->setIsAi($isAi);
        $conversion->setIsOcr($ocr);
        $conversion->setOptions($options);
        $conversion->setBillingMode($billingMode);

        $this->em->persist($inputFile);
        $this->em->persist($conversion);
        $this->em->flush();

        $this->chargePrepaidOrFail($conversion, $user, $category, $isAi, $billingMode);
        $this->dispatchOrRollbackPrepaid($conversion, $user, $category, $isAi, $billingMode);

        if ($billingMode === BillingMode::PlanQuota) {
            $this->quotaService->charge($user, $category, $isAi, $billingMode);
        }

        return $conversion;
    }

    /**
     * Prepaid debit ДО dispatch — иначе race на charge после enqueue оставит
     * принятую задачу без оплаты. При InsufficientBalanceException после flush
     * строка помечается Failed (не orphan Pending без dispatch).
     */
    private function chargePrepaidOrFail(
        Conversion $conversion,
        User $user,
        FileCategory $category,
        bool $isAi,
        BillingMode $billingMode,
    ): void {
        if ($billingMode !== BillingMode::PrepaidBalance) {
            return;
        }

        try {
            $this->quotaService->charge($user, $category, $isAi, $billingMode, $conversion->getId());
        } catch (InsufficientBalanceException $e) {
            $conversion->setStatus(ConversionStatus::Failed);
            $conversion->setErrorMessage('insufficient_balance');
            $this->em->flush();

            throw $e;
        }
    }

    /**
     * Plan-quota: charge ПОСЛЕ dispatch (increment только после enqueue).
     * Prepaid: при сбое dispatch — Failed + refund (симметрия DlqController::requeue).
     */
    private function dispatchOrRollbackPrepaid(
        Conversion $conversion,
        User $user,
        FileCategory $category,
        bool $isAi,
        BillingMode $billingMode,
    ): void {
        try {
            $this->dispatch($conversion);
        } catch (\Throwable $e) {
            if ($billingMode === BillingMode::PrepaidBalance) {
                $this->em->wrapInTransaction(function () use ($conversion, $user, $category, $isAi, $billingMode): void {
                    $conversion->setStatus(ConversionStatus::Failed);
                    $this->quotaService->refund(
                        $user,
                        $category,
                        $isAi,
                        $billingMode,
                        $conversion->getId(),
                    );
                });
            }

            throw $e;
        }
    }

    /**
     * Hard-delete конверсии владельца: S3 input (+ result, если есть) и строки
     * Conversion + FileStorage. Не soft-delete. Чужая/несуществующая →
     * RuntimeException (контроллер мапит в 404, не палим факт существования).
     *
     * Ключи валидируются (префикс inputs/|results/, без `..`) до обращения к S3.
     * Сбой/отсутствие объекта в S3 не блокирует вычистку БД (как FileCleanupService).
     */
    public function deleteConversion(int $id, User $user): void
    {
        $conversion = $this->requireOwnedConversion($id, $user);
        $inputFile  = $conversion->getInputFile();
        $outputFile = $conversion->getOutputFile();

        $conversionId = $conversion->getId();
        $inputKey     = $inputFile->getStoragePath();
        $this->assertSafeObjectKey($inputKey, 'inputs/');
        $this->deleteObjectQuietly($this->s3->inputsBucket(), $inputKey, $conversionId);

        if ($outputFile !== null) {
            $outputKey = $outputFile->getStoragePath();
            $this->assertSafeObjectKey($outputKey, 'results/');
            $this->deleteObjectQuietly($this->s3->resultsBucket(), $outputKey, $conversionId);
        }

        // Conversion — первым (FK на FileStorage), затем сами FileStorage.
        $this->em->remove($conversion);
        $this->em->remove($inputFile);
        if ($outputFile !== null) {
            $this->em->remove($outputFile);
        }
        $this->em->flush();
    }

    /**
     * Owner-scope загрузка: чужая / несуществующая → RuntimeException (единый
     * сигнал для контроллера → 404).
     */
    private function requireOwnedConversion(int $id, User $user): Conversion
    {
        $conversion = $this->conversionRepository->find($id);

        if ($conversion === null || $conversion->getUser()->getId() !== $user->getId()) {
            throw new \RuntimeException('Conversion not found');
        }

        return $conversion;
    }

    /**
     * Path-traversal защита для ключей из БД перед S3. Разрешены только ключи
     * с ожидаемым префиксом (`inputs/` / `results/`), без `..`, `\0`, `\`,
     * ведущего `/`. Имена объектов генерируем сами (uuid.ext) — пользовательский
     * filename в ключ никогда не попадает.
     *
     * Public for chain advance ({@see \App\EventListener\ConversionChainListener}).
     */
    public function assertSafeObjectKey(string $key, string $expectedPrefix): void
    {
        if (
            $key === ''
            || ! str_starts_with($key, $expectedPrefix)
            || str_contains($key, '..')
            || str_contains($key, "\0")
            || str_contains($key, '\\')
            || str_starts_with($key, '/')
        ) {
            throw new \RuntimeException('Invalid storage path');
        }
    }

    /**
     * Идемпотентное удаление S3-объекта: любой сбой глотаем — строка БД всё
     * равно вычищается (см. FileCleanupService::deleteObject). Сбой логируем
     * warning с bucket/key/conversionId, чтобы orphan-объекты были видны.
     */
    private function deleteObjectQuietly(string $bucket, string $key, int $conversionId): void
    {
        try {
            $this->s3->deleteObject($bucket, $key);
        } catch (\Throwable $e) {
            $this->logger?->warning('Не удалось удалить S3-объект при hard-delete конверсии; строка БД будет удалена', [
                'bucket'       => $bucket,
                'key'          => $key,
                'conversionId' => $conversionId,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Per-plan upload size gate (HTTP 413). Cheap getSize() check kept ahead of
     * the byte-sniffing MIME check and all quota/S3 work.
     */
    private function assertWithinSizeLimit(User $user, int $sizeBytes): void
    {
        $maxBytes = $this->quotaService->maxUploadBytes($user);

        if ($sizeBytes > $maxBytes) {
            $maxMb = intdiv($maxBytes, 1024 * 1024);

            throw new HttpException(413, "File exceeds the {$maxMb} MB upload limit for your plan.");
        }
    }

    /**
     * Category-level MIME gate (HTTP 415). Verifies the finfo-sniffed type
     * against the source category's allowed family prefixes — e.g. a .png whose
     * bytes are a PHP script sniffs as text/x-php ∉ image/* and is rejected. A
     * text file that is technically a script is fine for text/document
     * categories: it is stored & fed to a converter, never executed.
     *
     * OCR forces category=Image but its source set includes pdf, so the OCR
     * branch also accepts application/* (still rejects text/x-php scripts).
     */
    private function assertMimeAllowed(string $mimeType, FileCategory $category, bool $ocr): void
    {
        // Category-level allowlist (NOT exact-per-format): zip-based docx/odt/epub
        // all sniff as application/zip and every text/data/markup format sniffs as
        // text/plain, so an exact map would over-reject valid uploads. `audio` also
        // allows video/* because the audio worker owns video→audio extraction
        // (e.g. mp4→mp3 feeds a video/* file into an Audio-category conversion).
        $prefixes = $ocr
            ? ['image/', 'application/']
            : match ($category) {
                FileCategory::Image                      => ['image/'],
                FileCategory::Audio                      => ['audio/', 'video/'],
                FileCategory::Video                      => ['video/'],
                FileCategory::Document                   => ['application/', 'text/'],
                FileCategory::Markup, FileCategory::Data => ['text/', 'application/'],
                FileCategory::Archive                    => ['application/'],
            };

        foreach ($prefixes as $prefix) {
            if (str_starts_with($mimeType, $prefix)) {
                return;
            }
        }

        throw new UnsupportedMediaTypeHttpException(
            "File content type \"{$mimeType}\" is not allowed for a {$category->value} conversion.",
        );
    }

    public function dispatch(Conversion $conversion): void
    {
        $sourceFormat = $conversion->getFromFormat();
        $key          = $this->routingKey($conversion);

        $this->bus->dispatch(
            new ConversionMessage(
                conversionId: $conversion->getId(),
                inputBucket: $this->s3->inputsBucket(),
                inputKey: $conversion->getInputFile()->getStoragePath(),
                originalFilename: $conversion->getInputFile()->getOriginalName(),
                sourceFormat: $sourceFormat,
                targetFormat: $conversion->getToFormat(),
                category: $conversion->getCategory()->value,
                isAi: $conversion->isAi(),
                options: $conversion->getOptions(),
                attempt: (string) $conversion->getAttempt(),
            ),
            [new TransportNamesStamp(['conv_' . $key])],
        );
    }

    /**
     * Routing key = stream suffix. Delegates to the pure
     * {@see ConversionRegistry::streamFor()}; OCR jobs are forced to the image
     * stream via the persisted {@see Conversion::isOcr()} flag.
     */
    private function routingKey(Conversion $conversion): string
    {
        return $this->registry->streamFor(
            $conversion->getFromFormat(),
            $conversion->getToFormat(),
            $conversion->isOcr(),
        );
    }

    public function getStatus(int $id, User $user): ConversionResultDTO
    {
        $conversion = $this->conversionRepository->find($id);

        if ($conversion === null || $conversion->getUser()->getId() !== $user->getId()) {
            throw new \RuntimeException('Conversion not found');
        }

        $chainId     = $conversion->getChainId();
        $sequence    = $conversion->getSequence();
        $finalTo     = $conversion->getFinalToFormat();
        $chainLength = null;
        if ($chainId !== null) {
            $chainLength = count($this->conversionRepository->findByChainIdOrdered($chainId));
        }

        // Live status from Redis hash `conv:status:{id}` (TTL 24h). Falls back to
        // the MariaDB row once the hash has expired. Contract §4.
        $live = $this->statusReader->read($id);
        if ($live !== null) {
            $state = isset($live['state']) ? ConversionStatus::tryFrom($live['state']) : null;

            return new ConversionResultDTO(
                conversionId: $conversion->getId(),
                status: $state                 ?? $conversion->getStatus(),
                outputPath: $live['outputUrl'] ?? $live['outputKey'] ?? $conversion->getOutputFile()?->getStoragePath(),
                errorMessage: ($live['error'] ?? '') !== '' ? $live['error'] : $conversion->getErrorMessage(),
                chainId: $chainId,
                sequence: $sequence,
                finalToFormat: $finalTo,
                chainLength: $chainLength,
                fromFormat: $conversion->getFromFormat(),
                toFormat: $conversion->getToFormat(),
            );
        }

        return new ConversionResultDTO(
            conversionId: $conversion->getId(),
            status: $conversion->getStatus(),
            outputPath: $conversion->getOutputFile()?->getStoragePath(),
            errorMessage: $conversion->getErrorMessage(),
            chainId: $chainId,
            sequence: $sequence,
            finalToFormat: $finalTo,
            chainLength: $chainLength,
            fromFormat: $conversion->getFromFormat(),
            toFormat: $conversion->getToFormat(),
        );
    }

    /**
     * Resolve the conversion whose output may be downloaded for the given id.
     * Single-hop: the row itself. Chain: the final hop only when Completed.
     *
     * @throws \RuntimeException not found / not owned
     */
    public function resolveDownloadConversion(int $id, User $user): Conversion
    {
        $conversion = $this->conversionRepository->find($id);

        if ($conversion === null || $conversion->getUser()->getId() !== $user->getId()) {
            throw new \RuntimeException('Conversion not found');
        }

        $chainId = $conversion->getChainId();
        if ($chainId === null) {
            return $conversion;
        }

        $hops = $this->conversionRepository->findByChainIdOrdered($chainId);
        if ($hops === []) {
            return $conversion;
        }

        $final = $hops[array_key_last($hops)];
        if ($final->getStatus() !== ConversionStatus::Completed || $final->getOutputFile() === null) {
            throw new \RuntimeException('Output file not available');
        }

        return $final;
    }

    /**
     * Upload the validated input to the S3 inputs bucket and return the object
     * key. Key layout mirrors the results layout: `inputs/{Y}/{m}/{d}/{uuid}.{ext}`
     * with a random, path-traversal-safe basename (never the user filename).
     * `$ext` is the already-validated source extension; `$mimeType` is stored as
     * the object Content-Type. Nothing is written to /shared-files.
     */
    private function storeInput(UploadedFile $file, string $ext, string $mimeType): string
    {
        $key = 'inputs/' . date('Y/m/d') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;

        $stream = fopen($file->getPathname(), 'r');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open uploaded file for reading');
        }

        try {
            $this->s3->putObject($this->s3->inputsBucket(), $key, $stream, $mimeType);
        } finally {
            fclose($stream);
        }

        return $key;
    }
}

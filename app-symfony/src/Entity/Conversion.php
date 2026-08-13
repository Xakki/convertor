<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BillingMode;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use App\Repository\ConversionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ConversionRepository::class)]
#[ORM\Table(name: 'conversions')]
#[ORM\Index(name: 'IDX_CONVERSIONS_USER_ID', columns: ['user_id'])]
#[ORM\Index(name: 'IDX_CONVERSIONS_CREATED_AT', columns: ['created_at'])]
#[ORM\Index(name: 'FK_CONVERSIONS_INPUT', columns: ['input_file_id'])]
#[ORM\Index(name: 'FK_CONVERSIONS_OUTPUT', columns: ['output_file_id'])]
#[ORM\Index(name: 'IDX_CONVERSIONS_STATUS_UPDATED_AT', columns: ['status', 'updated_at'])]
#[ORM\Index(name: 'IDX_CONVERSIONS_STATUS_CREATED_AT', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'IDX_CONVERSIONS_CHAIN_ID_SEQUENCE', columns: ['chain_id', 'sequence'])]
#[ORM\HasLifecycleCallbacks]
class Conversion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\ManyToOne(targetEntity: FileStorage::class)]
    #[ORM\JoinColumn(nullable: false)]
    private FileStorage $inputFile;

    #[ORM\ManyToOne(targetEntity: FileStorage::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?FileStorage $outputFile = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $fromFormat;

    #[ORM\Column(type: 'string', length: 20)]
    private string $toFormat;

    #[ORM\Column(type: 'string', length: 20, enumType: FileCategory::class)]
    private FileCategory $category;

    #[ORM\Column(type: 'string', length: 20, enumType: ConversionStatus::class)]
    private ConversionStatus $status = ConversionStatus::Pending;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $processingMs = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isAi = false;

    #[ORM\Column(type: 'boolean')]
    private bool $isOcr = false;

    /** @var array<string, int|string> Параметры результата, применяемые воркером. */
    #[ORM\Column(type: 'json')]
    private array $options = [];

    /**
     * Attempt/generation-маркер (requeue-attempt-generation-marker). 0 = первичный
     * сабмит, инкрементится оператором на каждый {@see \App\Controller\Admin\Api\DlqController::requeue()}.
     * Протягивается в job (см. {@see \App\Service\Conversion\ConversionManager::dispatch()})
     * и обратно в dlq-fail — {@see \App\Service\Queue\ConversionResultPersister::persist()}
     * сверяет его со свежим значением на строке, чтобы stale-финализация устаревшей
     * попытки не убила свежий requeue (double-refund / потерянный результат).
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $attempt = 0;

    /**
     * Режим биллинга конверсии (CNV-28). null у legacy-строк → трактуется как plan_quota.
     */
    #[ORM\Column(type: 'string', length: 20, enumType: BillingMode::class, nullable: true)]
    private ?BillingMode $billingMode = null;

    /**
     * Идентификатор цепочки хопов (CNV-5). null = одиночная конверсия (не цепочка).
     * UUID-строка; общий для всех hop-строк одной submit-цепочки.
     */
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $chainId = null;

    /**
     * Порядковый номер hop внутри цепочки (1-based; задаёт Manager при submit).
     * null вне цепочки.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $sequence = null;

    /**
     * Пользовательский финальный target всей цепочки (A→…→final).
     * У одиночной конверсии = null; у hop'ов цепочки дублируется на каждой строке.
     */
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $finalToFormat = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getInputFile(): FileStorage
    {
        return $this->inputFile;
    }

    public function setInputFile(FileStorage $inputFile): self
    {
        $this->inputFile = $inputFile;

        return $this;
    }

    public function getOutputFile(): ?FileStorage
    {
        return $this->outputFile;
    }

    public function setOutputFile(?FileStorage $outputFile): self
    {
        $this->outputFile = $outputFile;

        return $this;
    }

    public function getFromFormat(): string
    {
        return $this->fromFormat;
    }

    public function setFromFormat(string $fromFormat): self
    {
        $this->fromFormat = $fromFormat;

        return $this;
    }

    public function getToFormat(): string
    {
        return $this->toFormat;
    }

    public function setToFormat(string $toFormat): self
    {
        $this->toFormat = $toFormat;

        return $this;
    }

    public function getCategory(): FileCategory
    {
        return $this->category;
    }

    public function setCategory(FileCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getStatus(): ConversionStatus
    {
        return $this->status;
    }

    public function setStatus(ConversionStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getProcessingMs(): ?int
    {
        return $this->processingMs;
    }

    public function setProcessingMs(?int $processingMs): self
    {
        $this->processingMs = $processingMs;

        return $this;
    }

    public function isAi(): bool
    {
        return $this->isAi;
    }

    public function setIsAi(bool $isAi): self
    {
        $this->isAi = $isAi;

        return $this;
    }

    public function isOcr(): bool
    {
        return $this->isOcr;
    }

    public function setIsOcr(bool $isOcr): self
    {
        $this->isOcr = $isOcr;

        return $this;
    }

    /** @return array<string, int|string> */
    public function getOptions(): array
    {
        return $this->options;
    }

    /** @param array<string, int|string> $options */
    public function setOptions(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function setAttempt(int $attempt): self
    {
        $this->attempt = $attempt;

        return $this;
    }

    /**
     * Бампает generation-маркер на новую попытку (см. класс-докблок поля).
     * Единственный вызывающий сейчас — оператор-requeue.
     */
    public function incrementAttempt(): self
    {
        ++$this->attempt;

        return $this;
    }

    public function getBillingMode(): ?BillingMode
    {
        return $this->billingMode;
    }

    /**
     * Эффективный режим биллинга: null → plan_quota для старых строк.
     */
    public function getEffectiveBillingMode(): BillingMode
    {
        return $this->billingMode ?? BillingMode::PlanQuota;
    }

    public function setBillingMode(?BillingMode $billingMode): self
    {
        $this->billingMode = $billingMode;

        return $this;
    }

    public function getChainId(): ?string
    {
        return $this->chainId;
    }

    public function setChainId(?string $chainId): self
    {
        $this->chainId = $chainId;

        return $this;
    }

    public function getSequence(): ?int
    {
        return $this->sequence;
    }

    public function setSequence(?int $sequence): self
    {
        $this->sequence = $sequence;

        return $this;
    }

    public function getFinalToFormat(): ?string
    {
        return $this->finalToFormat;
    }

    public function setFinalToFormat(?string $finalToFormat): self
    {
        $this->finalToFormat = $finalToFormat;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

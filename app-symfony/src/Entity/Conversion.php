<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use App\Repository\ConversionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ConversionRepository::class)]
#[ORM\Table(name: 'conversions')]
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExampleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Админ-управляемый «живой пример» конвертации для лендинга (карточка
 * admin-managed-examples). Заменяет захардкоженный {@see \App\Service\Examples\ExampleCatalog}
 * как ИСТОЧНИК ДАННЫХ для публичной витрины ({@see \App\Controller\Api\ExampleController}) —
 * каталог остаётся только seed-источником для {@see \App\Command\SeedExamplesCommand}.
 *
 * Результат и исходник — СЕРВЕРНЫЕ КОПИИ (S3 `copyObject`/`putObject`) в
 * стабильном префиксе `examples/<category>/…` бакета результатов, БЕЗ строки
 * {@see FileStorage} — 24-часовая очистка ({@see \App\Service\Storage\FileCleanupService})
 * и `app:clean-test-data` их не трогают (выборка идёт только по `file_storage`).
 *
 * `conversion` — необязательная ссылка на конвертацию-источник (для промо из
 * админки, только для трассировки/аудита). `ON DELETE SET NULL`: `clean-test-data`
 * безусловно вайпает ВСЕ `conversions`, а Example-строка (untracked-копия) должна
 * пережить этот вайп — см. класс-докблок {@see \App\Command\CleanTestDataCommand}.
 */
#[ORM\Entity(repositoryClass: ExampleRepository::class)]
#[ORM\Table(name: 'examples')]
class Example
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    /** Курируемая метка раздела витрины (совпадает с {@see \App\Enum\FileCategory} для промо, может отличаться для seed). */
    #[ORM\Column(type: 'string', length: 20)]
    private string $category;

    #[ORM\Column(type: 'string', length: 20)]
    private string $fromFormat;

    #[ORM\Column(type: 'string', length: 20)]
    private string $toFormat;

    /** Имя результирующего объекта (часть URL `/api/v1/examples/file/{category}/{filename}`). */
    #[ORM\Column(type: 'string', length: 255)]
    private string $filename;

    #[ORM\Column(type: 'string', length: 127)]
    private string $mime;

    #[ORM\Column(type: 'bigint')]
    private int $size;

    /** Пригоден ли результат для текстового inline-превью (md/txt/json/csv/html). */
    #[ORM\Column(type: 'boolean')]
    private bool $previewable;

    #[ORM\Column(type: 'string', length: 20)]
    private string $sourceFormat;

    #[ORM\Column(type: 'string', length: 127)]
    private string $sourceMime;

    /** Имя объекта исходника (часть URL `/api/v1/examples/source/{category}/{sourceFilename}`). */
    #[ORM\Column(type: 'string', length: 255)]
    private string $sourceFilename;

    /** Полный S3-ключ результата в бакете результатов (`examples/<category>/…`). */
    #[ORM\Column(type: 'string', length: 500)]
    private string $resultKey;

    /** Полный S3-ключ исходника в бакете результатов (`examples/<category>/…`). */
    #[ORM\Column(type: 'string', length: 500)]
    private string $sourceKey;

    /** Порядок отображения на витрине (меньше — раньше); при равенстве — по id. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: Conversion::class)]
    #[ORM\JoinColumn(name: 'conversion_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Conversion $conversion = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        $this->category = $category;

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

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    public function getMime(): string
    {
        return $this->mime;
    }

    public function setMime(string $mime): self
    {
        $this->mime = $mime;

        return $this;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function isPreviewable(): bool
    {
        return $this->previewable;
    }

    public function setPreviewable(bool $previewable): self
    {
        $this->previewable = $previewable;

        return $this;
    }

    public function getSourceFormat(): string
    {
        return $this->sourceFormat;
    }

    public function setSourceFormat(string $sourceFormat): self
    {
        $this->sourceFormat = $sourceFormat;

        return $this;
    }

    public function getSourceMime(): string
    {
        return $this->sourceMime;
    }

    public function setSourceMime(string $sourceMime): self
    {
        $this->sourceMime = $sourceMime;

        return $this;
    }

    public function getSourceFilename(): string
    {
        return $this->sourceFilename;
    }

    public function setSourceFilename(string $sourceFilename): self
    {
        $this->sourceFilename = $sourceFilename;

        return $this;
    }

    public function getResultKey(): string
    {
        return $this->resultKey;
    }

    public function setResultKey(string $resultKey): self
    {
        $this->resultKey = $resultKey;

        return $this;
    }

    public function getSourceKey(): string
    {
        return $this->sourceKey;
    }

    public function setSourceKey(string $sourceKey): self
    {
        $this->sourceKey = $sourceKey;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getConversion(): ?Conversion
    {
        return $this->conversion;
    }

    public function setConversion(?Conversion $conversion): self
    {
        $this->conversion = $conversion;

        return $this;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConversionToggleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Персистентный флаг вкл/выкл одной конвертации (пара from→to).
 *
 * Гранулярность = пара (fromFormat, toFormat): именно так реестр
 * {@see \App\Service\Conversion\ConversionRegistry} выбирает конвертор
 * (isSupported / getCategory / isAi). Один ряд на пару, уникальный ключ.
 *
 * Отсутствие ряда = конвертация включена (пустая таблица ничего не меняет).
 * Ряд хранится только для явно переключённых пар; enabled=false = отключена.
 * Проверка идёт в ConversionManager ДО постановки в очередь — реестр остаётся
 * toggle-слепым, чтобы отключённую пару всегда можно было включить обратно.
 */
#[ORM\Entity(repositoryClass: ConversionToggleRepository::class)]
#[ORM\Table(name: 'conversion_toggles')]
#[ORM\UniqueConstraint(name: 'uniq_conversion_pair', columns: ['from_format', 'to_format'])]
class ConversionToggle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(name: 'from_format', type: 'string', length: 32)]
    private string $fromFormat;

    #[ORM\Column(name: 'to_format', type: 'string', length: 32)]
    private string $toFormat;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $fromFormat, string $toFormat, bool $enabled = true)
    {
        $this->fromFormat = $fromFormat;
        $this->toFormat   = $toFormat;
        $this->enabled    = $enabled;
        $this->createdAt  = new \DateTimeImmutable();
        $this->updatedAt  = $this->createdAt;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getFromFormat(): string
    {
        return $this->fromFormat;
    }

    public function getToFormat(): string
    {
        return $this->toFormat;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled   = $enabled;
        $this->updatedAt = new \DateTimeImmutable();

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

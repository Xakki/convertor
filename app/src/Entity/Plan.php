<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'plans')]
class Plan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private string $name;

    #[ORM\Column(type: 'integer')]
    private int $dailyLimit;

    #[ORM\Column(type: 'integer')]
    private int $dailyAiLimit;

    #[ORM\Column(type: 'integer')]
    private int $maxFileSizeMb;

    #[ORM\Column(type: 'float')]
    private float $priceUsd;

    #[ORM\Column(type: 'integer')]
    private int $priceStars;

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getDailyLimit(): int
    {
        return $this->dailyLimit;
    }

    public function setDailyLimit(int $dailyLimit): self
    {
        $this->dailyLimit = $dailyLimit;
        return $this;
    }

    public function getDailyAiLimit(): int
    {
        return $this->dailyAiLimit;
    }

    public function setDailyAiLimit(int $dailyAiLimit): self
    {
        $this->dailyAiLimit = $dailyAiLimit;
        return $this;
    }

    public function getMaxFileSizeMb(): int
    {
        return $this->maxFileSizeMb;
    }

    public function setMaxFileSizeMb(int $maxFileSizeMb): self
    {
        $this->maxFileSizeMb = $maxFileSizeMb;
        return $this;
    }

    public function getPriceUsd(): float
    {
        return $this->priceUsd;
    }

    public function setPriceUsd(float $priceUsd): self
    {
        $this->priceUsd = $priceUsd;
        return $this;
    }

    public function getPriceStars(): int
    {
        return $this->priceStars;
    }

    public function setPriceStars(int $priceStars): self
    {
        $this->priceStars = $priceStars;
        return $this;
    }
}

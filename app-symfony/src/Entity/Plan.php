<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanRepository::class)]
#[ORM\Table(name: 'plans')]
#[ORM\UniqueConstraint(name: 'UNIQ_PLANS_NAME', columns: ['name'])]
class Plan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 50)]
    private string $name;

    #[ORM\Column(type: 'integer')]
    private int $lightDailyLimit;

    #[ORM\Column(type: 'integer')]
    private int $lightMonthlyLimit;

    #[ORM\Column(type: 'integer')]
    private int $mediumDailyLimit;

    #[ORM\Column(type: 'integer')]
    private int $mediumMonthlyLimit;

    #[ORM\Column(type: 'integer')]
    private int $heavyDailyLimit;

    #[ORM\Column(type: 'integer')]
    private int $heavyMonthlyLimit;

    #[ORM\Column(type: 'integer')]
    private int $aiDailyLimit;

    #[ORM\Column(type: 'integer')]
    private int $aiMonthlyLimit;

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

    public function getLightDailyLimit(): int
    {
        return $this->lightDailyLimit;
    }

    public function setLightDailyLimit(int $lightDailyLimit): self
    {
        $this->lightDailyLimit = $lightDailyLimit;

        return $this;
    }

    public function getLightMonthlyLimit(): int
    {
        return $this->lightMonthlyLimit;
    }

    public function setLightMonthlyLimit(int $lightMonthlyLimit): self
    {
        $this->lightMonthlyLimit = $lightMonthlyLimit;

        return $this;
    }

    public function getMediumDailyLimit(): int
    {
        return $this->mediumDailyLimit;
    }

    public function setMediumDailyLimit(int $mediumDailyLimit): self
    {
        $this->mediumDailyLimit = $mediumDailyLimit;

        return $this;
    }

    public function getMediumMonthlyLimit(): int
    {
        return $this->mediumMonthlyLimit;
    }

    public function setMediumMonthlyLimit(int $mediumMonthlyLimit): self
    {
        $this->mediumMonthlyLimit = $mediumMonthlyLimit;

        return $this;
    }

    public function getHeavyDailyLimit(): int
    {
        return $this->heavyDailyLimit;
    }

    public function setHeavyDailyLimit(int $heavyDailyLimit): self
    {
        $this->heavyDailyLimit = $heavyDailyLimit;

        return $this;
    }

    public function getHeavyMonthlyLimit(): int
    {
        return $this->heavyMonthlyLimit;
    }

    public function setHeavyMonthlyLimit(int $heavyMonthlyLimit): self
    {
        $this->heavyMonthlyLimit = $heavyMonthlyLimit;

        return $this;
    }

    public function getAiDailyLimit(): int
    {
        return $this->aiDailyLimit;
    }

    public function setAiDailyLimit(int $aiDailyLimit): self
    {
        $this->aiDailyLimit = $aiDailyLimit;

        return $this;
    }

    public function getAiMonthlyLimit(): int
    {
        return $this->aiMonthlyLimit;
    }

    public function setAiMonthlyLimit(int $aiMonthlyLimit): self
    {
        $this->aiMonthlyLimit = $aiMonthlyLimit;

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

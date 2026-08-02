<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\DTO\Billing\TopUpPack;
use App\Exception\UnknownTopUpPackException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Реестр пакетов пополнения из env JSON (CNV-28 slice 5).
 *
 * Формат TOPUP_PACKS_JSON:
 * {"pack_100":{"usd_cents":100,"stars":100},"pack_500":{"usd_cents":500,"stars":450}}
 */
class TopUpPackRegistry
{
    /** @var array<string, TopUpPack> */
    private array $packs;

    public function __construct(
        #[Autowire('%env(TOPUP_PACKS_JSON)%')]
        string $packsJson,
    ) {
        $this->packs = self::parse($packsJson);
    }

    /**
     * @return list<TopUpPack>
     */
    public function listPacks(): array
    {
        return array_values($this->packs);
    }

    public function getPack(string $packId): TopUpPack
    {
        return $this->packs[$packId] ?? throw new UnknownTopUpPackException($packId);
    }

    public function hasPack(string $packId): bool
    {
        return isset($this->packs[$packId]);
    }

    /**
     * @return array<string, TopUpPack>
     */
    private static function parse(string $packsJson): array
    {
        $trimmed = trim($packsJson);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('TOPUP_PACKS_JSON must not be empty.');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('TOPUP_PACKS_JSON must decode to a JSON object.');
        }

        $packs = [];
        foreach ($decoded as $packId => $raw) {
            if (! is_string($packId) || ! is_array($raw)) {
                throw new \InvalidArgumentException('Invalid top-up pack entry in TOPUP_PACKS_JSON.');
            }

            $usdCents = $raw['usd_cents'] ?? null;
            $stars    = $raw['stars']     ?? null;
            if (! is_int($usdCents) && ! (is_string($usdCents) && ctype_digit($usdCents))) {
                throw new \InvalidArgumentException(sprintf('Pack %s: usd_cents must be a positive integer.', $packId));
            }
            if (! is_int($stars) && ! (is_string($stars) && ctype_digit($stars))) {
                throw new \InvalidArgumentException(sprintf('Pack %s: stars must be a positive integer.', $packId));
            }

            $usdCentsInt = (int) $usdCents;
            $starsInt    = (int) $stars;
            if ($usdCentsInt <= 0 || $starsInt <= 0) {
                throw new \InvalidArgumentException(sprintf('Pack %s: usd_cents and stars must be positive.', $packId));
            }

            $packs[$packId] = new TopUpPack($packId, $usdCentsInt, $starsInt);
        }

        if ($packs === []) {
            throw new \InvalidArgumentException('TOPUP_PACKS_JSON must contain at least one pack.');
        }

        return $packs;
    }
}

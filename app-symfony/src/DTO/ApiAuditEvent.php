<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class ApiAuditEvent
{
    public readonly \DateTimeImmutable $createdAt;

    public function __construct(
        public string $method,
        public string $route,
        public int $status,
        public int $durationMs,
        public ApiAuditTokenMetadata $token,
        public ?int $conversionId,
        \DateTimeImmutable $createdAt,
    ) {
        $this->createdAt = $createdAt->setTimezone(new \DateTimeZone('UTC'));
        if (! in_array($this->method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new \InvalidArgumentException('Unsupported audit method.');
        }
        if (! self::isNormalizedRoute($this->route)) {
            throw new \InvalidArgumentException('Invalid audit route.');
        }
        if ($this->status < 200 || $this->status > 599 || $this->durationMs < 0 || ($this->conversionId !== null && $this->conversionId < 0)) {
            throw new \InvalidArgumentException('Invalid completed audit event.');
        }
    }

    private static function isNormalizedRoute(string $route): bool
    {
        return preg_match('#^/api/v1/(?:quota|convert(?:/history|/\{id\}/(?:status|download|preview))?)$#D', $route) === 1;
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'method'        => $this->method,
            'route'         => $this->route,
            'status'        => $this->status,
            'duration_ms'   => $this->durationMs,
            'token_label'   => $this->token->label,
            'token_mask'    => $this->token->mask,
            'conversion_id' => $this->conversionId,
            'created_at'    => $this->createdAt->format(DATE_ATOM),
        ];
    }
}

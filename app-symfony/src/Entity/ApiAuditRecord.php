<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ApiAuditRecordRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ApiAuditRecordRepository::class)]
#[ORM\Table(name: 'api_audit_records')]
#[ORM\Index(name: 'IDX_API_AUDIT_OWNER_CREATED', columns: ['owner_id', 'created_at'])]
#[ORM\Index(name: 'IDX_API_AUDIT_CREATED', columns: ['created_at'])]
final class ApiAuditRecord
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(type: 'string', length: 7)]
    private string $method;

    #[ORM\Column(type: 'string', length: 80)]
    private string $route;

    #[ORM\Column(type: 'smallint')]
    private int $status;

    #[ORM\Column(type: 'integer')]
    private int $durationMs;

    #[ORM\Column(type: 'string', length: 100)]
    private string $tokenLabel;

    #[ORM\Column(type: 'string', length: 13)]
    private string $tokenMask;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $conversionId;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $owner, \App\DTO\ApiAuditEvent $event)
    {
        $this->owner        = $owner;
        $this->method       = $event->method;
        $this->route        = $event->route;
        $this->status       = $event->status;
        $this->durationMs   = $event->durationMs;
        $this->tokenLabel   = $event->token->label;
        $this->tokenMask    = $event->token->mask;
        $this->conversionId = $event->conversionId;
        $this->createdAt    = $event->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getOwner(): User
    {
        return $this->owner;
    }
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return ['id'        => $this->id, 'method' => $this->method, 'route' => $this->route,
            'status'        => $this->status, 'duration_ms' => $this->durationMs,
            'token_label'   => $this->tokenLabel, 'token_mask' => $this->tokenMask,
            'conversion_id' => $this->conversionId, 'created_at' => $this->createdAt->format(DATE_ATOM)];
    }
}

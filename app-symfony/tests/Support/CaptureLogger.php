<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;

final class CaptureLogger extends AbstractLogger
{
    /** @var list<array{level:string, message:string, context:array<string,mixed>}> */
    private array $records = [];

    private bool $throwOnLog = false;

    public function log($level, $message, array $context = []): void
    {
        if ($this->throwOnLog) {
            throw new \RuntimeException('test logger failure');
        }

        $this->records[] = [
            'level'   => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /** @return list<array{level:string, message:string, context:array<string,mixed>}> */
    public function records(): array
    {
        return $this->records;
    }

    public function reset(): void
    {
        $this->records    = [];
        $this->throwOnLog = false;
    }

    public function throwOnLog(): void
    {
        $this->throwOnLog = true;
    }
}

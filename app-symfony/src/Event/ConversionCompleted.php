<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Conversion;

/**
 * Fired by {@see \App\Service\Queue\ConversionResultPersister} after the first
 * successful flush that moves a conversion to Completed (CNV-5 chain advance).
 * Not re-emitted on idempotent re-persist of an already-terminal row.
 */
final class ConversionCompleted
{
    public function __construct(
        public readonly Conversion $conversion,
    ) {
    }
}

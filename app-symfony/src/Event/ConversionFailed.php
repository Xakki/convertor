<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Conversion;

/**
 * Fired by {@see \App\Service\Queue\ConversionResultPersister} after the first
 * flush that moves a conversion to Failed (CNV-5 fail-propagate). Not re-emitted
 * on idempotent re-persist of an already-terminal row.
 */
final class ConversionFailed
{
    public function __construct(
        public readonly Conversion $conversion,
    ) {
    }
}

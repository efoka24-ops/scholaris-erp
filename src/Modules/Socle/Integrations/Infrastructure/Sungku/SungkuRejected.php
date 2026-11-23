<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Infrastructure\Sungku;

use RuntimeException;

/**
 * Sungku a répondu clairement par un refus (4xx hors 429) : l'échec est PROUVÉ,
 * la transaction locale peut passer à « échouée » immédiatement.
 */
final class SungkuRejected extends RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $message = 'Sungku a refusé la demande.')
    {
        parent::__construct($message, $httpStatus);
    }
}

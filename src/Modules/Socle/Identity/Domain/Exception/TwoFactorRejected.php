<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Domain\Exception;

use RuntimeException;

/** Code de double authentification refusé ; après trois échecs la session est fermée. */
final class TwoFactorRejected extends RuntimeException
{
    public function __construct(public readonly int $attemptsLeft)
    {
        parent::__construct(
            $attemptsLeft > 0
                ? sprintf('Code incorrect · %d %s restant%s.', $attemptsLeft, $attemptsLeft > 1 ? 'essais' : 'essai', $attemptsLeft > 1 ? 's' : '')
                : 'Trop d\'essais. Reconnectez-vous.',
        );
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Domain\Exception;

use RuntimeException;

/** Code à usage unique refusé : incorrect (essais restants), expiré ou épuisé. */
final class CodeRejected extends RuntimeException
{
    public const INVALID = 'invalid';
    public const EXPIRED = 'expired';
    public const EXHAUSTED = 'exhausted';

    private function __construct(public readonly string $reason, public readonly int $attemptsLeft, string $message)
    {
        parent::__construct($message);
    }

    public static function invalid(int $attemptsLeft): self
    {
        return new self(self::INVALID, $attemptsLeft, sprintf(
            'Code incorrect. Il vous reste %d %s.',
            $attemptsLeft,
            $attemptsLeft > 1 ? 'essais' : 'essai',
        ));
    }

    public static function expired(): self
    {
        return new self(self::EXPIRED, 0, 'Ce code a expiré. Demandez-en un nouveau.');
    }

    public static function exhausted(): self
    {
        return new self(self::EXHAUSTED, 0, 'Trop d\'essais. Demandez un nouveau code.');
    }
}

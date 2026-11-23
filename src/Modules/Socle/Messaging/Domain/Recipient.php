<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Domain;

/** Destinataire résolu d'une audience, avec les variables qui lui sont propres (enfant, solde...). */
final class Recipient
{
    /** @param array<string, scalar> $variables */
    public function __construct(
        public readonly string $personId,
        public readonly array $variables = [],
    ) {
    }
}

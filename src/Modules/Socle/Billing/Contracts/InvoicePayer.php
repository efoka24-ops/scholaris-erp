<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Contracts;

use InvalidArgumentException;

/** Payeur tiers au nom duquel une facture est établie (S13) : une personne (garant, parrain) ou un organisme (entreprise, bailleur). */
final class InvoicePayer
{
    public const PERSON = 'person';
    public const ORGANISATION = 'organisation';

    public function __construct(
        public readonly string $kind,
        public readonly string $name,
        public readonly ?string $reference = null,
    ) {
        if (!in_array($kind, [self::PERSON, self::ORGANISATION], true)) {
            throw new InvalidArgumentException('Nature du payeur : person ou organisation.');
        }
        $length = mb_strlen(trim($name));
        if ($length === 0 || $length > 160) {
            throw new InvalidArgumentException('Nom du payeur : 1 à 160 caractères.');
        }
        if ($reference !== null && (trim($reference) === '' || mb_strlen($reference) > 60)) {
            throw new InvalidArgumentException('Référence du payeur : 1 à 60 caractères.');
        }
    }
}

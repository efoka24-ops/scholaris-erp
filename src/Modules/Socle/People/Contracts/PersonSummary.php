<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/** Personne vue par les autres modules (lecture seule, sans données sensibles). */
final class PersonSummary
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
        /** fr ou en : langue des documents et messages */
        public readonly string $locale = 'fr',
        /** genre déclaré (« male », « female »), null si non renseigné : sert aux statistiques réglementaires */
        public readonly ?string $gender = null,
    ) {
    }

    public function fullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }
}

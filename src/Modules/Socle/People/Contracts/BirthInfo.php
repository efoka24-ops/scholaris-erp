<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/** Date de naissance et majorité d'une personne. Fourni par People ; les autres modules ne lisent jamais `people.birth_date`. */
interface BirthInfo
{
    public const ADULT_AGE = 18;

    /** @return string|null date AAAA-MM-JJ ; null si la personne est inconnue dans ce client ou sans date de naissance */
    public function birthDate(string $tenantId, string $personId): ?string;

    /**
     * Majorité (18 ans révolus) à la date donnée (AAAA-MM-JJ).
     *
     * @return bool|null null si la date de naissance est inconnue (le module appelant choisit son repli prudent)
     */
    public function isAdult(string $tenantId, string $personId, string $atDate): ?bool;
}

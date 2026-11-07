<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/** Consentements horodatés et révocables (EX-S03-07). Fourni par le module People. */
interface ConsentChecker
{
    public const COMMUNICATION = 'communication';
    public const PHOTO = 'photo';
    public const PARENT_ACCESS = 'parent_access';

    /** Vrai si la personne a donné ce consentement et ne l'a pas révoqué. */
    public function hasConsent(string $tenantId, string $personId, string $purpose): bool;
}

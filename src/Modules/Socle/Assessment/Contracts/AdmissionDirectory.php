<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/**
 * Décisions d'admission d'une année scolaire, pour le passage en classe supérieure. Fourni par le module Assessment (S09).
 * Une personne est « admise » si sa dernière décision de l'année (conseil de classe ou jury) est « Admis » ou « Admis par compensation ».
 */
interface AdmissionDirectory
{
    /** @return list<string> identifiants des personnes admises dans cet établissement pour cette année */
    public function admittedPersonIds(string $tenantId, string $establishmentId, string $academicYearId): array;
}

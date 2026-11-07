<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/**
 * Inscriptions d'une structure (classe) en une seule lecture (listes d'élèves, saisies de notes). Contrat distinct
 * d'EnrollmentDirectory pour ne pas imposer de nouvelle méthode à ses implémentations existantes. Fourni par People.
 */
interface StructureEnrollments
{
    /** @return list<EnrollmentInfo> inscriptions (hors brouillons) rattachées à cette structure, par identifiant croissant */
    public function inStructure(string $tenantId, string $structureId): array;
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Contracts;

/**
 * Classes, sections et effectifs (CLA), fourni par PublicSchools\Pupils. Lecture seule, bornée au client et à l'établissement :
 * une classe d'une autre école ou d'un autre client est introuvable. Les effectifs ne contiennent que des nombres et des
 * identifiants de personnes ; la lecture nominative passe par les routes de PublicSchools et son périmètre.
 */
interface ClassRoster
{
    /**
     * Classes de l'école pour l'année, triées par libellé.
     *
     * @return list<ClassInfo>
     */
    public function classesOf(string $tenantId, string $establishmentId, string $yearId): array;

    public function find(string $tenantId, string $establishmentId, string $structureId): ?ClassInfo;

    /**
     * Identifiants des personnes inscrites dans la classe à la date (AAAA-MM-JJ), triés ; liste vide si la classe est inconnue.
     *
     * @return list<string>
     */
    public function pupilIdsIn(string $tenantId, string $establishmentId, string $structureId, string $date): array;

    /** Effectif de la classe à la date (AAAA-MM-JJ), garçons et filles ; zéro si la classe est inconnue. */
    public function headcount(string $tenantId, string $establishmentId, string $structureId, string $date): int;
}

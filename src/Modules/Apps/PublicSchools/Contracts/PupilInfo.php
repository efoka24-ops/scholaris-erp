<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Contracts;

/** Élève (ou enfant) tel que le voient les autres applications : identité minimale, jamais de champ APEE ni de situation particulière. */
final class PupilInfo
{
    public function __construct(
        public readonly string $tenantId,
        /** identifiant de la personne dans le socle (People) */
        public readonly string $personId,
        /** identifiant national de l'élève, attribué une seule fois ; null tant qu'il n'est pas attribué */
        public readonly ?string $learnerNumber,
        public readonly string $lastName,
        public readonly string $firstName,
        /** date de naissance ISO (AAAA-MM-JJ) ; null si inconnue */
        public readonly ?string $birthDate,
        /** vrai si l'acte de naissance est présenté et vérifié (un élève sans acte reste accepté, signalé) */
        public readonly bool $birthActVerified,
    ) {
    }
}

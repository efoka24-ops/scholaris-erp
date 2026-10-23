<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Contracts;

/** Données d'inscription d'un élève ou d'un enfant (EX-ELV-01) : aucun champ APEE n'existe, par construction (RG-ELV-1). */
final class PupilDraft
{
    public function __construct(
        public readonly string $lastName,
        public readonly string $firstName,
        /** date de naissance ISO (AAAA-MM-JJ) */
        public readonly string $birthDate,
        /** F ou M */
        public readonly string $sex,
        /** référence de l'acte de naissance ; null = élève sans acte (accepté, signalé) */
        public readonly ?string $birthActRef = null,
        /** identifiant client de la saisie hors ligne (idempotence) */
        public readonly ?string $clientUuid = null,
    ) {
    }
}

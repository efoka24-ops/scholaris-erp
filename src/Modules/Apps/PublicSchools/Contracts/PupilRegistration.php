<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Contracts;

/** Résultat d'une inscription : l'élève, et les avertissements non bloquants (âge hors norme, acte manquant, doublon possible). */
final class PupilRegistration
{
    /** @param list<string> $warnings codes d'avertissement (ex. `birth_act_missing`, `age_out_of_range`, `possible_duplicate`) */
    public function __construct(
        public readonly PupilInfo $pupil,
        public readonly array $warnings = [],
    ) {
    }
}

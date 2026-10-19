<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/**
 * Point d'extension (PP8) : format et séquence de l'identifiant d'élève d'une application. Enregistrement dans le conteneur
 * sous une entrée de liste `people.learner_number.providers.<application>` (voir PREFIX). Le premier fournisseur (ordre des noms
 * d'entrée) dont supports() est vrai donne l'identifiant ; next() doit rendre une valeur unique du client, jamais réattribuée.
 * Sans fournisseur concerné, LearnerNumbering::assign() refuse : le socle n'invente aucun format.
 */
interface LearnerNumberProvider
{
    public const PREFIX = 'people.learner_number.providers.';

    public function supports(string $tenantId, string $establishmentId, string $personId): bool;

    public function next(string $tenantId, string $establishmentId, string $personId): string;
}

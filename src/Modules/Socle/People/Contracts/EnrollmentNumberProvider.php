<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/**
 * Point d'extension (D5) : numéro d'inscription (matricule) fourni par une application, à la place du `MAT-AAAA-NNNNN` du socle.
 * Enregistrement dans le conteneur sous une entrée de liste `people.enrollment_number.providers.<application>` (voir PREFIX).
 * À la validation d'une inscription, le premier fournisseur (ordre des noms d'entrée) dont supports() est vrai donne le numéro ;
 * sans fournisseur concerné, le numéro du socle est conservé. next() est appelé dans la transaction de numérotation du socle :
 * il doit rendre un numéro unique et jamais réattribué ; il peut rendre le numéro déjà attribué à la personne (double cursus :
 * un seul matricule), qui n'est alors pas enregistré deux fois comme identifiant.
 */
interface EnrollmentNumberProvider
{
    public const PREFIX = 'people.enrollment_number.providers.';

    public function supports(EnrollmentNumberRequest $request): bool;

    public function next(EnrollmentNumberRequest $request): string;
}

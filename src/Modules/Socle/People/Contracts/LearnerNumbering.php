<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/**
 * Identifiant d'élève (`people.learner_number`), attribué une seule fois et jamais modifié ensuite. Le format vient des
 * fournisseurs `people.learner_number.providers.<application>` (LearnerNumberProvider).
 */
interface LearnerNumbering
{
    /** Identifiant déjà attribué à la personne du client, ou null (aussi si la personne est inconnue du client). */
    public function find(string $tenantId, string $personId): ?string;

    /**
     * Attribue l'identifiant d'une personne qui n'en a pas encore.
     *
     * @return string l'identifiant attribué
     * @throws \Scholaris\Core\Http\HttpException 404 (personne inconnue du client), 409 (déjà numérotée ou identifiant déjà pris), 422 (aucun fournisseur concerné)
     */
    public function assign(string $tenantId, string $personId, string $establishmentId): string;
}

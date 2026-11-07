<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Documents\Contracts;

/** Documents du secondaire valides d'une personne (portails, registre par élève). Fourni par Documents\Application\StudentDocuments. */
interface IssuedStudentDocuments
{
    /**
     * @return list<array<string, mixed>> id, number, kind, doc, type, status, qr, verification_token (aucun contenu, aucune note)
     */
    public function issuedFor(string $tenantId, string $personId): array;
}

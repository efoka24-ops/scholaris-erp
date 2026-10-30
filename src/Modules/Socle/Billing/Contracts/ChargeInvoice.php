<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Contracts;

/**
 * Facture libre, sans inscription (D4, S13) : frais de dossier d'un candidat, frais de soutenance, d'examen national...
 * `$subjectId` est la personne concernée (le candidat a une fiche personne, pas encore d'inscription). `$reference` est la
 * clé stable de l'appelant (ex. identifiant de la candidature) : rappeler avec la même référence renvoie la même facture.
 */
final class ChargeInvoice
{
    /** @param list<ChargeLine> $lines */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $establishmentId,
        public readonly string $actorId,
        public readonly string $subjectId,
        public readonly string $academicYearId,
        public readonly string $reference,
        public readonly array $lines,
        public readonly ?InvoicePayer $payer = null,
        public readonly ?string $structureId = null,
    ) {
    }
}

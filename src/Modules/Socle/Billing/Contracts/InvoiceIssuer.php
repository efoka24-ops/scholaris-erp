<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Contracts;

/**
 * Contrat d'écriture des factures pour les applications (D4). Mêmes règles que les routes /invoices : grilles, remises et bourses
 * (plafond `billing.discount_cap_percent`, cibles `billing.discount_applies_to`), audit, crédit famille réaffecté.
 *
 * Erreurs (HttpException) : 404 inscription, facture ou frais introuvable ; 409 conflit ; 422 règle de gestion.
 */
interface InvoiceIssuer
{
    /**
     * Facture d'une inscription à partir des grilles de frais (comme POST /invoices), éventuellement au nom d'un tiers (S13).
     *
     * @param list<array{kind: string, mode: string, value: int, applies_to?: string, reason: string}> $discounts
     */
    public function forEnrollment(
        string $tenantId,
        string $establishmentId,
        string $actorId,
        string $enrollmentId,
        ?string $level = null,
        ?string $track = null,
        array $discounts = [],
        ?InvoicePayer $payer = null,
    ): InvoiceSummary;

    /** Facture libre sans inscription (candidat, frais ponctuels) ; idempotente par référence. */
    public function forCharges(ChargeInvoice $invoice): InvoiceSummary;

    /** Remise ou bourse sur une facture (types sibling, staff, merit, waiver) ; renvoie la facture à jour. */
    public function addDiscount(
        string $tenantId,
        string $establishmentId,
        string $actorId,
        string $invoiceId,
        string $kind,
        string $mode,
        int $value,
        string $appliesTo,
        string $reason,
    ): InvoiceSummary;

    public function find(string $tenantId, string $establishmentId, string $invoiceId): ?InvoiceSummary;

    /** Facture libre déjà émise pour une référence de l'appelant. */
    public function findByReference(string $tenantId, string $establishmentId, string $reference): ?InvoiceSummary;
}

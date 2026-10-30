<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Billing\Infrastructure\InvoiceRepository;
use Scholaris\Modules\Socle\Billing\Infrastructure\PaymentRepository;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Périmètre du paiement en libre-service (portails famille et apprenant, D2) : la personne connectée, les apprenants dont
 * elle est garant (et elle-même), et sa référence famille. Tout objet hors de ce périmètre est traité comme inexistant (404).
 */
final class SelfServiceScope
{
    public function __construct(
        private readonly UserPersonLink $users,
        private readonly PersonDirectory $people,
        private readonly PaymentRepository $payments,
        private readonly InvoiceRepository $invoices,
    ) {
    }

    public function personId(Principal $principal): string
    {
        return $this->users->personIdForUser($principal->tenantId, $principal->userId)
            ?? throw HttpException::forbidden('Aucune fiche personne n\'est liée à ce compte.');
    }

    /** @return list<string> la personne et les apprenants dont elle est garant */
    public function learnerIds(string $tenantId, string $personId): array
    {
        $ids = [$personId];
        foreach ($this->people->learnersOf($tenantId, $personId) as $learner) {
            if ($learner->tenantId === $tenantId) {
                $ids[] = $learner->id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<string> identifiants des références famille de la personne */
    public function referenceIds(string $tenantId, string $personId): array
    {
        $reference = $this->payments->referenceOfGuarantor($tenantId, $personId);

        return $reference === null ? [] : [(string) $reference['id']];
    }

    public function ownsReference(string $tenantId, string $personId, string $reference): bool
    {
        $row = $this->payments->referenceForTenant($tenantId, $reference);

        return $row !== null && $row['guarantor_id'] === $personId;
    }

    public function ownsInvoice(string $tenantId, string $personId, string $invoiceId): bool
    {
        $invoice = $this->invoices->find($tenantId, $invoiceId);

        return $invoice !== null && in_array((string) $invoice['learner_id'], $this->learnerIds($tenantId, $personId), true);
    }

    /** @param array<string, mixed> $payment */
    public function ownsPayment(string $tenantId, string $personId, array $payment): bool
    {
        if ($payment['payer_reference_id'] !== null && in_array((string) $payment['payer_reference_id'], $this->referenceIds($tenantId, $personId), true)) {
            return true;
        }
        if ($payment['learner_id'] !== null && in_array((string) $payment['learner_id'], $this->learnerIds($tenantId, $personId), true)) {
            return true;
        }

        return $payment['invoice_id'] !== null && $this->ownsInvoice($tenantId, $personId, (string) $payment['invoice_id']);
    }
}

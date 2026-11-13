<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Contracts;

/** Facture vue par une application : totaux en XAF, lignes (avec code de frais et part payée), échéances, payeur tiers. */
final class InvoiceSummary
{
    public const KIND_ENROLLMENT = 'enrollment';
    public const KIND_CHARGES = 'charges';

    /**
     * @param list<array{id: string, fee_item_id: string, fee_code: string|null, kind: string, label: string, gross_xaf: int, discount_xaf: int, net_xaf: int, paid_xaf: int}> $lines
     * @param list<array{id: string, line_id: string, label: string, due_on: string, amount_xaf: int, paid_xaf: int}> $installments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $establishmentId,
        public readonly string $subjectId,
        public readonly string $kind,
        public readonly ?string $enrollmentId,
        public readonly ?string $reference,
        public readonly string $status,
        public readonly int $grossXaf,
        public readonly int $discountXaf,
        public readonly int $netXaf,
        public readonly int $paidXaf,
        public readonly int $balanceXaf,
        public readonly ?InvoicePayer $payer,
        public readonly array $lines,
        public readonly array $installments,
    ) {
    }

    /** Montant restant dû sur les lignes d'un code de frais (0 si aucune ligne de ce code). */
    public function balanceForCode(string $feeCode): int
    {
        $due = 0;
        foreach ($this->lines as $line) {
            if ($line['fee_code'] === $feeCode) {
                $due += $line['net_xaf'] - $line['paid_xaf'];
            }
        }

        return max(0, $due);
    }
}

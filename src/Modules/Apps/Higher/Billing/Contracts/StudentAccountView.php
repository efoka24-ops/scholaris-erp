<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Contracts;

/** Compte de scolarité vu par un autre module : totaux en XAF, tranches datées avec leur état, reçus des paiements. */
final class StudentAccountView
{
    /**
     * @param list<array{id: string, label: string, due_on: string, amount_xaf: int, paid_xaf: int, remaining_xaf: int, status: string, overdue: bool}> $installments
     * @param list<array{payment_id: string, receipt_number: ?string, amount_xaf: int, channel: string, status: string, recorded_at: string}> $receipts
     */
    public function __construct(
        public readonly string $id,
        public readonly string $establishmentId,
        public readonly string $academicYearId,
        public readonly string $personId,
        public readonly string $schoolId,
        public readonly ?string $programId,
        public readonly ?string $levelId,
        public readonly string $invoiceId,
        public readonly ?string $payerName,
        public readonly int $netXaf,
        public readonly int $paidXaf,
        public readonly int $balanceXaf,
        public readonly bool $upToDate,
        public readonly array $installments,
        public readonly array $receipts,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'establishment_id' => $this->establishmentId, 'academic_year_id' => $this->academicYearId,
            'person_id' => $this->personId, 'school_id' => $this->schoolId, 'program_id' => $this->programId, 'level_id' => $this->levelId,
            'invoice_id' => $this->invoiceId, 'payer_name' => $this->payerName, 'net_xaf' => $this->netXaf, 'paid_xaf' => $this->paidXaf,
            'balance_xaf' => $this->balanceXaf, 'up_to_date' => $this->upToDate, 'installments' => $this->installments, 'receipts' => $this->receipts,
        ];
    }
}

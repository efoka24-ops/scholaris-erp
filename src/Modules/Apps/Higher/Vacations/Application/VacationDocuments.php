<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Vacations\Application;

use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentIssuer;
use Scholaris\Modules\Apps\Higher\Vacations\Documents\VacationDocumentTypes;
use Scholaris\Modules\Apps\Higher\Vacations\Domain\VacationStatus;
use Scholaris\Modules\Apps\Higher\Vacations\Infrastructure\VacationStore;

/** Émission de l'état mensuel (DOC-25, état visé ou plus) et du bulletin de paiement (DOC-26, état payé) par le module Documents. */
final class VacationDocuments
{
    public function __construct(
        private readonly VacationStore $store,
        private readonly WorkflowService $workflow,
        private readonly HigherDocumentIssuer $issuer,
        private readonly VacationClock $clock,
    ) {
    }

    /** @return array<string, mixed> document émis */
    public function contract(Principal $principal, string $establishmentId, string $id): array
    {
        $row = $this->workflow->writable($principal, $establishmentId, $id, VacationPermissions::STATEMENT_DOCUMENT, [VacationStatus::APPROVED, VacationStatus::PAID]);

        return $this->issue($principal, $establishmentId, $row, VacationDocumentTypes::CONTRACT, 'contract_document_id', [
            'hours_summary' => $this->summary($row),
            'hourly_rate' => $row['hourly_rate'] === null ? 'variable' : VacationNotices::amount((int) $row['hourly_rate']),
        ]);
    }

    /** @param array<string, mixed> $row */
    private function summary(array $row): string
    {
        $kinds = [];
        foreach (['cm' => 'CM', 'td' => 'TD', 'tp' => 'TP', 'other' => 'Autre'] as $kind => $label) {
            if ((int) $row[$kind . '_minutes'] > 0) {
                $kinds[] = sprintf('%s %s h = %s HETD', $label, VacationNotices::hetd(intdiv((int) $row[$kind . '_minutes'] * 1000, 60)), VacationNotices::hetd((int) $row[$kind . '_hetd_milli']));
            }
        }

        return implode(' ; ', $kinds);
    }

    /** @return array<string, mixed> document émis */
    public function statement(Principal $principal, string $establishmentId, string $id): array
    {
        $row = $this->workflow->writable($principal, $establishmentId, $id, VacationPermissions::STATEMENT_DOCUMENT, [VacationStatus::DRAFT, VacationStatus::SCHOOL_VALIDATED, VacationStatus::APPROVED, VacationStatus::PAID]);

        return $this->issue($principal, $establishmentId, $row, VacationDocumentTypes::STATEMENT, 'statement_document_id', [
            'hours_summary' => $this->summary($row),
            'hourly_rate' => $row['hourly_rate'] === null ? 'variable' : VacationNotices::amount((int) $row['hourly_rate']),
        ]);
    }

    /** @return array<string, mixed> document émis */
    public function payslip(Principal $principal, string $establishmentId, string $id): array
    {
        $row = $this->workflow->writable($principal, $establishmentId, $id, VacationPermissions::PAY, [VacationStatus::PAID]);

        return $this->issue($principal, $establishmentId, $row, VacationDocumentTypes::PAYSLIP, 'payslip_document_id', [
            'payment_method' => $row['payment_method'] === 'mobile_money' ? 'mobile money' : 'virement',
            'payment_reference' => (string) $row['payment_reference'],
            'paid_on' => (string) $row['paid_on'],
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $extra
     * @return array<string, mixed>
     */
    private function issue(Principal $principal, string $establishmentId, array $row, string $code, string $column, array $extra): array
    {
        $data = $extra + [
            'period_month' => (string) $row['period_month'],
            'hetd_total' => VacationNotices::hetd((int) $row['hetd_milli']),
            'gross_amount' => VacationNotices::amount((int) $row['gross_amount']),
            'deductions_amount' => VacationNotices::amount((int) $row['deductions_amount']),
            'net_amount' => VacationNotices::amount((int) $row['net_amount']),
        ];
        $document = $this->issuer->issueFor($principal->tenantId, $establishmentId, $code, (string) $row['person_id'], (string) $row['school_id'], null, null, $data, $principal->userId);
        $this->store->update(StatementService::TABLE, $principal->tenantId, $establishmentId, [$column => (string) ($document['id'] ?? ''), 'updated_at' => $this->clock->now()], ['id' => (string) $row['id']]);

        return $document;
    }
}

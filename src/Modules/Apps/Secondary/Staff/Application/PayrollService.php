<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Staff\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\SecondaryDocuments;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Paie simplifiée du privé (T912, module `payroll_simple`, Q9) : brut et retenues saisis par l'intendant, net = brut - retenues, aucun
 * taux légal calculé. Statuts éligibles fixés par l'établissement, jamais un fonctionnaire (Q31). Cycle : brouillon (intendant),
 * validé (chef d'établissement, autre que l'auteur), payé (intendant) avec émission de DOC-30 par le socle Documents. Les routes
 * sont gardées par le module (écritures : module actif ; lectures : aussi module coupé avec données).
 */
final class PayrollService
{
    public const TABLE = 'se_payslips';
    public const RULES = 'se_staff_payroll_rules';
    /** Statuts éligibles proposés ; le fonctionnaire n'y figure jamais. */
    public const ELIGIBLE = ['private_employee', 'hourly', 'state_contract'];
    /** Valeur de départ tant que l'établissement n'a rien fixé (privé : salarié et vacataire, Q31). */
    public const DEFAULT_ELIGIBLE = ['private_employee', 'hourly'];
    private const MONTH = '/^\d{4}-(0[1-9]|1[0-2])$/';

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly StaffService $staff,
        private readonly SecondaryDocuments $documents,
        private readonly SecondaryClock $clock,
    ) {
    }

    /** @return array{eligible_statuses: list<string>} */
    public function rules(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, [...StaffAccess::PAYROLL_READ, ...StaffAccess::PAYROLL_VALIDATE]);

        return ['eligible_statuses' => $this->eligibleStatuses($principal->tenantId, $establishmentId)];
    }

    /**
     * @param array<string, mixed> $body eligible_statuses
     * @return array{eligible_statuses: list<string>}
     */
    public function saveRules(Principal $principal, string $establishmentId, array $body): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, StaffAccess::PAYROLL_VALIDATE);
        $this->access->assertWhole($principal, $perimeter, self::RULES);
        $input = new Input($body);
        /** @var list<string> $statuses */
        $statuses = $input->custom('eligible_statuses', static function (mixed $value): array {
            if (!is_array($value) || !array_is_list($value) || $value === [] || array_diff($value, self::ELIGIBLE) !== []) {
                throw new \InvalidArgumentException('Choisissez au moins un statut parmi : ' . implode(', ', self::ELIGIBLE) . ' (jamais un fonctionnaire).');
            }

            return array_values(array_unique($value));
        });
        $input->done();
        $existing = $this->store->select(self::RULES, $principal->tenantId, $establishmentId, [], 'id', 1)[0] ?? null;
        $values = ['eligible_statuses' => json_encode($statuses, JSON_THROW_ON_ERROR), 'updated_by' => $principal->userId, 'updated_at' => $this->clock->now()];
        if ($existing === null) {
            $id = (string) new Ulid();
            $this->store->insert(self::RULES, $principal->tenantId, $establishmentId, $values + ['id' => $id]);
        } else {
            $id = (string) $existing['id'];
            $this->store->update(self::RULES, $principal->tenantId, $establishmentId, $values, ['id' => $id]);
        }
        $this->access->audit($principal, 'secondary.staff.payroll_rules_saved', self::RULES, $id, $existing === null ? null : ['eligible_statuses' => $existing['eligible_statuses']], ['eligible_statuses' => $statuses]);

        return ['eligible_statuses' => $statuses];
    }

    /** @return list<array<string, mixed>> */
    public function payslips(Principal $principal, string $establishmentId, ?string $month): array
    {
        $this->access->require($principal, $establishmentId, StaffAccess::PAYROLL_READ);
        if ($month !== null && preg_match(self::MONTH, $month) !== 1) {
            throw HttpException::validation(['month' => ['Mois au format AAAA-MM attendu.']]);
        }

        return array_map(self::resource(...), $this->store->select(self::TABLE, $principal->tenantId, $establishmentId, $month === null ? [] : ['month_ref' => $month], 'month_ref DESC, created_at DESC', 1000));
    }

    /**
     * @param array<string, mixed> $body staff_profile_id, month_ref, gross_amount, deductions [{label, amount}]
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $body): array
    {
        $this->access->require($principal, $establishmentId, StaffAccess::PAYROLL_PREPARE);
        $input = new Input($body);
        $profileId = (string) $input->reference('staff_profile_id');
        $month = (string) $input->custom('month_ref', static function (mixed $value): string {
            if (!is_string($value) || preg_match(self::MONTH, $value) !== 1) {
                throw new \InvalidArgumentException('Mois au format AAAA-MM attendu.');
            }

            return $value;
        });
        $gross = (int) $input->int('gross_amount', 0, 1000000000);
        /** @var list<array{label: string, amount: int}> $deductions */
        $deductions = $input->custom('deductions', static function (mixed $value): array {
            if (!is_array($value) || !array_is_list($value) || count($value) > 30) {
                throw new \InvalidArgumentException('Liste de 30 retenues au plus attendue.');
            }
            $lines = [];
            foreach ($value as $index => $line) {
                $label = is_array($line) && is_string($line['label'] ?? null) ? trim($line['label']) : '';
                $amount = is_array($line) ? ($line['amount'] ?? null) : null;
                if ($label === '' || mb_strlen($label) > 100 || !is_int($amount) || $amount < 0) {
                    throw new \InvalidArgumentException('Chaque retenue a un libellé (100 caractères au plus) et un montant entier en FCFA positif ou nul (ligne ' . ($index + 1) . ').');
                }
                $lines[] = ['label' => $label, 'amount' => $amount];
            }

            return $lines;
        }, false) ?? [];
        $input->done();
        $profile = $this->eligibleProfile($principal->tenantId, $establishmentId, $profileId);
        $deducted = array_sum(array_column($deductions, 'amount'));
        if ($deducted > $gross) {
            throw HttpException::validation(['deductions' => ['Le total des retenues ne peut pas dépasser le brut.']]);
        }
        if ($this->store->count(self::TABLE, $principal->tenantId, $establishmentId, ['staff_profile_id' => $profileId, 'month_ref' => $month]) > 0) {
            throw HttpException::conflict('Un bulletin existe déjà pour ce membre du personnel et ce mois.');
        }
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $values = [
            'id' => $id, 'staff_profile_id' => $profileId, 'person_id' => (string) $profile['person_id'], 'month_ref' => $month,
            'gross_amount' => $gross, 'deductions_json' => json_encode($deductions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'deductions_amount' => $deducted, 'net_amount' => $gross - $deducted, 'status' => 'draft',
            'created_by' => $principal->userId, 'validated_by' => null, 'validated_at' => null, 'paid_by' => null,
            'paid_on' => null, 'document_id' => null, 'document_number' => null, 'created_at' => $now, 'updated_at' => $now,
        ];
        $this->store->insert(self::TABLE, $principal->tenantId, $establishmentId, $values);
        $this->access->audit($principal, 'secondary.staff.payslip_created', self::TABLE, $id, null, ['staff_profile_id' => $profileId, 'month_ref' => $month, 'gross_amount' => $gross, 'deductions_amount' => $deducted, 'net_amount' => $gross - $deducted]);

        return self::resource($values);
    }

    /** @return array<string, mixed> */
    public function validate(Principal $principal, string $establishmentId, string $id): array
    {
        $this->access->require($principal, $establishmentId, StaffAccess::PAYROLL_VALIDATE);
        $row = $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id, 'Bulletin de paie introuvable.');
        if ($row['status'] !== 'draft') {
            throw HttpException::conflict('Seul un bulletin en brouillon peut être validé.');
        }
        if ($row['created_by'] === $principal->userId) {
            throw HttpException::forbidden('Un bulletin est validé par une autre personne que celle qui l\'a préparé.');
        }
        $this->eligibleProfile($principal->tenantId, $establishmentId, (string) $row['staff_profile_id']);
        $now = $this->clock->now();
        $values = ['status' => 'validated', 'validated_by' => $principal->userId, 'validated_at' => $now, 'updated_at' => $now];
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, $values, ['id' => $id]);
        $this->access->audit($principal, 'secondary.staff.payslip_validated', self::TABLE, $id, ['status' => 'draft'], $values);

        return self::resource(array_replace($row, $values));
    }

    /** @return array<string, mixed> */
    public function pay(Principal $principal, string $establishmentId, string $id, ?string $locale): array
    {
        $this->access->require($principal, $establishmentId, StaffAccess::PAYROLL_PREPARE);
        $row = $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id, 'Bulletin de paie introuvable.');
        if ($row['status'] !== 'validated') {
            throw HttpException::conflict('Seul un bulletin validé peut être marqué payé.');
        }
        $profile = $this->eligibleProfile($principal->tenantId, $establishmentId, (string) $row['staff_profile_id']);
        $deductions = json_decode((string) $row['deductions_json'], true);
        $lines = [];
        foreach (is_array($deductions) ? $deductions : [] as $line) {
            $lines[] = sprintf('%s : %s', (string) ($line['label'] ?? ''), self::money((int) ($line['amount'] ?? 0)));
        }
        $document = $this->documents->issue($principal->tenantId, $establishmentId, PayrollDocumentTypes::PAYSLIP, (string) $profile['person_id'], [
            'period_month' => (string) $row['month_ref'],
            'payroll_ref' => (string) ($profile['payroll_ref'] ?? '') !== '' ? (string) $profile['payroll_ref'] : '-',
            'gross_amount' => self::money((int) $row['gross_amount']),
            'deductions_block' => $lines === [] ? '-' : implode("\n", $lines),
            'deductions_amount' => self::money((int) $row['deductions_amount']),
            'net_amount' => self::money((int) $row['net_amount']),
        ], $principal->userId, $locale);
        $now = $this->clock->now();
        $values = ['status' => 'paid', 'paid_by' => $principal->userId, 'paid_on' => $this->clock->today(), 'document_id' => $document->id, 'document_number' => $document->number, 'updated_at' => $now];
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, $values, ['id' => $id]);
        $this->access->audit($principal, 'secondary.staff.payslip_paid', self::TABLE, $id, ['status' => 'validated'], $values);

        return self::resource(array_replace($row, $values));
    }

    /** @return array<string, mixed> */
    public function show(Principal $principal, string $establishmentId, string $id): array
    {
        $this->access->require($principal, $establishmentId, StaffAccess::PAYROLL_READ);

        return self::resource($this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id, 'Bulletin de paie introuvable.'));
    }

    /** @return list<string> */
    public function eligibleStatuses(string $tenantId, string $establishmentId): array
    {
        $row = $this->store->select(self::RULES, $tenantId, $establishmentId, [], 'id', 1)[0] ?? null;
        $statuses = $row === null ? self::DEFAULT_ELIGIBLE : json_decode((string) $row['eligible_statuses'], true);

        return is_array($statuses) ? array_values(array_intersect(self::ELIGIBLE, $statuses)) : [];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        $deductions = json_decode((string) $row['deductions_json'], true);
        $text = static fn (mixed $value): ?string => $value === null ? null : (string) $value;

        return [
            'id' => (string) $row['id'], 'staff_profile_id' => (string) $row['staff_profile_id'], 'person_id' => (string) $row['person_id'],
            'month_ref' => (string) $row['month_ref'], 'gross_amount' => (int) $row['gross_amount'],
            'deductions' => is_array($deductions) ? $deductions : [], 'deductions_amount' => (int) $row['deductions_amount'],
            'net_amount' => (int) $row['net_amount'], 'status' => (string) $row['status'],
            'created_by' => (string) $row['created_by'], 'validated_by' => $text($row['validated_by']), 'validated_at' => $text($row['validated_at']),
            'paid_on' => $text($row['paid_on']), 'document_id' => $text($row['document_id']), 'document_number' => $text($row['document_number'] ?? null),
        ];
    }

    /** Montant entier en FCFA, séparateur de milliers espace (ex. « 250 000 FCFA »). */
    public static function money(int $amount): string
    {
        return number_format($amount, 0, ',', ' ') . ' FCFA';
    }

    /**
     * Profil actif dont le statut est éligible : jamais un fonctionnaire, et parmi les statuts fixés par l'établissement (409 sinon).
     *
     * @return array<string, mixed>
     */
    private function eligibleProfile(string $tenantId, string $establishmentId, string $profileId): array
    {
        $profile = $this->staff->activeProfile($tenantId, $establishmentId, $profileId);
        $status = (string) $profile['employment_status'];
        if ($status === StaffService::CIVIL_SERVANT || !in_array($status, $this->eligibleStatuses($tenantId, $establishmentId), true)) {
            throw HttpException::conflict('Ce statut du personnel n\'est pas éligible à la paie simplifiée.');
        }

        return $profile;
    }
}

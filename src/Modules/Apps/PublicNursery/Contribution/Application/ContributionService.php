<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Contribution\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Contribution exigible de la maternelle publique (circulaire du MINEDUB, visa MINFI du 7 mars 2022) : montant, nombre de tranches et
 * quotas de répartition PARAMÉTRÉS (`public_nursery.contribution.*`, valeur de départ 7 500 FCFA, une tranche). Chaque encaissement
 * produit un REÇU numéroté émis par le module Documents (QR de vérification, contenu figé) ; la souche reste en base, y compris après
 * annulation motivée (le reçu est alors révoqué). Une seule contribution valide par enfant et par année (paramètre `installments`).
 * La contribution ne conditionne jamais l'accueil de l'enfant : aucun blocage n'est posé ici.
 */
final class ContributionService
{
    public const TABLE = 'pn_contributions';
    public const AMOUNT = 'public_nursery.contribution.amount_xaf';
    public const INSTALLMENTS = 'public_nursery.contribution.installments';
    public const QUOTAS = 'public_nursery.contribution.quotas';
    public const SOURCE = 'public_nursery.contribution.source';
    public const DOCUMENT_TYPE = 'pn_contribution_receipt';

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly SettingsReader $settings,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly OrgUnitTree $tree,
        private readonly CurrentPeriod $periods,
        private readonly DocumentIssuer $documents,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * Paramètres en vigueur pour l'établissement ; montant ou tranches absents : la perception est impossible (422), jamais inventée.
     *
     * @return array{amount_xaf: ?int, installments: ?int, quotas: list<array{code: string, label: string, percent: float}>, source: ?string}
     */
    public function rules(string $tenantId, string $establishmentId): array
    {
        $amount = $this->settings->value($tenantId, $establishmentId, self::AMOUNT);
        $installments = $this->settings->value($tenantId, $establishmentId, self::INSTALLMENTS);
        $source = $this->settings->value($tenantId, $establishmentId, self::SOURCE);

        return [
            'amount_xaf' => is_int($amount) && $amount > 0 ? $amount : null,
            'installments' => is_int($installments) && $installments > 0 ? $installments : null,
            'quotas' => self::quotas($this->settings->value($tenantId, $establishmentId, self::QUOTAS)),
            'source' => is_string($source) && $source !== '' ? $source : null,
        ];
    }

    /**
     * Encaissement contre reçu.
     *
     * @param array{learner_id: string, payer_name: string, paid_on: string, locale: string} $data
     * @return array<string, mixed>
     */
    public function collect(Principal $principal, string $establishmentId, array $data, string $now): array
    {
        $tenantId = $principal->tenantId;
        $rules = $this->rules($tenantId, $establishmentId);
        if ($rules['amount_xaf'] === null || $rules['installments'] === null) {
            throw HttpException::validation(['amount_xaf' => ['Montant ou nombre de tranches de la contribution non paramétré pour cet établissement.']]);
        }
        $enrollment = $this->enrollment($tenantId, $establishmentId, $data['learner_id']);
        $paid = $this->validFor($tenantId, $establishmentId, $data['learner_id'], $enrollment->academicYearId);
        if (count($paid) >= $rules['installments']) {
            throw HttpException::conflict('Contribution déjà perçue pour cet enfant cette année (' . count($paid) . ' tranche(s) sur ' . $rules['installments'] . ').');
        }
        $amount = intdiv($rules['amount_xaf'], $rules['installments']) + (count($paid) === $rules['installments'] - 1 ? $rules['amount_xaf'] % $rules['installments'] : 0);
        $class = $enrollment->structureId !== null ? $this->tree->structure($tenantId, $establishmentId, $enrollment->structureId) : null;
        $document = $this->documents->issue($tenantId, $establishmentId, self::DOCUMENT_TYPE, $data['learner_id'], [
            'academic_year' => $this->yearLabel($tenantId, $establishmentId, $enrollment->academicYearId, substr($now, 0, 10)),
            'class_name' => $class !== null ? $class->name : '', 'amount_xaf' => number_format($amount, 0, ',', ' '), 'payer_name' => $data['payer_name'],
            'paid_on' => $data['paid_on'], 'source' => $rules['source'] ?? '',
        ], $principal->userId, $data['locale']);
        $id = (string) new Ulid();
        try {
            $this->connections->pdo()->prepare(
                'INSERT INTO pn_contributions (id, tenant_id, establishment_id, learner_id, academic_year_id, structure_id, amount_xaf, payer_name, paid_on, receipt_number, document_id, verification_token, quotas_json, status, collected_by, cancel_reason, cancelled_by, cancelled_at, created_at)'
                . " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'valid', ?, NULL, NULL, NULL, ?)",
            )->execute([
                $id, $tenantId, $establishmentId, $data['learner_id'], $enrollment->academicYearId, $enrollment->structureId, $amount, $data['payer_name'], $data['paid_on'],
                $document->number, $document->id, $document->verificationToken, json_encode(self::split($amount, $rules['quotas']), JSON_THROW_ON_ERROR), $principal->userId, $now,
            ]);
        } catch (\Throwable $e) {
            $this->documents->revoke($tenantId, $document->id, 'Encaissement non enregistré.', $principal->userId);

            throw $e;
        }
        $this->audit->append($tenantId, $principal->userId, 'public_nursery.contribution.collected', 'contribution', $id, null, ['learner_id' => $data['learner_id'], 'amount_xaf' => $amount, 'receipt' => $document->number]);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> annulation motivée : le reçu est révoqué, la souche reste */
    public function cancel(Principal $principal, string $establishmentId, string $id, string $reason, string $now): array
    {
        $row = $this->row($principal->tenantId, $establishmentId, $id);
        if ((string) $row['status'] !== 'valid') {
            throw HttpException::conflict('Reçu déjà annulé.');
        }
        $this->documents->revoke($principal->tenantId, (string) $row['document_id'], 'Reçu annulé : ' . $reason, $principal->userId);
        $this->connections->pdo()->prepare("UPDATE pn_contributions SET status = 'cancelled', cancel_reason = ?, cancelled_by = ?, cancelled_at = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?")
            ->execute([mb_substr($reason, 0, 255), $principal->userId, $now, $principal->tenantId, $establishmentId, $id]);
        $this->audit->append($principal->tenantId, $principal->userId, 'public_nursery.contribution.cancelled', 'contribution', $id, ['status' => 'valid'], ['status' => 'cancelled', 'reason' => $reason]);

        return $this->get($principal->tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->resource($this->row($tenantId, $establishmentId, $id));
    }

    /**
     * Souches (reçus valides et annulés), les plus récentes d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public function stubs(string $tenantId, string $establishmentId, ?string $status, ?string $learnerId): array
    {
        $sql = 'SELECT * FROM pn_contributions WHERE tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        foreach (['status' => $status, 'learner_id' => $learnerId] as $column => $value) {
            if ($value !== null) {
                $sql .= ' AND ' . $column . ' = ?';
                $params[] = $value;
            }
        }
        $statement = $this->connections->pdo()->prepare($sql . ' ORDER BY created_at DESC, id DESC');
        $statement->execute($params);

        return array_map($this->resource(...), array_values(array_filter($statement->fetchAll(PDO::FETCH_ASSOC), 'is_array')));
    }

    /**
     * Bilan de l'établissement : attendu, perçu, répartition par quota (paramètre), enfants inscrits sans contribution.
     *
     * @return array<string, mixed>
     */
    public function summary(string $tenantId, string $establishmentId): array
    {
        $rules = $this->rules($tenantId, $establishmentId);
        $learners = $this->enrollments->personIdsInEstablishment($tenantId, $establishmentId);
        $paidBy = [];
        $collected = 0;
        $valid = 0;
        $cancelled = 0;
        foreach ($this->stubs($tenantId, $establishmentId, null, null) as $stub) {
            if ($stub['status'] === 'valid') {
                $paidBy[(string) $stub['learner_id']] = true;
                $collected += (int) $stub['amount_xaf'];
                ++$valid;
            } else {
                ++$cancelled;
            }
        }
        $unpaid = array_values(array_filter($learners, static fn (string $id): bool => !isset($paidBy[$id])));

        return [
            'rules' => $rules,
            'enrolled' => count($learners),
            'paid' => count($learners) - count($unpaid),
            'unpaid' => count($unpaid),
            'expected_xaf' => $rules['amount_xaf'] === null ? null : $rules['amount_xaf'] * count($learners),
            'collected_xaf' => $collected,
            'receipts_valid' => $valid,
            'receipts_cancelled' => $cancelled,
            'split' => self::split($collected, $rules['quotas']),
            'quotas_configured' => $rules['quotas'] !== [],
        ];
    }

    /**
     * Enfants inscrits sans contribution valide (liste nominative : direction seulement).
     *
     * @return list<array{learner_id: string, name: string}>
     */
    public function unpaid(string $tenantId, string $establishmentId): array
    {
        $paid = [];
        foreach ($this->stubs($tenantId, $establishmentId, 'valid', null) as $stub) {
            $paid[(string) $stub['learner_id']] = true;
        }
        $out = [];
        foreach ($this->enrollments->personIdsInEstablishment($tenantId, $establishmentId) as $learnerId) {
            if (!isset($paid[$learnerId])) {
                $out[] = ['learner_id' => $learnerId, 'name' => $this->people->find($tenantId, $learnerId)?->fullName() ?? ''];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * Répartition d'un montant selon les quotas (pourcentages paramétrés) ; le reste des arrondis est affiché, jamais réparti d'office.
     *
     * @param list<array{code: string, label: string, percent: float}> $quotas
     * @return array{lines: list<array{code: string, label: string, percent: float, amount_xaf: int}>, unallocated_xaf: int}
     */
    public static function split(int $amount, array $quotas): array
    {
        $lines = [];
        $allocated = 0;
        foreach ($quotas as $quota) {
            $share = intdiv($amount * (int) round($quota['percent'] * 100), 10000);
            $allocated += $share;
            $lines[] = $quota + ['amount_xaf' => $share];
        }

        return ['lines' => $lines, 'unallocated_xaf' => $amount - $allocated];
    }

    /** @return list<array{code: string, label: string, percent: float}> quotas valides (code, libellé, pourcentage) ; total au plus 100 */
    public static function quotas(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        $total = 0.0;
        foreach ($value as $quota) {
            if (!is_array($quota) || !is_string($quota['code'] ?? null) || !is_string($quota['label'] ?? null) || !is_numeric($quota['percent'] ?? null)) {
                continue;
            }
            $percent = (float) $quota['percent'];
            if ($percent <= 0 || $total + $percent > 100.0001) {
                continue;
            }
            $total += $percent;
            $out[] = ['code' => $quota['code'], 'label' => $quota['label'], 'percent' => $percent];
        }

        return $out;
    }

    private function enrollment(string $tenantId, string $establishmentId, string $learnerId): EnrollmentInfo
    {
        foreach ($this->enrollments->forPerson($tenantId, $learnerId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true)) {
                return $enrollment;
            }
        }

        throw HttpException::notFound('Enfant non inscrit dans cette école.');
    }

    /** @return list<array<string, mixed>> */
    private function validFor(string $tenantId, string $establishmentId, string $learnerId, string $yearId): array
    {
        $statement = $this->connections->pdo()->prepare("SELECT id FROM pn_contributions WHERE tenant_id = ? AND establishment_id = ? AND learner_id = ? AND academic_year_id = ? AND status = 'valid'");
        $statement->execute([$tenantId, $establishmentId, $learnerId, $yearId]);

        return array_values(array_filter($statement->fetchAll(PDO::FETCH_ASSOC), 'is_array'));
    }

    private function yearLabel(string $tenantId, string $establishmentId, string $yearId, string $day): string
    {
        foreach ($this->periods->allAt($tenantId, $establishmentId, $day) as $period) {
            if ($period->academicYearId === $yearId && $period->academicYearLabel !== null) {
                return $period->academicYearLabel;
            }
        }

        return $yearId;
    }

    /** @return array<string, mixed> */
    private function row(string $tenantId, string $establishmentId, string $id): array
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM pn_contributions WHERE tenant_id = ? AND establishment_id = ? AND id = ?');
        $statement->execute([$tenantId, $establishmentId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : throw HttpException::notFound('Reçu introuvable.');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function resource(array $row): array
    {
        $split = json_decode((string) $row['quotas_json'], true);

        return [
            'id' => (string) $row['id'], 'learner_id' => (string) $row['learner_id'], 'learner_name' => $this->people->find((string) $row['tenant_id'], (string) $row['learner_id'])?->fullName(),
            'academic_year_id' => (string) $row['academic_year_id'], 'amount_xaf' => (int) $row['amount_xaf'], 'payer_name' => (string) $row['payer_name'],
            'paid_on' => (string) $row['paid_on'], 'receipt_number' => (string) $row['receipt_number'], 'document_id' => (string) $row['document_id'],
            'verification_path' => '/api/v1/public/verify/' . (string) $row['verification_token'], 'split' => is_array($split) ? $split : null,
            'status' => (string) $row['status'], 'cancel_reason' => $row['cancel_reason'] !== null ? (string) $row['cancel_reason'] : null,
            'cancelled_at' => $row['cancelled_at'] !== null ? (string) $row['cancelled_at'] : null, 'created_at' => (string) $row['created_at'],
        ];
    }
}

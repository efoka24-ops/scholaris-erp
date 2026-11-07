<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Councils\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Primary\Grades\Contracts\SheetProgress;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\ClassRoster;
use Scholaris\Modules\Apps\Primary\Support\PrimaryClock;
use Scholaris\Modules\Apps\Primary\Support\PrimarySettings;
use Scholaris\Modules\Apps\Primary\Support\ScaleProvider;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodInfo;

/**
 * Conseil des maîtres de fin de trimestre (EX-PRI-25, 27, RG-PRI-6, 17, 18), présidé par la direction (Q8, à confirmer) :
 *  - ouverture : une ligne par élève inscrit, préremplie par CouncilLineBuilder (moyennes et rang chiffrés facultatifs, absences en
 *    demi-journées, conduite, synthèse des compétences, remédiation, redoublant) ; au dernier trimestre, récapitulatif annuel et
 *    proposition de fin d'année (fin de cycle au niveau final, sinon note de passage des règles du niveau) ;
 *  - séance : appréciation, conduite, remédiation et décision modifiables ; une décision différente de la proposition exige un motif ;
 *  - validation (présidence seulement) : feuilles de compétences verrouillées (paramètre), décision arrêtée pour chaque élève au
 *    dernier trimestre, procès-verbal ; les bulletins s'émettent ensuite (ReportCardService).
 * La période est authentifiée par le socle (CurrentPeriod) : identifiant, dates et nature doivent concorder.
 */
final class CouncilService
{
    public const COUNCILS = 'pri_councils';
    public const LINES = 'pri_council_lines';
    public const READ = ['primary.council.read', 'primary.council.preside', 'assessment.validate'];
    public const PREPARE = ['primary.council.preside', 'assessment.validate'];
    public const PRESIDE = ['primary.council.preside'];
    public const DECISIONS = ['admitted', 'adjourned', 'cycle_end'];
    private const EDITABLE = ['appraisal', 'conduct_code', 'decision', 'reason', 'remediation_flag', 'remediation_note'];

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly ClassRoster $classes,
        private readonly CouncilLineBuilder $builder,
        private readonly PrimarySettings $settings,
        private readonly ScaleProvider $scales,
        private readonly CurrentPeriod $periods,
        private readonly SheetProgress $sheets,
        private readonly PrimaryClock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(Principal $principal, string $establishmentId, ?string $classId, ?string $yearId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $where = [];
        if ($classId !== null) {
            $where['class_structure_id'] = $classId;
        }
        if ($yearId !== null) {
            $where['academic_year_id'] = $yearId;
        }
        $out = [];
        foreach ($this->store->select(self::COUNCILS, $principal->tenantId, $establishmentId, $where, 'starts_on, class_structure_id', 1000) as $row) {
            if ($perimeter->coversClass((string) $row['class_structure_id'])) {
                $out[] = self::councilResource($row) + ['lines_count' => $this->store->count(self::LINES, $principal->tenantId, $establishmentId, ['council_id' => (string) $row['id']])];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $input class_id, period_id, starts_on, period_label, held_on, sequences [{id, starts_on}]
     * @return array<string, mixed>
     */
    public function open(Principal $principal, string $establishmentId, array $input): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::PREPARE);
        $t = $principal->tenantId;
        $classId = (string) $input['class_id'];
        $this->access->assertWritableClass($principal, $perimeter, $classId, self::COUNCILS, $classId);
        $context = $this->classes->resolve($t, $establishmentId, $classId);
        if ($context['learner_ids'] === []) {
            throw HttpException::validation(['class_id' => ['Aucun élève inscrit dans cette classe.']]);
        }
        $period = $this->period($t, $establishmentId, (string) $input['period_id'], (string) $input['starts_on']);
        if ($period->kind !== 'trimester') {
            throw HttpException::validation(['period_id' => ['Le conseil du primaire se tient par trimestre (Q2, Q4 : à confirmer).']]);
        }
        if ($context['year_id'] !== null && $context['year_id'] !== $period->academicYearId) {
            throw HttpException::validation(['period_id' => ['Cette période n\'est pas dans l\'année des inscriptions de la classe.']]);
        }
        $children = [];
        foreach ((array) ($input['sequences'] ?? []) as $i => $sequence) {
            $child = $this->period($t, $establishmentId, (string) ($sequence['id'] ?? ''), (string) ($sequence['starts_on'] ?? ''), "sequences.$i");
            if ($child->parentId !== $period->id) {
                throw HttpException::validation(["sequences.$i" => ['Cette séquence n\'appartient pas au trimestre du conseil.']]);
            }
            $children[] = $child->id;
        }
        if ($this->store->select(self::COUNCILS, $t, $establishmentId, ['class_structure_id' => $classId, 'period_id' => $period->id], 'id', 1) !== []) {
            throw HttpException::conflict('Un conseil existe déjà pour cette classe et ce trimestre : ouvrez-le plutôt que d\'en créer un second.');
        }
        $final = $period->ordinal >= $this->settings->int($t, $establishmentId, 'primary.trimesters');
        $label = isset($input['period_label']) && is_string($input['period_label']) ? $input['period_label'] : 'Trimestre ' . $period->ordinal;
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $council = [
            'id' => $id, 'class_structure_id' => $classId, 'level_structure_id' => $context['level_id'], 'academic_year_id' => $period->academicYearId,
            'year_label' => $period->academicYearLabel, 'period_id' => $period->id, 'period_label' => $label, 'period_kind' => $period->kind,
            'term_ordinal' => $period->ordinal, 'is_final_term' => $final ? 1 : 0, 'child_period_ids' => json_encode($children, JSON_THROW_ON_ERROR),
            'starts_on' => substr($period->startsOn, 0, 10), 'ends_on' => substr($period->endsOn, 0, 10), 'held_on' => $input['held_on'] ?? null,
            'status' => 'open', 'numeric_enabled' => 0, 'rule_version' => null, 'summary' => null, 'minutes' => null, 'created_by' => $principal->userId,
            'validated_by' => null, 'validated_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ];
        $this->store->transaction(function () use ($t, $establishmentId, $council, $context, $now): void {
            $this->store->insert(self::COUNCILS, $t, $establishmentId, $council);
            $built = $this->builder->build($t, $establishmentId, $council, $context['learner_ids']);
            foreach ($built['lines'] as $personId => $values) {
                $this->store->insert(self::LINES, $t, $establishmentId, $values + [
                    'id' => (string) new Ulid(), 'council_id' => $council['id'], 'person_id' => $personId, 'decision' => $values['proposal'],
                    'reason' => null, 'edited_fields' => null, 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $this->store->update(self::COUNCILS, $t, $establishmentId, [
                'numeric_enabled' => $built['numeric'] ? 1 : 0, 'rule_version' => $built['rule_version'], 'summary' => $this->summaryJson($t, $establishmentId, (string) $council['id']),
            ], ['id' => (string) $council['id']]);
        });
        $this->access->audit($principal, 'primary.council.opened', self::COUNCILS, $id, null, ['class_structure_id' => $classId, 'period_id' => $period->id]);

        return $this->find($principal, $establishmentId, $id);
    }

    /** @return array<string, mixed> conseil, lignes avec noms, échelles et libellés */
    public function find(Principal $principal, string $establishmentId, string $id): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $council = $this->store->findOrFail(self::COUNCILS, $principal->tenantId, $establishmentId, $id, 'Conseil introuvable.');
        $this->access->assertReadableClass($perimeter, (string) $council['class_structure_id']);

        return $this->present($principal->tenantId, $establishmentId, $council);
    }

    /**
     * @param array<string, mixed> $changes appraisal, conduct_code, decision, reason, remediation_flag, remediation_note
     * @return array<string, mixed>
     */
    public function updateLine(Principal $principal, string $establishmentId, string $councilId, string $lineId, array $changes): array
    {
        $t = $principal->tenantId;
        $council = $this->openCouncil($principal, $establishmentId, $councilId, self::PREPARE);
        $line = $this->store->select(self::LINES, $t, $establishmentId, ['id' => $lineId, 'council_id' => $councilId], 'id', 1)[0] ?? throw HttpException::notFound('Ligne du conseil introuvable.');
        if (array_key_exists('conduct_code', $changes) && $changes['conduct_code'] !== null && !in_array($changes['conduct_code'], $this->scales->codes($t, $establishmentId, true), true)) {
            throw HttpException::validation(['conduct_code' => ['Valeur possible : ' . implode(', ', $this->scales->codes($t, $establishmentId, true)) . '.']]);
        }
        if (array_key_exists('decision', $changes) && (int) $council['is_final_term'] !== 1 && $changes['decision'] !== null) {
            throw HttpException::validation(['decision' => ['La décision de fin d\'année se prend au conseil du dernier trimestre.']]);
        }
        $decision = array_key_exists('decision', $changes) ? $changes['decision'] : $line['decision'];
        $reason = array_key_exists('reason', $changes) ? $changes['reason'] : $line['reason'];
        if ($decision !== null && $decision !== $line['proposal'] && $line['proposal'] !== null && ($reason === null || trim((string) $reason) === '')) {
            throw HttpException::validation(['reason' => ['La décision diffère de la proposition calculée : indiquez le motif.']]);
        }
        if ($decision === $line['proposal']) {
            $reason = null;
        }
        $set = ['decision' => $decision, 'reason' => $reason];
        $edited = array_filter(explode(',', (string) ($line['edited_fields'] ?? '')));
        foreach (self::EDITABLE as $field) {
            if (array_key_exists($field, $changes) && !in_array($field, ['decision', 'reason'], true)) {
                $set[$field] = is_bool($changes[$field]) ? ($changes[$field] ? 1 : 0) : $changes[$field];
            }
            if (array_key_exists($field, $changes)) {
                $edited[] = $field;
            }
        }
        $set['edited_fields'] = implode(',', array_values(array_unique($edited)));
        $set['updated_by'] = $principal->userId;
        $set['updated_at'] = $this->clock->now();
        $this->store->update(self::LINES, $t, $establishmentId, $set, ['id' => $lineId]);
        $this->store->update(self::COUNCILS, $t, $establishmentId, ['summary' => $this->summaryJson($t, $establishmentId, $councilId), 'updated_at' => $set['updated_at']], ['id' => $councilId]);
        $this->access->audit($principal, 'primary.council.line_updated', self::LINES, $lineId, array_intersect_key($line, $set), $set);

        return $this->present($t, $establishmentId, $this->store->findOrFail(self::COUNCILS, $t, $establishmentId, $councilId));
    }

    /**
     * Recalcule les valeurs calculées sans écraser ce que le conseil a saisi (champs modifiés, décision différente de la proposition).
     *
     * @return array<string, mixed>
     */
    public function refresh(Principal $principal, string $establishmentId, string $councilId): array
    {
        $t = $principal->tenantId;
        $council = $this->openCouncil($principal, $establishmentId, $councilId, self::PREPARE);
        $context = $this->classes->resolve($t, $establishmentId, (string) $council['class_structure_id']);
        $existing = [];
        foreach ($this->store->select(self::LINES, $t, $establishmentId, ['council_id' => $councilId], 'id', 5000) as $line) {
            $existing[(string) $line['person_id']] = $line;
        }
        $built = $this->builder->build($t, $establishmentId, $council, array_values(array_unique([...array_keys($existing), ...$context['learner_ids']])));
        $now = $this->clock->now();
        $this->store->transaction(function () use ($t, $establishmentId, $councilId, $built, $existing, $now): void {
            foreach ($built['lines'] as $personId => $values) {
                $line = $existing[$personId] ?? null;
                if ($line === null) {
                    $this->store->insert(self::LINES, $t, $establishmentId, $values + [
                        'id' => (string) new Ulid(), 'council_id' => $councilId, 'person_id' => $personId, 'decision' => $values['proposal'], 'reason' => null,
                        'edited_fields' => null, 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    continue;
                }
                $edited = array_filter(explode(',', (string) ($line['edited_fields'] ?? '')));
                foreach ($edited as $field) {
                    unset($values[$field]);
                }
                if ($line['decision'] === $line['proposal'] || $line['decision'] === null) {
                    $values['decision'] = $values['proposal'];
                    $values['reason'] = null;
                }
                $values['updated_at'] = $now;
                $this->store->update(self::LINES, $t, $establishmentId, $values, ['id' => (string) $line['id']]);
            }
            $this->store->update(self::COUNCILS, $t, $establishmentId, [
                'numeric_enabled' => $built['numeric'] ? 1 : 0, 'rule_version' => $built['rule_version'],
                'summary' => $this->summaryJson($t, $establishmentId, $councilId), 'updated_at' => $now,
            ], ['id' => $councilId]);
        });

        return $this->present($t, $establishmentId, $this->store->findOrFail(self::COUNCILS, $t, $establishmentId, $councilId));
    }

    /** @return array<string, mixed> */
    public function validate(Principal $principal, string $establishmentId, string $councilId): array
    {
        $t = $principal->tenantId;
        $council = $this->openCouncil($principal, $establishmentId, $councilId, self::PRESIDE);
        $classId = (string) $council['class_structure_id'];
        if ($this->settings->bool($t, $establishmentId, 'primary.council.require_locked_sheets')) {
            $states = $this->sheets->statesByPeriod($t, $establishmentId, $classId);
            $marks = $this->sheets->marksByPeriod($t, $establishmentId, $classId);
            $open = [];
            foreach ([(string) $council['period_id'], ...self::children($council)] as $periodId) {
                if (($marks[$periodId] ?? 0) > 0 && ($states[$periodId] ?? 'open') === 'open') {
                    $open[] = $periodId;
                }
            }
            if ($open !== []) {
                throw HttpException::conflict('La feuille de compétences n\'est pas verrouillée pour cette période : faites-la verrouiller avant de valider le conseil.');
            }
        }
        $lines = $this->store->select(self::LINES, $t, $establishmentId, ['council_id' => $councilId], 'id', 5000);
        if ((int) $council['is_final_term'] === 1) {
            $missing = array_filter($lines, static fn (array $l): bool => $l['decision'] === null || $l['decision'] === '');
            if ($missing !== []) {
                throw HttpException::validation(['decision' => [sprintf('%d élève(s) sans décision de fin d\'année : arrêtez chaque décision avant de valider.', count($missing))]]);
            }
        }
        $now = $this->clock->now();
        $this->store->update(self::COUNCILS, $t, $establishmentId, [
            'status' => 'validated', 'validated_by' => $principal->userId, 'validated_at' => $now, 'updated_at' => $now,
            'summary' => $this->summaryJson($t, $establishmentId, $councilId), 'minutes' => $this->minutes($t, $establishmentId, $council, $lines),
        ], ['id' => $councilId]);
        $this->access->audit($principal, 'primary.council.validated', self::COUNCILS, $councilId, ['status' => 'open'], ['status' => 'validated']);

        return $this->present($t, $establishmentId, $this->store->findOrFail(self::COUNCILS, $t, $establishmentId, $councilId));
    }

    /**
     * Réouverture d'un conseil validé tant qu'aucun bulletin n'a été émis (sinon : rectificatif du bulletin).
     *
     * @return array<string, mixed>
     */
    public function reopen(Principal $principal, string $establishmentId, string $councilId, string $reason): array
    {
        $t = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, self::PRESIDE);
        $council = $this->store->findOrFail(self::COUNCILS, $t, $establishmentId, $councilId, 'Conseil introuvable.');
        $this->access->assertWritableClass($principal, $perimeter, (string) $council['class_structure_id'], self::COUNCILS, $councilId);
        if ($council['status'] !== 'validated') {
            throw HttpException::conflict('Seul un conseil validé peut être rouvert.');
        }
        if ($this->store->select('pri_report_cards', $t, $establishmentId, ['council_id' => $councilId], 'id', 1, "status <> 'failed'") !== []) {
            throw HttpException::conflict('Des bulletins ont été émis pour ce conseil : corrigez-les par un rectificatif.');
        }
        $now = $this->clock->now();
        $this->store->update(self::COUNCILS, $t, $establishmentId, ['status' => 'open', 'validated_by' => null, 'validated_at' => null, 'updated_at' => $now], ['id' => $councilId]);
        $this->access->audit($principal, 'primary.council.reopened', self::COUNCILS, $councilId, ['status' => 'validated'], ['status' => 'open', 'reason' => $reason]);

        return $this->present($t, $establishmentId, $this->store->findOrFail(self::COUNCILS, $t, $establishmentId, $councilId));
    }

    /**
     * @param array<string, mixed> $council
     * @return array<string, mixed>
     */
    public function present(string $tenantId, string $establishmentId, array $council): array
    {
        $lines = $this->store->select(self::LINES, $tenantId, $establishmentId, ['council_id' => (string) $council['id']], 'id', 5000);
        $names = $this->classes->names($tenantId, array_map(static fn (array $l): string => (string) $l['person_id'], $lines));
        $out = array_map(static fn (array $l): array => self::lineResource($l) + ['name' => $names[(string) $l['person_id']] ?? (string) $l['person_id']], $lines);
        usort($out, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
        $labels = [];
        foreach (self::DECISIONS as $code) {
            $labels[] = ['code' => $code, 'label_fr' => $this->settings->label($tenantId, $establishmentId, 'primary.council.decision_labels', $code), 'label_en' => $this->settings->label($tenantId, $establishmentId, 'primary.council.decision_labels', $code, 'en')];
        }
        $class = $this->classes->resolve($tenantId, $establishmentId, (string) $council['class_structure_id'])['class'];

        return self::councilResource($council) + [
            'class_name' => $class->name,
            'show_rank' => $this->settings->bool($tenantId, $establishmentId, 'primary.report_card.show_rank'),
            'conduct_scale' => $this->scales->conduct($tenantId, $establishmentId),
            'competency_scale' => $this->scales->qualitative($tenantId, $establishmentId),
            'decisions' => $labels,
            'lines' => $out,
        ];
    }

    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    private function openCouncil(Principal $principal, string $establishmentId, string $councilId, array $permissions): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, $permissions);
        $council = $this->store->findOrFail(self::COUNCILS, $principal->tenantId, $establishmentId, $councilId, 'Conseil introuvable.');
        if (!$perimeter->coversClass((string) $council['class_structure_id'])) {
            $this->access->assertReadableClass($this->access->perimeter($principal, $establishmentId, self::READ), (string) $council['class_structure_id']);
            $this->access->assertWritableClass($principal, $perimeter, (string) $council['class_structure_id'], self::COUNCILS, $councilId);
        }
        if ($council['status'] !== 'open') {
            throw HttpException::conflict('Ce conseil est validé : ses décisions ne se modifient plus (réouverture avant émission des bulletins, rectificatif ensuite).');
        }

        return $council;
    }

    private function period(string $tenantId, string $establishmentId, string $periodId, string $startsOn, string $field = 'period_id'): PeriodInfo
    {
        foreach ($this->periods->allAt($tenantId, $establishmentId, $startsOn) as $info) {
            if ($info->id === $periodId && substr($info->startsOn, 0, 10) === substr($startsOn, 0, 10)) {
                return $info;
            }
        }

        throw HttpException::validation([$field => ['Période introuvable dans cet établissement (identifiant et date de début doivent concorder).']]);
    }

    /**
     * @param array<string, mixed> $council
     * @return list<string>
     */
    public static function children(array $council): array
    {
        $decoded = json_decode((string) ($council['child_period_ids'] ?? '[]'), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private function summaryJson(string $tenantId, string $establishmentId, string $councilId): string
    {
        $lines = $this->store->select(self::LINES, $tenantId, $establishmentId, ['council_id' => $councilId], 'id', 5000);
        $averages = array_values(array_filter(array_map(static fn (array $l): ?float => $l['average_value'] === null ? null : (float) $l['average_value'], $lines), static fn (?float $v): bool => $v !== null));
        $decisions = [];
        foreach ($lines as $line) {
            if ($line['decision'] !== null) {
                $decisions[(string) $line['decision']] = ($decisions[(string) $line['decision']] ?? 0) + 1;
            }
        }

        return json_encode([
            'learners' => count($lines),
            'with_average' => count($averages),
            'class_average' => $averages === [] ? null : round(array_sum($averages) / count($averages), 2),
            'decisions' => $decisions,
            'remediation' => count(array_filter($lines, static fn (array $l): bool => (int) $l['remediation_flag'] === 1)),
            'absences_half_days' => array_sum(array_map(static fn (array $l): int => (int) $l['absences_half_days'], $lines)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $council
     * @param list<array<string, mixed>> $lines
     */
    private function minutes(string $tenantId, string $establishmentId, array $council, array $lines): string
    {
        $class = $this->classes->resolve($tenantId, $establishmentId, (string) $council['class_structure_id'])['class'];
        $names = $this->classes->names($tenantId, array_map(static fn (array $l): string => (string) $l['person_id'], $lines));
        $out = [sprintf('Procès-verbal du conseil des maîtres · %s · %s', $class->name, (string) $council['period_label']), 'Date : ' . ($council['held_on'] ?? $this->clock->today()), sprintf('Effectif : %d', count($lines)), ''];
        foreach ($lines as $line) {
            $decision = $line['decision'] === null ? '' : ' · décision : ' . $this->settings->label($tenantId, $establishmentId, 'primary.council.decision_labels', (string) $line['decision']);
            $modified = $line['decision'] !== null && $line['proposal'] !== null && $line['decision'] !== $line['proposal'] ? ' (modifiée, motif : ' . (string) $line['reason'] . ')' : '';
            $out[] = sprintf('%s%s%s', $names[(string) $line['person_id']] ?? (string) $line['person_id'], $decision, $modified);
        }

        return implode("\n", $out);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function councilResource(array $row): array
    {
        $summary = json_decode((string) ($row['summary'] ?? 'null'), true);

        return [
            'id' => (string) $row['id'], 'class_structure_id' => (string) $row['class_structure_id'], 'level_structure_id' => $row['level_structure_id'] === null ? null : (string) $row['level_structure_id'],
            'academic_year_id' => (string) $row['academic_year_id'], 'year_label' => $row['year_label'] === null ? null : (string) $row['year_label'],
            'period_id' => (string) $row['period_id'], 'period_label' => (string) $row['period_label'], 'term_ordinal' => (int) $row['term_ordinal'],
            'is_final_term' => (int) $row['is_final_term'] === 1, 'starts_on' => (string) $row['starts_on'], 'ends_on' => (string) $row['ends_on'],
            'held_on' => $row['held_on'] === null ? null : (string) $row['held_on'], 'status' => (string) $row['status'],
            'numeric_enabled' => (int) $row['numeric_enabled'] === 1, 'summary' => is_array($summary) ? $summary : null,
            'minutes' => $row['minutes'] === null ? null : (string) $row['minutes'],
            'validated_at' => $row['validated_at'] === null ? null : (string) $row['validated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function lineResource(array $row): array
    {
        $decode = static function (mixed $json): mixed {
            $value = json_decode((string) ($json ?? 'null'), true);

            return is_array($value) ? $value : [];
        };

        return [
            'id' => (string) $row['id'], 'person_id' => (string) $row['person_id'],
            'average' => $row['average_value'] === null ? null : (float) $row['average_value'], 'rank' => $row['rank_in_class'] === null ? null : (int) $row['rank_in_class'],
            'class_size' => $row['class_size'] === null ? null : (int) $row['class_size'], 'subjects' => $decode($row['subjects_json']),
            'annual_average' => $row['annual_average'] === null ? null : (float) $row['annual_average'], 'annual_rank' => $row['annual_rank'] === null ? null : (int) $row['annual_rank'],
            'terms' => $decode($row['terms_json']), 'absences_half_days' => (int) $row['absences_half_days'], 'unjustified_half_days' => (int) $row['unjustified_half_days'],
            'lates' => (int) $row['lates'], 'conduct_code' => $row['conduct_code'] === null ? null : (string) $row['conduct_code'],
            'competencies' => $decode($row['competency_summary']), 'appraisal' => $row['appraisal'] === null ? null : (string) $row['appraisal'],
            'remediation_flag' => (int) $row['remediation_flag'] === 1, 'remediation_note' => $row['remediation_note'] === null ? null : (string) $row['remediation_note'],
            'repeater' => (int) $row['repeater'] === 1, 'proposal' => $row['proposal'] === null ? null : (string) $row['proposal'],
            'decision' => $row['decision'] === null ? null : (string) $row['decision'], 'reason' => $row['reason'] === null ? null : (string) $row['reason'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use Psr\Container\ContainerInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Assessment\Contracts\ActorDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\CouncilLineProvider;
use Scholaris\Modules\Socle\Assessment\Domain\RuleEngine;
use Scholaris\Modules\Socle\Assessment\Domain\RuleSet;
use Scholaris\Modules\Socle\Assessment\Infrastructure\CatalogRepository;
use Scholaris\Modules\Socle\Assessment\Infrastructure\Db;
use Scholaris\Modules\Socle\Assessment\Infrastructure\ResultRepository;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummaryProvider;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/**
 * Conseil de classe (T122) : proposition calculée par les règles, décision modifiable (motif obligatoire si elle diffère),
 * validation qui reporte les décisions, génère le procès-verbal et met les bulletins en file de génération.
 */
final class CouncilService
{
    public function __construct(
        private readonly Db $db,
        private readonly ResultRepository $repo,
        private readonly CatalogRepository $catalog,
        private readonly ResultService $results,
        private readonly RuleEngine $engine,
        private readonly Roster $roster,
        private readonly AssessmentSettings $settings,
        private readonly AttendanceSummaryProvider $attendance,
        private readonly ActorDirectory $actors,
        private readonly MinutesIssuer $minutes,
        private readonly AuditLog $audit,
        private readonly AssessmentClock $clock,
        private readonly ContainerInterface $container,
    ) {
    }

    /**
     * @param array<string, mixed> $data structure_id, period_id, period_label, held_on, from, to
     * @return array<string, mixed>
     */
    public function open(Principal $principal, string $establishmentId, array $data): array
    {
        $tenantId = $principal->tenantId;
        $structureId = (string) $data['structure_id'];
        $periodId = (string) $data['period_id'];
        if ($this->repo->councilOf($tenantId, $establishmentId, $structureId, $periodId) !== null) {
            throw HttpException::conflict('Un conseil de classe existe déjà pour cette classe et cette période. Ouvrez-le plutôt que d\'en créer un second.');
        }
        if ($this->roster->students($tenantId, $establishmentId, $structureId) === []) {
            throw HttpException::validation(['structure_id' => ['Aucun élève inscrit dans cette classe pour cet établissement.']]);
        }

        $id = (string) new Ulid();
        $this->db->transaction(function () use ($principal, $establishmentId, $data, $tenantId, $structureId, $periodId, $id): void {
            $computed = $this->results->compute($tenantId, $establishmentId, $structureId, $periodId, $data['period_label'] ?? null);
            $personIds = array_map(static fn (array $row): string => (string) $row['person_id'], $computed['rows']);
            $prefills = $this->prefills($tenantId, $establishmentId, $structureId, $periodId, $personIds);
            $window = ['from' => (string) ($data['from'] ?? '0001-01-01'), 'to' => (string) ($data['to'] ?? '9999-12-31')];
            $this->repo->insert('class_councils', [
                'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'structure_id' => $structureId, 'period_id' => $periodId,
                'period_label' => $data['period_label'] ?? $periodId, 'status' => 'open', 'held_on' => $data['held_on'] ?? null,
                'rule_version' => $computed['rules']->version, 'summary' => '{}', 'minutes' => null, 'minutes_document_id' => null, 'minutes_status' => null,
                'created_by' => $principal->userId, 'validated_by' => null, 'created_at' => $this->clock->now(), 'validated_at' => null,
            ]);
            foreach ($computed['rows'] as $row) {
                $absences = $this->unjustified($tenantId, $establishmentId, (string) $row['person_id'], $window);
                $proposal = $this->engine->councilProposal($computed['rules'], $row['average'] === null ? null : (float) $row['average'], $absences);
                $this->repo->insert('council_lines', [
                    'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'council_id' => $id,
                    'person_id' => $row['person_id'], 'average' => $row['average'], 'rank_in_class' => $row['rank_in_class'],
                    'unjustified_absences' => $absences, 'conduct' => $prefills[$row['person_id']]['conduct'] ?? null, 'proposal' => $proposal, 'decision' => $proposal, 'reason' => null,
                    'appraisal' => $prefills[$row['person_id']]['appraisal'] ?? $row['mention'],
                ]);
            }
            $this->repo->update('class_councils', $tenantId, $id, ['summary' => $this->summaryJson($tenantId, $establishmentId, $id, $computed['rules'], $window)]);
        });

        return $this->find($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> conseil avec ses lignes (nom et matricule de l'élève inclus) */
    public function find(string $tenantId, string $establishmentId, string $id): array
    {
        $council = $this->repo->council($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Conseil de classe introuvable.');
        $names = [];
        foreach ($this->roster->students($tenantId, $establishmentId, (string) $council['structure_id']) as $student) {
            $names[$student['person_id']] = $student;
        }
        $lines = [];
        foreach ($this->repo->councilLines($tenantId, $id) as $line) {
            $line['name'] = $names[$line['person_id']]['name'] ?? null;
            $line['matricule'] = $names[$line['person_id']]['matricule'] ?? null;
            $lines[] = $line;
        }
        $council['lines'] = $lines;

        return $council;
    }

    /**
     * Décision du conseil pour un élève : motif obligatoire si elle diffère de la proposition.
     *
     * @param array<string, mixed> $data decision, reason, appraisal, conduct
     * @return array<string, mixed>
     */
    public function updateLine(string $tenantId, string $establishmentId, string $councilId, string $lineId, array $data): array
    {
        $council = $this->openCouncil($tenantId, $establishmentId, $councilId);
        $line = $this->repo->councilLine($tenantId, $councilId, $lineId) ?? throw HttpException::notFound('Ligne du conseil introuvable.');

        $decision = array_key_exists('decision', $data) ? $data['decision'] : $line['decision'];
        $reason = array_key_exists('reason', $data) ? $data['reason'] : $line['reason'];
        if ($decision !== $line['proposal'] && ($reason === null || trim((string) $reason) === '')) {
            throw HttpException::validation(['reason' => ['La décision diffère de la proposition calculée : indiquez le motif (par exemple « retards répétés »).']]);
        }
        if ($decision === $line['proposal']) {
            $reason = null;
        }
        $set = ['decision' => $decision, 'reason' => $reason];
        foreach (['appraisal', 'conduct'] as $field) {
            if (array_key_exists($field, $data)) {
                $set[$field] = $data[$field];
            }
        }
        $this->repo->update('council_lines', $tenantId, $lineId, $set);

        return $this->find($tenantId, $establishmentId, (string) $council['id']);
    }

    /**
     * Recalcule moyennes, rangs et propositions (après une correction de note, par exemple) sans écraser les décisions
     * déjà modifiées par le conseil.
     *
     * @return array<string, mixed>
     */
    public function refresh(string $tenantId, string $establishmentId, string $id): array
    {
        $council = $this->openCouncil($tenantId, $establishmentId, $id);
        $this->db->transaction(function () use ($tenantId, $establishmentId, $council): void {
            $this->recompute($tenantId, $establishmentId, $council);
        });

        return $this->find($tenantId, $establishmentId, $id);
    }

    /**
     * « Valider les décisions et générer le PV » : décisions reportées sur les résultats, procès-verbal généré,
     * bulletins mis en file de génération. Exige que la saisie des notes soit verrouillée.
     *
     * @return array<string, mixed>
     */
    public function validate(Principal $principal, string $establishmentId, string $id): array
    {
        $tenantId = $principal->tenantId;
        $council = $this->openCouncil($tenantId, $establishmentId, $id);
        $structureId = (string) $council['structure_id'];
        $periodId = (string) $council['period_id'];

        $assessments = $this->catalog->forSheet($tenantId, $establishmentId, $structureId, $periodId);
        if ($assessments === []) {
            throw HttpException::conflict('Aucune épreuve n\'existe pour cette classe et cette période : il n\'y a rien à valider.');
        }
        $lifecycle = $this->settings->lifecycle($tenantId, $establishmentId);
        $open = array_values(array_filter($assessments, fn (array $a): bool => $lifecycle->isEditable((string) $a['status'])));
        if ($open !== []) {
            throw HttpException::conflict(sprintf('La saisie n\'est pas verrouillée pour %d épreuve(s), par exemple « %s ». Faites valider les notes avant de valider le conseil.', count($open), $open[0]['title']));
        }

        $actorPerson = $this->actors->personIdOf($tenantId, $principal->userId);
        $this->db->transaction(function () use ($principal, $tenantId, $establishmentId, $council, $id): void {
            $rules = $this->recompute($tenantId, $establishmentId, $council);
            $now = $this->clock->now();
            $lines = $this->repo->councilLines($tenantId, $id);
            foreach ($lines as $line) {
                $result = $this->repo->resultOf($tenantId, $establishmentId, (string) $line['person_id'], (string) $council['period_id']);
                if ($result !== null) {
                    $this->repo->update('results', $tenantId, (string) $result['id'], ['decision' => $line['decision']]);
                }
                $this->repo->insert('report_card_requests', [
                    'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'council_id' => $id,
                    'person_id' => $line['person_id'], 'period_id' => $council['period_id'], 'status' => 'queued', 'document_id' => null,
                    'error_message' => null, 'created_at' => $now, 'processed_at' => null,
                ]);
            }
            $this->repo->update('class_councils', $tenantId, $id, [
                'status' => 'validated', 'validated_by' => $principal->userId, 'validated_at' => $now, 'rule_version' => $rules->version,
                'minutes' => $this->minutesText($tenantId, $establishmentId, $id),
            ]);
            $this->audit->append($tenantId, $principal->userId, 'assessment.council.validated', 'class_council', $id, ['status' => 'open'], ['status' => 'validated']);
        });

        $validated = $this->find($tenantId, $establishmentId, $id);
        $summary = (array) json_decode((string) $validated['summary'], true);
        $issued = $this->minutes->issue($tenantId, $establishmentId, 'class_council_minutes', $actorPerson, [
            'structure' => $structureId, 'period' => (string) $validated['period_label'], 'class_average' => $summary['class_average'] ?? null,
            'students' => count($validated['lines']), 'held_on' => $validated['held_on'],
        ], $principal->userId);
        $this->repo->update('class_councils', $tenantId, $id, ['minutes_status' => $issued['status'], 'minutes_document_id' => $issued['document_id']]);

        return $this->find($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    private function openCouncil(string $tenantId, string $establishmentId, string $id): array
    {
        $council = $this->repo->council($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Conseil de classe introuvable.');
        if ($council['status'] !== 'open') {
            throw HttpException::conflict('Ce conseil de classe est validé : ses décisions ne peuvent plus être modifiées.');
        }

        return $council;
    }

    /**
     * @param array<string, mixed> $council
     */
    private function recompute(string $tenantId, string $establishmentId, array $council): RuleSet
    {
        $computed = $this->results->compute($tenantId, $establishmentId, (string) $council['structure_id'], (string) $council['period_id']);
        $window = $this->window($council);
        $byPerson = [];
        foreach ($this->repo->councilLines($tenantId, (string) $council['id']) as $line) {
            $byPerson[(string) $line['person_id']] = $line;
        }
        foreach ($computed['rows'] as $row) {
            $line = $byPerson[(string) $row['person_id']] ?? null;
            if ($line === null) {
                continue;
            }
            $absences = $this->unjustified($tenantId, $establishmentId, (string) $row['person_id'], $window);
            $proposal = $this->engine->councilProposal($computed['rules'], $row['average'] === null ? null : (float) $row['average'], $absences);
            $set = ['average' => $row['average'], 'rank_in_class' => $row['rank_in_class'], 'unjustified_absences' => $absences, 'proposal' => $proposal];
            $prefill = $this->prefills($tenantId, $establishmentId, (string) $council['structure_id'], (string) $council['period_id'], [(string) $row['person_id']])[(string) $row['person_id']] ?? [];
            foreach (['conduct', 'appraisal'] as $field) {
                if (($line[$field] ?? null) === null && isset($prefill[$field])) {
                    $set[$field] = $prefill[$field];
                }
            }
            if ($line['decision'] === $line['proposal'] || $line['decision'] === null) {
                $set['decision'] = $proposal;
                $set['reason'] = null;
            }
            $this->repo->update('council_lines', $tenantId, (string) $line['id'], $set);
        }
        $this->repo->update('class_councils', $tenantId, (string) $council['id'], [
            'rule_version' => $computed['rules']->version,
            'summary' => $this->summaryJson($tenantId, $establishmentId, (string) $council['id'], $computed['rules'], $window),
        ]);

        return $computed['rules'];
    }

    /**
     * @param array<string, mixed> $council
     * @return array{from: string, to: string}
     */
    private function window(array $council): array
    {
        $summary = (array) json_decode((string) $council['summary'], true);

        return ['from' => (string) ($summary['from'] ?? '0001-01-01'), 'to' => (string) ($summary['to'] ?? '9999-12-31')];
    }

    /** @param array{from: string, to: string} $window */
    private function unjustified(string $tenantId, string $establishmentId, string $personId, array $window): int
    {
        return $this->attendance->forLearner($tenantId, $establishmentId, $personId, $window['from'], $window['to'])->unjustifiedAbsences;
    }

    /**
     * Indicateurs du conseil : moyenne de classe, effectif ≥ seuil, tableau d'honneur, avertissements, absences, moyennes par matière.
     *
     * @param array{from: string, to: string} $window
     */
    private function summaryJson(string $tenantId, string $establishmentId, string $councilId, RuleSet $rules, array $window): string
    {
        $lines = $this->repo->councilLines($tenantId, $councilId);
        $averages = [];
        $honor = 0;
        $warnings = 0;
        $absences = 0;
        foreach ($lines as $line) {
            $absences += (int) $line['unjustified_absences'];
            if ($line['average'] !== null) {
                $averages[] = (float) $line['average'];
            }
            $label = (string) ($line['decision'] ?? '');
            if ($label === 'Tableau d\'honneur' || $label === 'Félicitations') {
                ++$honor;
            }
            if (str_starts_with($label, 'Avertissement') || $label === 'Blâme') {
                ++$warnings;
            }
        }

        $council = $this->repo->council($tenantId, $establishmentId, $councilId) ?? [];
        $subjectTotals = [];
        foreach ($this->repo->resultsOf($tenantId, $establishmentId, (string) ($council['structure_id'] ?? ''), (string) ($council['period_id'] ?? '')) as $result) {
            foreach ((array) json_decode((string) $result['details'], true) as $detail) {
                if (is_array($detail) && isset($detail['subject_id']) && $detail['average'] !== null) {
                    $subjectTotals[(string) $detail['subject_id']]['name'] = $detail['name'] ?? null;
                    $subjectTotals[(string) $detail['subject_id']]['values'][] = (float) $detail['average'];
                }
            }
        }
        $bySubject = [];
        foreach ($subjectTotals as $subjectId => $info) {
            $bySubject[] = ['subject_id' => $subjectId, 'name' => $info['name'], 'average' => $this->engine->round($rules, array_sum($info['values']) / count($info['values']))];
        }

        return json_encode([
            'from' => $window['from'], 'to' => $window['to'],
            'class_average' => $averages === [] ? null : $this->engine->round($rules, array_sum($averages) / count($averages)),
            'pass_count' => count(array_filter($averages, static fn (float $a): bool => $a >= $rules->passMark)),
            'honor_roll' => $honor, 'warnings' => $warnings, 'unjustified_absences' => $absences, 'subject_averages' => $bySubject,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function minutesText(string $tenantId, string $establishmentId, string $id): string
    {
        $council = $this->find($tenantId, $establishmentId, $id);
        $summary = (array) json_decode((string) $council['summary'], true);
        $out = [
            sprintf('Procès-verbal du conseil de classe · classe %s · %s', $council['structure_id'], $council['period_label']),
            'Date : ' . ($council['held_on'] ?? 'non précisée'),
            sprintf('Effectif : %d · moyenne de classe : %s · élèves à la moyenne : %s', count($council['lines']), $summary['class_average'] ?? 'n/a', $summary['pass_count'] ?? 0),
            '',
        ];
        foreach ($council['lines'] as $line) {
            $modified = $line['decision'] !== $line['proposal'];
            $out[] = sprintf(
                '%s. %s · moyenne %s · décision : %s%s',
                $line['rank_in_class'] ?? '-',
                $line['name'] ?? $line['person_id'],
                $line['average'] ?? 'n/a',
                $line['decision'] ?? 'aucune',
                $modified ? ' (modifiée, motif : ' . $line['reason'] . ')' : '',
            );
        }

        return implode("\n", $out);
    }

    /**
     * @param list<string> $personIds
     * @return array<string, array<string, mixed>>
     */
    private function prefills(string $tenantId, string $establishmentId, string $structureId, string $periodId, array $personIds): array
    {
        $result = [];
        foreach (AssessmentExtensions::collect($this->container, AssessmentExtensions::COUNCIL_LINES, CouncilLineProvider::class) as $provider) {
            foreach ($provider->prefill($tenantId, $establishmentId, $structureId, $periodId, $personIds) as $personId => $values) {
                if (in_array($personId, $personIds, true)) {
                    $result[$personId] = array_merge($result[$personId] ?? [], $values);
                }
            }
        }

        return $result;
    }
}

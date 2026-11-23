<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumDirectory;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Grades\Domain\MarkValue;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Saisie des notes en grille (EX-NOT-02, API-09) : lot atomique depuis l'ordinateur ou le téléphone, synchronisation hors ligne
 * (client_uuid : un rejeu ne crée jamais de doublon ; version optimiste : un conflit est signalé, jamais résolu automatiquement),
 * import CSV au format du modèle fourni. RG-NOT-1 (barème, pas), codes d'absence (EX-NOT-03), saisie sur numéros d'anonymat tant
 * que l'anonymat n'est pas levé (EX-NOT-04). Hors de l'état de saisie, aucune note n'est modifiée (RG-NOT-2, TST-06) : seule une
 * demande de correction le permet. Chaque écriture ajoute une ligne à l'historique et au journal d'audit (avant/après).
 */
final class MarkService
{
    public const EVENTS = 'hi_grade_mark_events';

    public function __construct(
        private readonly Store $store,
        private readonly MarkPolicy $policy,
        private readonly AssessmentService $assessments,
        private readonly GradesSettings $settings,
        private readonly CurriculumDirectory $curricula,
        private readonly StudentDirectory $students,
        private readonly PersonDirectory $people,
        private readonly TeacherAssignments $teachers,
        private readonly GradesClock $clock,
    ) {
    }

    /**
     * Grille d'une évaluation : une ligne par étudiant (ou par copie anonyme), avec version pour la saisie hors ligne.
     *
     * @return array<string, mixed>
     */
    public function grid(Principal $principal, string $establishmentId, string $assessmentId): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->assessments->find($tenantId, $establishmentId, $assessmentId);
        $this->policy->assertReadable($principal, $establishmentId, $row);
        $schoolId = (string) $row['school_id'];
        $lifecycle = $this->settings->lifecycle($tenantId, $establishmentId, $schoolId);
        if ($lifecycle->isEditable((string) $row['status'])) {
            $this->assessments->syncRoster($tenantId, $establishmentId, $row);
        }
        $hidden = (int) $row['anonymous'] === 1 && $row['anonymity_lifted_at'] === null;
        $marks = $this->visibleMarks($principal, $establishmentId, $row);
        $names = [];
        if (!$hidden) {
            foreach ($this->people->findMany($tenantId, array_values(array_unique(array_map(static fn (array $m): string => (string) $m['person_id'], $marks)))) as $person) {
                $names[$person->id] = $person;
            }
        }
        $lines = [];
        foreach ($marks as $mark) {
            $person = $names[(string) $mark['person_id']] ?? null;
            $lines[] = [
                'mark_id' => (string) $mark['id'],
                'person_id' => $hidden ? null : (string) $mark['person_id'],
                'matricule' => $hidden ? null : $this->matricule($tenantId, $establishmentId, $mark),
                'last_name' => $hidden ? null : $person?->lastName,
                'first_name' => $hidden ? null : $person?->firstName,
                'group_id' => $hidden ? null : $mark['group_id'],
                'anonymous_code' => $mark['anonymous_code'],
                'score' => $mark['score'] === null ? null : (float) $mark['score'],
                'absence' => $mark['absence_code'],
                'version' => (int) $mark['version'],
            ];
        }
        usort($lines, static fn (array $a, array $b): int => [$a['last_name'] ?? '', $a['first_name'] ?? '', $a['anonymous_code'] ?? ''] <=> [$b['last_name'] ?? '', $b['first_name'] ?? '', $b['anonymous_code'] ?? '']);

        return [
            'assessment' => AssessmentService::resource($row),
            'editable' => $lifecycle->isEditable((string) $row['status']),
            'next_states' => $lifecycle->targetsFrom((string) $row['status']),
            'absence_codes' => $this->settings->absenceCodes($tenantId, $establishmentId, $schoolId),
            'lines' => $lines,
            'entered' => count(array_filter($lines, static fn (array $l): bool => $l['score'] !== null || $l['absence'] !== null)),
            'total' => count($lines),
        ];
    }

    /**
     * Lot de notes (atomique) : toutes les lignes valides sont écrites, sinon rien (422 par ligne, 409 en conflit de version).
     *
     * @param list<array<string, mixed>> $entries person_id|anonymous_code, score|absence, expected_version?
     * @return array<string, mixed>
     */
    public function enter(Principal $principal, string $establishmentId, string $assessmentId, array $entries, string $kind = 'entry'): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->writableAssessment($principal, $establishmentId, $assessmentId);
        $byKey = $this->marksByKey($principal, $establishmentId, $row);
        $errors = [];
        $conflicts = [];
        $plan = [];
        foreach ($entries as $index => $entry) {
            $line = $index + 1;
            try {
                $mark = $this->locate($row, $byKey, $entry);
                $value = $this->value($tenantId, $establishmentId, $row, $entry);
            } catch (\InvalidArgumentException $e) {
                $errors['lines.' . $line][] = $e->getMessage();
                continue;
            }
            $expected = isset($entry['expected_version']) && is_int($entry['expected_version']) ? $entry['expected_version'] : null;
            if ($expected !== null && $expected !== (int) $mark['version']) {
                $conflicts[] = ['line' => $line, 'mark_id' => (string) $mark['id'], 'expected_version' => $expected, 'current_version' => (int) $mark['version'],
                    'current' => ['score' => $mark['score'] === null ? null : (float) $mark['score'], 'absence' => $mark['absence_code']]];
                continue;
            }
            $plan[] = [$mark, $value];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors, 'Lot refusé : aucune note n\'a été enregistrée.');
        }
        if ($conflicts !== []) {
            throw new HttpException(409, 'Conflit de version', 'Des notes ont changé depuis votre lecture : rechargez la grille, rien n\'a été écrit.', 'https://api.scholaris.cm/errors/version-conflict', ['conflicts' => [(string) json_encode($conflicts)]]);
        }
        $written = 0;
        $unchanged = 0;
        $referrals = [];
        $this->store->transaction(function () use ($principal, $establishmentId, $row, $plan, $kind, &$written, &$unchanged, &$referrals): void {
            foreach ($plan as [$mark, $value]) {
                $result = $this->apply($principal, $establishmentId, $row, $mark, $value, $kind, null);
                $result === 'unchanged' ? ++$unchanged : ++$written;
                if ($value->absence === 'fr' && $result !== 'unchanged') {
                    $referrals[] = (string) $mark['person_id'];
                }
            }
        });
        $this->afterWrite($principal, $establishmentId, $row, $written, $referrals);

        return ['written' => $written, 'unchanged' => $unchanged, 'discipline_referrals' => count($referrals)];
    }

    /**
     * Synchronisation hors ligne (lots signés par l'appareil) : chaque cellule est traitée à part.
     *
     * @param list<array<string, mixed>> $entries client_uuid, assessment_id, person_id|anonymous_code, base_version, score|absence
     * @return list<array<string, mixed>>
     */
    public function sync(Principal $principal, string $establishmentId, array $entries): array
    {
        $tenantId = $principal->tenantId;
        $results = [];
        foreach ($entries as $entry) {
            $uuid = (string) ($entry['client_uuid'] ?? '');
            try {
                if (preg_match('/^[A-Za-z0-9-]{8,40}$/', $uuid) !== 1) {
                    throw new \InvalidArgumentException('client_uuid invalide.');
                }
                if ($this->store->count(self::EVENTS, $tenantId, $establishmentId, ['client_uuid' => $uuid]) > 0) {
                    $results[] = ['client_uuid' => $uuid, 'status' => 'duplicate'];
                    continue;
                }
                $row = $this->writableAssessment($principal, $establishmentId, (string) ($entry['assessment_id'] ?? ''));
                $mark = $this->locate($row, $this->marksByKey($principal, $establishmentId, $row), $entry);
                $value = $this->value($tenantId, $establishmentId, $row, $entry);
                $base = $entry['base_version'] ?? null;
                if (!is_int($base)) {
                    throw new \InvalidArgumentException('base_version entière attendue.');
                }
                if ($base !== (int) $mark['version']) {
                    $results[] = ['client_uuid' => $uuid, 'status' => 'conflict', 'mark_id' => (string) $mark['id'], 'base_version' => $base,
                        'server' => ['version' => (int) $mark['version'], 'score' => $mark['score'] === null ? null : (float) $mark['score'], 'absence' => $mark['absence_code']],
                        'client' => ['score' => $value->score, 'absence' => $value->absence]];
                    continue;
                }
                $status = $this->store->transaction(fn (): string => $this->apply($principal, $establishmentId, $row, $mark, $value, 'sync', $uuid));
                $this->afterWrite($principal, $establishmentId, $row, $status === 'unchanged' ? 0 : 1, $value->absence === 'fr' ? [(string) $mark['person_id']] : []);
                $results[] = ['client_uuid' => $uuid, 'status' => $status === 'unchanged' ? 'unchanged' : 'applied', 'mark_id' => (string) $mark['id'], 'version' => (int) $mark['version'] + ($status === 'unchanged' ? 0 : 1)];
            } catch (\InvalidArgumentException $e) {
                $results[] = ['client_uuid' => $uuid, 'status' => 'rejected', 'message' => $e->getMessage()];
            } catch (HttpException $e) {
                $results[] = ['client_uuid' => $uuid, 'status' => 'rejected', 'http_status' => $e->status, 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Import CSV (séparateur « ; » ou « , ») au format du modèle : identifiant (matricule ou numéro d'anonymat) puis note ou code.
     *
     * @return array<string, mixed>
     */
    public function import(Principal $principal, string $establishmentId, string $assessmentId, string $content): array
    {
        $row = $this->writableAssessment($principal, $establishmentId, $assessmentId);
        $hidden = (int) $row['anonymous'] === 1 && $row['anonymity_lifted_at'] === null;
        $lines = preg_split('/\r\n|\n|\r/', trim(preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? '')) ?: [];
        if (count($lines) < 2) {
            throw HttpException::validation(['file' => ['Fichier vide : utilisez le modèle fourni (en-tête puis une ligne par étudiant).']]);
        }
        $separator = str_contains($lines[0], ';') ? ';' : ',';
        $byMatricule = [];
        if (!$hidden) {
            foreach ($this->visibleMarks($principal, $establishmentId, $row) as $mark) {
                $matricule = $this->matricule($principal->tenantId, $establishmentId, $mark);
                if ($matricule !== null) {
                    $byMatricule[strtoupper($matricule)] = (string) $mark['person_id'];
                }
            }
        }
        $entries = [];
        $errors = [];
        foreach (array_slice($lines, 1) as $index => $raw) {
            if (trim($raw) === '') {
                continue;
            }
            $cells = str_getcsv($raw, $separator);
            $identifier = strtoupper(trim((string) ($cells[0] ?? '')));
            $cell = trim((string) ($cells[count($cells) - 1] ?? ''));
            if ($hidden) {
                $entries[] = ['anonymous_code' => $identifier, 'csv' => $cell];
            } elseif (isset($byMatricule[$identifier])) {
                $entries[] = ['person_id' => $byMatricule[$identifier], 'csv' => $cell];
            } else {
                $errors['lines.' . ($index + 2)][] = 'Matricule inconnu dans cette évaluation : « ' . $identifier . ' ».';
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors, 'Import refusé : aucune note n\'a été enregistrée.');
        }

        return ['lines' => count($entries)] + $this->enter($principal, $establishmentId, $assessmentId, $entries, 'import');
    }

    /** Modèle CSV de l'évaluation (en-tête et une ligne par étudiant ou par copie). */
    public function template(Principal $principal, string $establishmentId, string $assessmentId): string
    {
        $grid = $this->grid($principal, $establishmentId, $assessmentId);
        $hidden = $grid['assessment']['anonymous'] === true && $grid['assessment']['anonymity_lifted'] === false;
        $out = $hidden ? "numero_anonymat;note\n" : "matricule;nom;prenom;note\n";
        foreach ($grid['lines'] as $line) {
            $value = $line['absence'] !== null ? strtoupper((string) ($grid['absence_codes'][$line['absence']]['aliases'][0] ?? $line['absence'])) : ($line['score'] === null ? '' : MarkValue::format((float) $line['score']));
            $out .= $hidden
                ? $line['anonymous_code'] . ';' . $value . "\n"
                : implode(';', [(string) $line['matricule'], (string) $line['last_name'], (string) $line['first_name'], $value]) . "\n";
        }

        return "\xEF\xBB\xBF" . $out;
    }

    /** @return list<array<string, mixed>> historique d'une note (ajout seul) */
    public function events(Principal $principal, string $establishmentId, string $markId): array
    {
        $tenantId = $principal->tenantId;
        $mark = $this->store->findOrFail(AssessmentService::MARKS, $tenantId, $establishmentId, $markId, 'Note introuvable.');
        $this->policy->assertReadable($principal, $establishmentId, $this->assessments->find($tenantId, $establishmentId, (string) $mark['assessment_id']));

        return array_map(static fn (array $e): array => [
            'id' => (string) $e['id'], 'kind' => (string) $e['event_kind'],
            'old' => ['score' => $e['old_score'] === null ? null : (float) $e['old_score'], 'absence' => $e['old_absence']],
            'new' => ['score' => $e['new_score'] === null ? null : (float) $e['new_score'], 'absence' => $e['new_absence']],
            'version' => (int) $e['mark_version'], 'actor_id' => (string) $e['actor_id'], 'reason' => $e['reason'],
            'correction_id' => $e['correction_id'], 'created_at' => (string) $e['created_at'],
        ], $this->store->select(self::EVENTS, $tenantId, $establishmentId, ['mark_id' => $markId], 'created_at, id', 500));
    }

    /**
     * Écrit une nouvelle valeur (saisie, correction approuvée) : version + 1, historique, audit avant/après.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $mark
     */
    public function apply(Principal $principal, string $establishmentId, array $row, array $mark, MarkValue $value, string $kind, ?string $clientUuid, ?string $reason = null, ?string $correctionId = null): string
    {
        $tenantId = $principal->tenantId;
        $oldScore = $mark['score'] === null ? null : (float) $mark['score'];
        if ($oldScore === $value->score && $mark['absence_code'] === $value->absence) {
            return 'unchanged';
        }
        $now = $this->clock->now();
        $version = (int) $mark['version'] + 1;
        $updated = $this->store->update(AssessmentService::MARKS, $tenantId, $establishmentId, [
            'score' => $value->score, 'absence_code' => $value->absence, 'version' => $version, 'entered_by' => $principal->userId,
            'entered_at' => $now, 'updated_at' => $now,
        ], ['id' => $mark['id'], 'version' => (int) $mark['version']]);
        if ($updated !== 1) {
            throw HttpException::conflict('La note a changé pendant l\'enregistrement : rechargez la grille.');
        }
        $this->store->insert(self::EVENTS, $tenantId, $establishmentId, [
            'id' => (string) new Ulid(), 'mark_id' => $mark['id'], 'assessment_id' => $row['id'], 'person_id' => $mark['person_id'],
            'event_kind' => $kind, 'old_score' => $oldScore, 'old_absence' => $mark['absence_code'], 'new_score' => $value->score,
            'new_absence' => $value->absence, 'mark_version' => $version, 'actor_id' => $principal->userId, 'reason' => $reason,
            'correction_id' => $correctionId, 'client_uuid' => $clientUuid, 'created_at' => $now,
        ]);
        $this->policy->audit(
            $principal,
            $correctionId === null ? 'higher.grades.mark.entered' : 'higher.grades.mark.corrected',
            'higher_grade_mark',
            (string) $mark['id'],
            ['score' => $oldScore, 'absence' => $mark['absence_code'], 'version' => (int) $mark['version']],
            ['score' => $value->score, 'absence' => $value->absence, 'version' => $version, 'kind' => $kind],
        );

        return 'written';
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $entry score|absence|csv
     */
    public function value(string $tenantId, string $establishmentId, array $row, array $entry): MarkValue
    {
        $schoolId = (string) $row['school_id'];
        $codes = $this->settings->absenceCodes($tenantId, $establishmentId, $schoolId);
        $max = (float) $row['max_score'];
        $step = (float) $row['score_step'];
        if (array_key_exists('csv', $entry)) {
            return MarkValue::fromCsv((string) $entry['csv'], $max, $step, $codes);
        }

        return MarkValue::of($entry['score'] ?? null, $entry['absence'] ?? null, $max, $step, $codes);
    }

    /** @return array<string, mixed> évaluation en saisie, enseignant affecté ; sinon 403 journalisé ou 409 (TST-06) */
    private function writableAssessment(Principal $principal, string $establishmentId, string $assessmentId): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->assessments->find($tenantId, $establishmentId, $assessmentId);
        $this->policy->assertTeacher($principal, $establishmentId, $row);
        if (!$this->settings->lifecycle($tenantId, $establishmentId, (string) $row['school_id'])->isEditable((string) $row['status'])) {
            $this->policy->audit($principal, 'higher.grades.locked_write_refused', 'higher_grade_assessment', (string) $row['id'], null, ['status' => $row['status']]);

            throw HttpException::conflict('Saisie fermée (évaluation « ' . $row['status'] . ' ») : une note soumise, validée ou verrouillée ne se modifie que par une demande de correction motivée.');
        }

        return $row;
    }

    /**
     * Cellules visibles : l'enseignant ne voit que ses groupes quand l'évaluation couvre tout le niveau (RG-NOT-3).
     *
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private function visibleMarks(Principal $principal, string $establishmentId, array $row): array
    {
        $tenantId = $principal->tenantId;
        $marks = $this->store->select(AssessmentService::MARKS, $tenantId, $establishmentId, ['assessment_id' => $row['id']], 'id', 5000);
        if ($row['group_id'] !== null || $this->policy->covers($principal, $establishmentId, $row, MarkPolicy::READ[0]) || $this->policy->covers($principal, $establishmentId, $row, MarkPolicy::VALIDATE)
            || $this->policy->covers($principal, $establishmentId, $row, MarkPolicy::EXAMS) || $this->policy->covers($principal, $establishmentId, $row, MarkPolicy::LIFT)) {
            return $marks;
        }
        $groups = $this->teachers->groupsFor($tenantId, $establishmentId, $principal->userId, (string) $row['element_id']) ?? [];
        if (in_array(null, $groups, true)) {
            return $marks;
        }

        return array_values(array_filter($marks, static fn (array $m): bool => in_array($m['group_id'], $groups, true)));
    }

    /**
     * @param array<string, mixed> $row
     * @return array{person: array<string, array<string, mixed>>, code: array<string, array<string, mixed>>}
     */
    private function marksByKey(Principal $principal, string $establishmentId, array $row): array
    {
        $index = ['person' => [], 'code' => []];
        foreach ($this->visibleMarks($principal, $establishmentId, $row) as $mark) {
            $index['person'][(string) $mark['person_id']] = $mark;
            if ($mark['anonymous_code'] !== null) {
                $index['code'][strtoupper((string) $mark['anonymous_code'])] = $mark;
            }
        }

        return $index;
    }

    /**
     * @param array<string, mixed> $row
     * @param array{person: array<string, array<string, mixed>>, code: array<string, array<string, mixed>>} $byKey
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function locate(array $row, array $byKey, array $entry): array
    {
        $hidden = (int) $row['anonymous'] === 1 && $row['anonymity_lifted_at'] === null;
        if ($hidden) {
            if (isset($entry['person_id'])) {
                throw new \InvalidArgumentException('Copies anonymes : saisissez sur le numéro d\'anonymat, pas sur l\'étudiant.');
            }
            $code = strtoupper((string) ($entry['anonymous_code'] ?? ''));

            return $byKey['code'][$code] ?? throw new \InvalidArgumentException('Numéro d\'anonymat inconnu : « ' . $code . ' ».');
        }
        $person = (string) ($entry['person_id'] ?? '');
        if ($person === '' && isset($entry['anonymous_code'])) {
            return $byKey['code'][strtoupper((string) $entry['anonymous_code'])] ?? throw new \InvalidArgumentException('Numéro d\'anonymat inconnu.');
        }

        return $byKey['person'][$person] ?? throw new \InvalidArgumentException('Étudiant absent de cette évaluation (ou d\'un groupe qui ne vous est pas affecté).');
    }

    /** @param array<string, mixed> $mark */
    private function matricule(string $tenantId, string $establishmentId, array $mark): ?string
    {
        if ($mark['enrolment_id'] === null) {
            return null;
        }

        return $this->students->enrolment($tenantId, $establishmentId, (string) $mark['enrolment_id'])?->matricule;
    }

    /**
     * RG-MAQ-1 (la maquette ne se modifie plus) et RG-NOT-5 (FR : saisine du conseil de discipline, tracée).
     *
     * @param array<string, mixed> $row
     * @param list<string> $referrals
     */
    private function afterWrite(Principal $principal, string $establishmentId, array $row, int $written, array $referrals): void
    {
        if ($written > 0) {
            $this->curricula->markGradesRecorded($principal->tenantId, $establishmentId, (string) $row['curriculum_id']);
        }
        foreach ($referrals as $personId) {
            $this->policy->audit($principal, 'higher.grades.fraud_referral', 'higher_grade_assessment', (string) $row['id'], null, [
                'person_id' => $personId, 'element_id' => $row['element_id'], 'referral' => 'conseil_discipline',
            ]);
        }
    }
}

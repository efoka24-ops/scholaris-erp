<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use InvalidArgumentException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Assessment\Domain\GradeCsv;
use Scholaris\Modules\Socle\Assessment\Domain\Num;
use Scholaris\Modules\Socle\Assessment\Infrastructure\Db;
use Scholaris\Modules\Socle\Assessment\Infrastructure\GradeRepository;

/**
 * Saisie des notes (T118) : cellule isolée, lot atomique, synchronisation hors ligne (client_uuid, contrôle de version),
 * import CSV. Toute modification d'une note écrit une ligne dans grade_events (historique append-only, T124).
 * Une épreuve verrouillée n'accepte plus aucune saisie : seule une correction approuvée la modifie (CorrectionService).
 */
final class GradeService
{
    public const QUALITATIVE = ['acquired', 'in_progress', 'not_acquired'];
    public const ABSENCES = ['justified', 'unjustified'];

    public function __construct(
        private readonly Db $db,
        private readonly GradeRepository $grades,
        private readonly AssessmentService $assessments,
        private readonly Roster $roster,
        private readonly ScopeGuard $scope,
        private readonly AssessmentClock $clock,
        private readonly AssessmentSettings $settings,
    ) {
    }

    /**
     * Valide une cellule. Les messages sont ceux de l'écran : « 22 dépasse le maximum de 20 ». Les codes d'absence
     * acceptés sont ceux du socle plus ceux déclarés par l'application (S1) ; le pas de saisie est celui de l'épreuve,
     * sinon de l'établissement (S2), sinon deux décimales au plus.
     *
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $input value, absence, qualitative, remark
     * @param array{codes: list<string>, step: float|null}|null $rules paramètres déjà lus (saisie en lot : une lecture par lot)
     * @return array{score: float|null, absence: string|null, qualitative: string|null, remark: string|null}
     * @throws InvalidArgumentException
     */
    public function parseCell(array $assessment, array $input, ?array $rules = null): array
    {
        $absence = $input['absence'] ?? null;
        if ($absence !== null) {
            $rules ??= $this->cellRules($assessment);
            $codes = $rules['codes'];
            if (!in_array($absence, $codes, true)) {
                throw new InvalidArgumentException(count($codes) === 2
                    ? 'L\'absence doit valoir « justified » ou « unjustified ».'
                    : 'Code d\'absence inconnu. Codes possibles : ' . implode(', ', $codes) . '.');
            }
        }
        $remark = isset($input['remark']) && is_string($input['remark']) && trim($input['remark']) !== '' ? mb_substr(trim($input['remark']), 0, 255) : null;
        $score = null;
        $qualitative = null;

        if ($assessment['grading_mode'] === 'qualitative') {
            $rules ??= $this->cellRules($assessment);
            $qualitative = $input['qualitative'] ?? null;
            $allowed = $rules['qualitative'] ?? self::QUALITATIVE;
            if ($qualitative !== null && !in_array($qualitative, $allowed, true)) {
                throw new InvalidArgumentException('Appréciation inconnue. Valeurs possibles : ' . implode(', ', $allowed) . '.');
            }
            if (isset($input['value'])) {
                throw new InvalidArgumentException('Cette épreuve est qualitative : saisissez une appréciation, pas une note.');
            }
            if ($absence !== null) {
                $qualitative = null;
            }
        } else {
            $value = $input['value'] ?? null;
            if ($value !== null && !is_int($value) && !is_float($value)) {
                throw new InvalidArgumentException('La note doit être un nombre.');
            }
            if ($value !== null && $absence !== null) {
                throw new InvalidArgumentException('Une note ne peut pas être saisie pour un élève absent. Retirez la note ou l\'absence.');
            }
            if ($value !== null) {
                $score = (float) $value;
                $max = (float) $assessment['max_score'];
                if ($score < 0) {
                    throw new InvalidArgumentException(Num::format($score) . ' est négatif : une note est comprise entre 0 et ' . Num::format($max) . '.');
                }
                if ($score > $max) {
                    throw new InvalidArgumentException(Num::format($score) . ' dépasse le maximum de ' . Num::format($max) . '.');
                }
                if (abs($score * 100 - round($score * 100)) > 1e-6) {
                    throw new InvalidArgumentException(Num::format($score) . ' a trop de décimales : deux au plus.');
                }
                $step = isset($assessment['score_step']) && (float) $assessment['score_step'] > 0 ? (float) $assessment['score_step'] : ($rules ??= $this->cellRules($assessment))['step'];
                if ($step !== null && abs($score / $step - round($score / $step)) > 1e-6) {
                    throw new InvalidArgumentException(sprintf('%s n\'est pas un multiple de %s : la note se saisit au pas de %s.', Num::format($score), Num::format($step), Num::format($step)));
                }
            }
        }

        return ['score' => $score, 'absence' => $absence, 'qualitative' => $qualitative, 'remark' => $remark];
    }

    /**
     * Paramètres de saisie de l'établissement de l'épreuve : codes d'absence acceptés et pas de saisie.
     *
     * @param array<string, mixed> $assessment
     * @return array{codes: list<string>, step: float|null, qualitative: list<string>}
     */
    private function cellRules(array $assessment): array
    {
        $tenantId = (string) ($assessment['tenant_id'] ?? '');
        $establishmentId = (string) ($assessment['establishment_id'] ?? '');

        return [
            'codes' => array_map('strval', array_keys($this->settings->absenceCodes($tenantId, $establishmentId))),
            'step' => $this->settings->step($tenantId, $establishmentId),
            'qualitative' => array_column($this->settings->qualitativeScale($tenantId, $establishmentId), 'code'),
        ];
    }

    /**
     * Saisie d'une cellule.
     *
     * @param array<string, mixed> $data assessment_id, person_id, value|absence|qualitative, expected_version, client_uuid
     * @return array{status: string, grade: array<string, mixed>}
     * @throws VersionConflict
     */
    public function enter(Principal $principal, string $establishmentId, array $data): array
    {
        $tenantId = $principal->tenantId;
        $assessment = $this->assessments->assessment($tenantId, $establishmentId, (string) $data['assessment_id']);
        $this->assessments->assertEditable($tenantId, $establishmentId, $assessment, 'saisir cette note');
        $personId = (string) $data['person_id'];
        $this->assertOnRoster($tenantId, $establishmentId, $assessment, [$personId]);
        $this->scope->assertCanEnter($principal, $establishmentId, $assessment, [$personId]);

        try {
            $cell = $this->parseCell($assessment, $data);
        } catch (InvalidArgumentException $e) {
            throw HttpException::validation(['value' => [$assessment['title'] . ' : ' . $e->getMessage() . ' Corrigez avant de valider.']]);
        }

        $result = $this->db->transaction(fn (): array => $this->applyEntry(
            $principal,
            $establishmentId,
            $assessment,
            $personId,
            $cell,
            isset($data['expected_version']) ? (int) $data['expected_version'] : null,
            $data['client_uuid'] ?? null,
            'entry',
        ));
        if ($result['status'] === 'conflict') {
            throw new VersionConflict([['server' => $result['server'], 'client' => $result['client']]]);
        }

        return ['status' => $result['status'], 'grade' => $result['grade']];
    }

    /**
     * Saisie en lot, atomique : une seule erreur et rien n'est enregistré.
     *
     * @param list<array<string, mixed>> $entries person_id, value|absence|qualitative, expected_version
     * @return array{created: int, updated: int, unchanged: int}
     * @throws VersionConflict
     */
    public function batch(Principal $principal, string $establishmentId, string $assessmentId, array $entries, string $kind = 'entry'): array
    {
        $lines = [];
        foreach ($entries as $index => $entry) {
            $lines[] = ['line' => $index + 1, 'person_id' => (string) $entry['person_id'], 'input' => $entry, 'expected_version' => isset($entry['expected_version']) ? (int) $entry['expected_version'] : null];
        }

        return $this->applyAll($principal, $establishmentId, $assessmentId, $lines, $kind);
    }

    /**
     * Import CSV d'une épreuve : mêmes contrôles que la saisie en lot, rapportés ligne par ligne.
     *
     * @return array{created: int, updated: int, unchanged: int}
     */
    public function import(Principal $principal, string $establishmentId, string $assessmentId, string $content): array
    {
        $tenantId = $principal->tenantId;
        $assessment = $this->assessments->assessment($tenantId, $establishmentId, $assessmentId);
        $students = $this->roster->students($tenantId, $establishmentId, (string) $assessment['structure_id']);
        $byKey = [];
        foreach ($students as $student) {
            $byKey[mb_strtolower($student['person_id'])] = $student['person_id'];
            if ($student['matricule'] !== null) {
                $byKey[mb_strtolower($student['matricule'])] = $student['person_id'];
            }
        }

        $aliases = [];
        $others = [];
        foreach ($this->settings->absenceCodes($tenantId, $establishmentId) as $code => $declared) {
            foreach ([strtoupper($code), ...$declared['aliases']] as $alias) {
                $aliases[$alias] ??= $code;
            }
            if (!isset(AssessmentSettings::BUILT_IN_ABSENCES[$code])) {
                $others[] = ($declared['aliases'][0] ?? strtoupper($code)) . ' (' . $declared['label'] . ')';
            }
        }
        $extra = $others === [] ? '' : ', ' . implode(', ', $others);

        $lines = [];
        $errors = [];
        foreach (GradeCsv::parse($content) as $row) {
            $personId = $byKey[mb_strtolower($row['key'])] ?? null;
            if ($personId === null) {
                $errors[] = sprintf('Ligne %d · élève inconnu « %s » dans cette classe. Vérifiez le matricule. Corrigez avant de valider.', $row['line'], $row['key']);
                continue;
            }
            if ($row['raw'] === '') {
                continue;
            }
            $input = $this->csvInput($row['raw'], $aliases);
            if ($input === null) {
                $errors[] = sprintf('Ligne %d · %s : « %s » n\'est pas une note. Utilisez un nombre, ABS (absent justifié) ou ABI (absent non justifié)%s. Corrigez avant de valider.', $row['line'], $assessment['title'], $row['raw'], $extra);
                continue;
            }
            $lines[] = ['line' => $row['line'], 'person_id' => $personId, 'input' => $input, 'expected_version' => null];
        }

        return $this->applyAll($principal, $establishmentId, $assessmentId, $lines, 'import', $errors);
    }

    /** Modèle CSV à remplir pour une épreuve. */
    public function template(string $tenantId, string $establishmentId, string $assessmentId): string
    {
        $assessment = $this->assessments->assessment($tenantId, $establishmentId, $assessmentId);
        $students = [];
        foreach ($this->roster->students($tenantId, $establishmentId, (string) $assessment['structure_id']) as $student) {
            $students[] = ['key' => $student['matricule'] ?? $student['person_id'], 'name' => $student['name']];
        }

        return GradeCsv::template($students);
    }

    /**
     * Synchronisation hors ligne : chaque note est traitée à part ; un rejeu ne crée jamais de doublon (client_uuid) ;
     * une version périmée est signalée avec les deux versions, jamais écrasée.
     *
     * @param list<array<string, mixed>> $entries client_uuid, assessment_id, person_id, base_version, value|absence|qualitative
     * @return list<array<string, mixed>>
     */
    public function sync(Principal $principal, string $establishmentId, array $entries): array
    {
        $tenantId = $principal->tenantId;
        $results = [];
        foreach ($entries as $entry) {
            $uuid = (string) $entry['client_uuid'];
            try {
                $assessment = $this->assessments->assessment($tenantId, $establishmentId, (string) $entry['assessment_id']);
                $this->assessments->assertEditable($tenantId, $establishmentId, $assessment, 'enregistrer cette note');
                $this->assertOnRoster($tenantId, $establishmentId, $assessment, [(string) $entry['person_id']]);
                $this->scope->assertCanEnter($principal, $establishmentId, $assessment, [(string) $entry['person_id']]);
                $cell = $this->parseCell($assessment, $entry);
                $result = $this->db->transaction(fn (): array => $this->applyEntry(
                    $principal,
                    $establishmentId,
                    $assessment,
                    (string) $entry['person_id'],
                    $cell,
                    (int) $entry['base_version'],
                    $uuid,
                    'sync',
                ));
            } catch (HttpException $e) {
                $results[] = ['client_uuid' => $uuid, 'status' => 'rejected', 'message' => $e->getMessage()];
                continue;
            } catch (InvalidArgumentException $e) {
                $results[] = ['client_uuid' => $uuid, 'status' => 'rejected', 'message' => $e->getMessage()];
                continue;
            }
            $results[] = ['client_uuid' => $uuid] + $result;
        }

        return $results;
    }

    /** @return list<array<string, mixed>> historique d'une note, du plus ancien au plus récent */
    public function events(string $tenantId, string $establishmentId, string $gradeId): array
    {
        $grade = $this->grades->grade($tenantId, $establishmentId, $gradeId) ?? throw HttpException::notFound('Note introuvable.');

        return $this->grades->events($tenantId, (string) $grade['id']);
    }

    /**
     * @param list<array{line: int, person_id: string, input: array<string, mixed>, expected_version: int|null}> $lines
     * @param list<string> $fileErrors erreurs déjà relevées à la lecture du fichier (import)
     * @return array{created: int, updated: int, unchanged: int}
     */
    private function applyAll(Principal $principal, string $establishmentId, string $assessmentId, array $lines, string $kind, array $fileErrors = []): array
    {
        $tenantId = $principal->tenantId;
        $assessment = $this->assessments->assessment($tenantId, $establishmentId, $assessmentId);
        $this->assessments->assertEditable($tenantId, $establishmentId, $assessment, 'enregistrer des notes');
        $personIds = array_column($lines, 'person_id');
        $this->scope->assertCanEnter($principal, $establishmentId, $assessment, $personIds);

        $known = array_flip(array_column($this->roster->students($tenantId, $establishmentId, (string) $assessment['structure_id']), 'person_id'));
        $errors = $fileErrors;
        $parsed = [];
        $seen = [];
        $rules = $this->cellRules($assessment);
        foreach ($lines as $line) {
            if (!isset($known[$line['person_id']])) {
                $errors[] = sprintf('Ligne %d · élève inconnu dans cette classe. Corrigez avant de valider.', $line['line']);
                continue;
            }
            if (isset($seen[$line['person_id']])) {
                $errors[] = sprintf('Ligne %d · %s : cet élève figure déjà plus haut. Corrigez avant de valider.', $line['line'], $assessment['title']);
                continue;
            }
            $seen[$line['person_id']] = true;
            try {
                $parsed[] = ['line' => $line, 'cell' => $this->parseCell($assessment, $line['input'], $rules)];
            } catch (InvalidArgumentException $e) {
                $errors[] = sprintf('Ligne %d · %s : %s Corrigez avant de valider.', $line['line'], $assessment['title'], $e->getMessage());
            }
        }
        if ($errors !== []) {
            usort($errors, static fn (string $a, string $b): int => (int) preg_replace('/\D+/', '', substr($a, 0, 12)) <=> (int) preg_replace('/\D+/', '', substr($b, 0, 12)));
            throw HttpException::validation([$kind === 'import' ? 'file' : 'entries' => $errors], count($errors) === 1 ? '1 erreur : aucune note n\'a été enregistrée.' : count($errors) . ' erreurs : aucune note n\'a été enregistrée.');
        }

        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        $this->db->transaction(function () use ($principal, $establishmentId, $assessment, $parsed, $kind, &$counts): void {
            // Notes existantes de l'épreuve lues en une requête (P2 : plus de lecture par élève), sous la transaction.
            $existing = [];
            foreach ($this->grades->ofAssessment($principal->tenantId, (string) $assessment['id']) as $row) {
                $existing[(string) $row['person_id']] = $row;
            }
            $conflicts = [];
            foreach ($parsed as $item) {
                $personId = $item['line']['person_id'];
                $result = $this->applyEntry($principal, $establishmentId, $assessment, $personId, $item['cell'], $item['line']['expected_version'], null, $kind, $existing[$personId] ?? null, false);
                if ($result['status'] === 'conflict') {
                    $conflicts[] = ['server' => $result['server'], 'client' => $result['client']];
                    continue;
                }
                match ($result['status']) {
                    'created' => ++$counts['created'],
                    'updated' => ++$counts['updated'],
                    default => ++$counts['unchanged'],
                };
            }
            if ($conflicts !== []) {
                throw new VersionConflict($conflicts);
            }
        });

        return $counts;
    }

    /**
     * Écrit (ou non) une cellule. À appeler dans une transaction.
     *
     * @param array<string, mixed> $assessment
     * @param array{score: float|null, absence: string|null, qualitative: string|null, remark: string|null} $cell
     * @param array<string, mixed>|false|null $preloaded note existante déjà lue (null = aucune), false = à lire
     * @param bool $reread relire la note écrite pour la renvoyer (inutile pour un lot, qui ne renvoie que des compteurs)
     * @return array<string, mixed> status created|updated|unchanged|duplicate (+ grade) ou conflict (+ server, client)
     */
    private function applyEntry(Principal $principal, string $establishmentId, array $assessment, string $personId, array $cell, ?int $expectedVersion, mixed $clientUuid, string $kind, array|false|null $preloaded = false, bool $reread = true): array
    {
        $tenantId = $principal->tenantId;
        $assessmentId = (string) $assessment['id'];
        $uuid = is_string($clientUuid) && $clientUuid !== '' ? $clientUuid : null;
        $existing = $preloaded === false ? $this->grades->cell($tenantId, $assessmentId, $personId) : $preloaded;
        $client = $cell + ['person_id' => $personId, 'assessment_id' => $assessmentId, 'base_version' => $expectedVersion, 'client_uuid' => $uuid];

        if ($uuid !== null) {
            $byUuid = $this->grades->byClientUuid($tenantId, $uuid);
            if ($byUuid !== null && ($byUuid['assessment_id'] !== $assessmentId || $byUuid['person_id'] !== $personId)) {
                throw HttpException::validation(['client_uuid' => ['Cet identifiant d\'appareil sert déjà à une autre note. Générez-en un nouveau par note.']]);
            }
            if ($byUuid !== null && $this->sameValues($byUuid, $cell)) {
                return ['status' => 'duplicate', 'grade' => $byUuid];
            }
        }

        if ($existing === null) {
            $isEmpty = $cell['score'] === null && $cell['absence'] === null && $cell['qualitative'] === null;
            if ($isEmpty) {
                return ['status' => 'unchanged', 'grade' => ['assessment_id' => $assessmentId, 'person_id' => $personId, 'score' => null, 'absence' => null, 'qualitative' => null, 'version' => 0]];
            }
            $id = (string) new Ulid();
            $now = $this->clock->now();
            $this->grades->insert('grades', [
                'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'assessment_id' => $assessmentId, 'person_id' => $personId,
                'score' => $cell['score'], 'absence' => $cell['absence'], 'qualitative' => $cell['qualitative'], 'remark' => $cell['remark'],
                'version' => 1, 'entered_by' => $principal->userId, 'client_uuid' => $uuid, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->recordEvent($tenantId, $establishmentId, $id, $assessmentId, $personId, $kind, null, $cell, $principal->userId, null, null, $now);

            return ['status' => 'created', 'grade' => $reread ? ($this->grades->grade($tenantId, $establishmentId, $id) ?? []) : ['id' => $id]];
        }

        if ($this->sameValues($existing, $cell)) {
            return ['status' => 'unchanged', 'grade' => $existing];
        }
        if ($expectedVersion !== null && $expectedVersion !== (int) $existing['version']) {
            return ['status' => 'conflict', 'server' => $existing, 'client' => $client];
        }

        $now = $this->clock->now();
        $this->grades->updateGrade($tenantId, (string) $existing['id'], [
            'score' => $cell['score'], 'absence' => $cell['absence'], 'qualitative' => $cell['qualitative'], 'remark' => $cell['remark'],
            'version' => (int) $existing['version'] + 1, 'entered_by' => $principal->userId, 'client_uuid' => $uuid ?? $existing['client_uuid'], 'updated_at' => $now,
        ]);
        $this->recordEvent($tenantId, $establishmentId, (string) $existing['id'], $assessmentId, $personId, $kind, $existing, $cell, $principal->userId, null, null, $now);

        return ['status' => 'updated', 'grade' => $reread ? ($this->grades->grade($tenantId, $establishmentId, (string) $existing['id']) ?? []) : ['id' => (string) $existing['id']]];
    }

    /**
     * Écrit une ligne d'historique. Public pour la correction approuvée.
     *
     * @param array<string, mixed>|null $old
     * @param array{score: float|null, absence: string|null, qualitative?: string|null} $new
     */
    public function recordEvent(string $tenantId, string $establishmentId, string $gradeId, string $assessmentId, string $personId, string $kind, ?array $old, array $new, string $author, ?string $reason, ?string $approvedBy, string $at): void
    {
        $this->grades->insert('grade_events', [
            'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'grade_id' => $gradeId, 'assessment_id' => $assessmentId,
            'person_id' => $personId, 'event_kind' => $kind,
            'old_score' => $old === null || $old['score'] === null ? null : (float) $old['score'], 'new_score' => $new['score'],
            'old_absence' => $old['absence'] ?? null, 'new_absence' => $new['absence'],
            'author' => $author, 'reason' => $reason, 'approved_by' => $approvedBy, 'happened_at' => $at,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @param array{score: float|null, absence: string|null, qualitative: string|null, remark: string|null} $cell
     */
    private function sameValues(array $row, array $cell): bool
    {
        $score = $row['score'] === null ? null : (float) $row['score'];
        $sameScore = $score === null || $cell['score'] === null ? $score === $cell['score'] : abs($score - $cell['score']) < 1e-9;

        return $sameScore && ($row['absence'] ?? null) === $cell['absence'] && ($row['qualitative'] ?? null) === $cell['qualitative'];
    }

    /**
     * @param array<string, string> $aliases alias en majuscules => code d'absence
     * @return array<string, mixed>|null
     */
    private function csvInput(string $raw, array $aliases): ?array
    {
        $upper = mb_strtoupper(trim($raw));
        if (isset($aliases[$upper])) {
            return ['absence' => $aliases[$upper]];
        }
        $number = str_replace(',', '.', trim($raw));
        if (preg_match('/^\d+(\.\d+)?$/', $number) !== 1) {
            return null;
        }

        return ['value' => (float) $number];
    }

    /**
     * @param array<string, mixed> $assessment
     * @param list<string> $personIds
     */
    private function assertOnRoster(string $tenantId, string $establishmentId, array $assessment, array $personIds): void
    {
        $known = array_column($this->roster->students($tenantId, $establishmentId, (string) $assessment['structure_id']), 'person_id');
        if (array_diff($personIds, $known) !== []) {
            throw HttpException::validation(['person_id' => ['Élève inconnu dans cette classe. Vérifiez l\'inscription de l\'élève.']]);
        }
    }
}

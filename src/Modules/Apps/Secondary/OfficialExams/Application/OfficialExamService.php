<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\OfficialExams\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\ExamFeeStatus;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\NationalIds;
use Scholaris\Modules\Apps\Secondary\OfficialExams\Domain\ExamRules;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Listing;
use Scholaris\Modules\Apps\Secondary\Support\ScopedByClass;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\Assessment\Contracts\ClassResults;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\MediaRef;
use Scholaris\Modules\Socle\People\Contracts\MediaStore;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/** Sessions et listes de candidats aux examens officiels. Les référentiels, calendriers, pièces et formats restent configurables. */
final class OfficialExamService
{
    public const SESSIONS = 'se_official_exam_sessions';
    public const CANDIDATES = 'se_official_exam_candidates';
    public const READ = ['secondary.exams.read', 'secondary.exams.manage', 'secondary.exams.lists', 'secondary.exams.validate'];
    public const MANAGE = ['secondary.exams.manage', 'secondary.exams.lists'];
    public const VALIDATE = ['secondary.exams.validate'];
    private const MEDIA_NAMESPACE = 'secondary-exams';
    private const PHOTO_ROUTE = '/api/v1/secondary/official-exams/candidates/';
    private const FAMILY_LINK = '/secondaire/famille';

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly Listing $listing,
        private readonly ScopedByClass $scoped,
        private readonly SecondaryClock $clock,
        private readonly ClassProfiles $classes,
        private readonly EstablishmentProfiles $profiles,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly OrgUnitTree $tree,
        private readonly NationalIds $nationalIds,
        private readonly ExamFeeStatus $examFees,
        private readonly MediaStore $media,
        private readonly ClassResults $classResults,
        private readonly Messenger $messenger,
    ) {
    }

    /** @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}} */
    public function sessions(Principal $principal, string $establishmentId, \Psr\Http\Message\ServerRequestInterface $request): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $this->modules($principal->tenantId, $establishmentId);

        return $this->listing->page($request, self::SESSIONS, $principal->tenantId, $establishmentId, ['exam_code', 'academic_year_id', 'class_structure_id', 'status'], ['exam_code', 'academic_year_id', 'exam_label', 'status'], 'academic_year_id DESC, exam_code', static fn (array $row): array => self::sessionResource($row), [], $this->scoped->sqlFilter($perimeter, 'class_structure_id'));
    }

    /** @return array<string, mixed> */
    public function showSession(Principal $principal, string $establishmentId, string $id): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $session = $this->store->findOrFail(self::SESSIONS, $principal->tenantId, $establishmentId, $id, 'Session d’examen introuvable.');
        $this->access->assertReadableClass($perimeter, (string) $session['class_structure_id']);

        return self::sessionResource($session);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createSession(Principal $principal, string $establishmentId, array $data): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $class = $this->class($principal->tenantId, $establishmentId, (string) $data['class_structure_id'], (string) $data['academic_year_id']);
        $this->access->assertWritableClass($principal, $perimeter, $class->structureId, 'se_official_exam_session', $class->structureId);
        if (!$this->profiles->forEstablishment($principal->tenantId, $establishmentId)->isActive(Modules::OFFICIAL_EXAMS)) {
            throw HttpException::conflict('Le module official_exams n’est pas activé.');
        }
        $this->dates($data['registration_deadline'] ?? null, $data['exam_on'] ?? null);
        $row = [
            'id' => (string) new Ulid(), 'exam_code' => (string) $data['exam_code'], 'exam_label' => (string) $data['exam_label'],
            'academic_year_id' => $class->academicYearId, 'class_structure_id' => $class->structureId,
            'comparison_period_id' => $data['comparison_period_id'] ?? null, 'registration_deadline' => $data['registration_deadline'] ?? null,
            'exam_on' => $data['exam_on'] ?? null, 'required_pieces' => self::json($data['required_pieces'] ?? []),
            'optional_subjects' => self::json($data['optional_subjects'] ?? []), 'export_columns' => self::json($data['export_columns'] ?? []),
            'passing_codes' => self::json($data['passing_codes'] ?? []), 'status' => ExamRules::SESSION_OPEN,
            'created_by' => $principal->userId, 'created_at' => $this->clock->now(), 'updated_at' => $this->clock->now(),
        ];
        $this->store->insert(self::SESSIONS, $principal->tenantId, $establishmentId, $row);
        $this->access->audit($principal, 'secondary.official_exam.session.created', 'se_official_exam_session', $row['id'], null, ['exam_code' => $row['exam_code'], 'class_id' => $class->structureId]);

        return self::sessionResource($this->store->findOrFail(self::SESSIONS, $principal->tenantId, $establishmentId, $row['id']));
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updateSession(Principal $principal, string $establishmentId, string $id, array $changes): array
    {
        $session = $this->writableSession($principal, $establishmentId, $id);
        if ($session['status'] === ExamRules::SESSION_CLOSED) {
            throw HttpException::conflict('Session close : aucune modification.');
        }
        $this->dates($changes['registration_deadline'] ?? $session['registration_deadline'], $changes['exam_on'] ?? $session['exam_on']);
        $values = [];
        foreach (['exam_code', 'exam_label', 'comparison_period_id', 'registration_deadline', 'exam_on'] as $field) {
            if (array_key_exists($field, $changes)) {
                $values[$field] = $changes[$field];
            }
        }
        foreach (['required_pieces', 'optional_subjects', 'export_columns', 'passing_codes'] as $field) {
            if (array_key_exists($field, $changes)) {
                $values[$field] = self::json($changes[$field]);
            }
        }
        if (($changes['status'] ?? null) === ExamRules::SESSION_CLOSED) {
            $values['status'] = ExamRules::SESSION_CLOSED;
        }
        if ($values === []) {
            throw HttpException::validation(['session' => ['Aucun champ modifiable fourni.']]);
        }
        $values['updated_at'] = $this->clock->now();
        $this->store->update(self::SESSIONS, $principal->tenantId, $establishmentId, $values, ['id' => $id]);
        $this->access->audit($principal, 'secondary.official_exam.session.updated', 'se_official_exam_session', $id, $session, $values);

        return self::sessionResource($this->store->findOrFail(self::SESSIONS, $principal->tenantId, $establishmentId, $id));
    }

    /**
     * Génère les candidats depuis les inscriptions actives de la classe exacte (RG-EXA-1), sans supprimer de dossier.
     * @return array{added: int, already_listed: int}
     */
    public function generate(Principal $principal, string $establishmentId, string $sessionId): array
    {
        $session = $this->writableSession($principal, $establishmentId, $sessionId);
        $this->assertOpen($session);
        $tenantId = $principal->tenantId;
        $class = $this->class($tenantId, $establishmentId, (string) $session['class_structure_id'], (string) $session['academic_year_id']);
        $people = $this->people->findMany($tenantId, $this->enrollments->personIdsInStructure($tenantId, $class->structureId));
        $byId = [];
        foreach ($people as $person) {
            $byId[$person->id] = $person;
        }
        $added = 0;
        $existing = [];
        foreach ($this->store->select(self::CANDIDATES, $tenantId, $establishmentId, ['session_id' => $sessionId], 'id', 10000) as $candidate) {
            $existing[(string) $candidate['person_id']] = true;
        }
        foreach (array_keys($byId) as $personId) {
            $enrollment = $this->activeEnrollment($tenantId, $establishmentId, $class->structureId, $class->academicYearId, $personId);
            if ($enrollment === null || isset($existing[$personId])) {
                continue;
            }
            $this->insertCandidate($principal, $establishmentId, $session, $enrollment, $byId[$personId]->fullName());
            if (!$this->examFees->seen($tenantId, $establishmentId, $personId, (string) $session['exam_code']) && is_string($session['registration_deadline'])) {
                $this->notifyUnpaid($principal, $establishmentId, $personId, $byId[$personId]->fullName(), $session);
            }
            $existing[$personId] = true;
            ++$added;
        }
        $this->access->audit($principal, 'secondary.official_exam.candidates.generated', 'se_official_exam_session', $sessionId, null, ['added' => $added]);

        return ['added' => $added, 'already_listed' => count($existing) - $added];
    }

    /**
     * Ajout manuel contrôlé contre l'inscription exacte de classe/année.
     * @return array<string, mixed>
     */
    public function addCandidate(Principal $principal, string $establishmentId, string $sessionId, string $personId): array
    {
        $session = $this->writableSession($principal, $establishmentId, $sessionId);
        $this->assertOpen($session);
        $person = $this->people->find($principal->tenantId, $personId);
        $enrollment = $this->activeEnrollment($principal->tenantId, $establishmentId, (string) $session['class_structure_id'], (string) $session['academic_year_id'], $personId);
        if ($person === null || $enrollment === null) {
            throw HttpException::validation(['person_id' => ['RG-EXA-1 : seul un élève régulièrement inscrit dans la classe d’examen figure sur la liste.']]);
        }
        if ($this->store->count(self::CANDIDATES, $principal->tenantId, $establishmentId, ['session_id' => $sessionId, 'person_id' => $personId]) > 0) {
            throw HttpException::conflict('Cet élève figure déjà sur la liste de cette session.');
        }
        $candidate = $this->insertCandidate($principal, $establishmentId, $session, $enrollment, $person->fullName());
        if (!$this->examFees->seen($principal->tenantId, $establishmentId, $personId, (string) $session['exam_code']) && is_string($session['registration_deadline'])) {
            $this->notifyUnpaid($principal, $establishmentId, $personId, $person->fullName(), $session);
        }
        $this->access->audit($principal, 'secondary.official_exam.candidate.added', 'se_official_exam_candidate', (string) $candidate['id'], null, ['person_id' => $personId]);

        return $this->candidateResource($principal->tenantId, $establishmentId, $session, $candidate);
    }

    /** @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}} */
    public function candidates(Principal $principal, string $establishmentId, string $sessionId, \Psr\Http\Message\ServerRequestInterface $request): array
    {
        $session = $this->readableSession($principal, $establishmentId, $sessionId);
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $page = $this->listing->page($request, self::CANDIDATES, $principal->tenantId, $establishmentId, ['person_id'], ['full_name', 'created_at'], 'full_name', fn (array $row): array => $this->candidateResource($principal->tenantId, $establishmentId, $session, $row), ['session_id' => $sessionId], $this->scoped->sqlFilter($perimeter, 'class_structure_id'));

        return $page;
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updateCandidate(Principal $principal, string $establishmentId, string $id, array $changes): array
    {
        [$candidate, $session] = $this->writableCandidate($principal, $establishmentId, $id);
        $this->assertOpen($session);
        if (isset($changes['selected_subjects'])) {
            $allowedSubjects = self::stringList($session['optional_subjects'] ?? '[]');
            if (array_diff($changes['selected_subjects'], $allowedSubjects) !== []) {
                throw HttpException::validation(['selected_subjects' => ['Seules les matières optionnelles configurées pour la session peuvent être sélectionnées.']]);
            }
        }
        if (isset($changes['pieces_received'])) {
            $allowedPieces = self::stringList($session['required_pieces'] ?? '[]');
            if (array_diff($changes['pieces_received'], $allowedPieces) !== []) {
                throw HttpException::validation(['pieces_received' => ['Seules les pièces configurées pour la session peuvent être marquées comme reçues.']]);
            }
        }
        $values = [];
        foreach (['pieces_received', 'selected_subjects'] as $field) {
            if (array_key_exists($field, $changes)) {
                $values[$field] = self::json($changes[$field]);
            }
        }
        if ($values === []) {
            throw HttpException::validation(['candidate' => ['Aucun champ modifiable fourni.']]);
        }
        $values['updated_at'] = $this->clock->now();
        $this->store->update(self::CANDIDATES, $principal->tenantId, $establishmentId, $values, ['id' => $id]);
        $updated = $this->store->findOrFail(self::CANDIDATES, $principal->tenantId, $establishmentId, $id);
        $this->access->audit($principal, 'secondary.official_exam.candidate.updated', 'se_official_exam_candidate', $id, $candidate, $values);

        return $this->candidateResource($principal->tenantId, $establishmentId, $session, $updated);
    }

    /**
     * Retrait d'un candidat de la liste de travail ; aucun motif de dette ou d'impayé n'est accepté.
     * @return array{id: string, removed: true}
     */
    public function removeCandidate(Principal $principal, string $establishmentId, string $id): array
    {
        [$candidate, $session] = $this->writableCandidate($principal, $establishmentId, $id);
        $this->assertOpen($session);
        if (is_string($candidate['photo_ref'])) {
            $ref = MediaRef::tryFrom((string) $candidate['photo_ref']);
            if ($ref !== null) {
                $this->media->delete($principal->tenantId, $ref);
            }
        }
        $this->store->delete(self::CANDIDATES, $principal->tenantId, $establishmentId, ['id' => $id, 'session_id' => (string) $session['id']]);
        $this->access->audit($principal, 'secondary.official_exam.candidate.removed', 'se_official_exam_candidate', $id, $candidate, null);

        return ['id' => $id, 'removed' => true];
    }

    /** @return array<string, mixed> */
    public function removePhoto(Principal $principal, string $establishmentId, string $id): array
    {
        [$candidate, $session] = $this->writableCandidate($principal, $establishmentId, $id);
        $this->assertOpen($session);
        if (is_string($candidate['photo_ref'])) {
            $ref = MediaRef::tryFrom((string) $candidate['photo_ref']);
            if ($ref !== null) {
                $this->media->delete($principal->tenantId, $ref);
            }
        }
        $this->store->update(self::CANDIDATES, $principal->tenantId, $establishmentId, ['photo_ref' => null, 'updated_at' => $this->clock->now()], ['id' => $id]);
        $updated = $this->store->findOrFail(self::CANDIDATES, $principal->tenantId, $establishmentId, $id);
        $this->access->audit($principal, 'secondary.official_exam.candidate.photo_removed', 'se_official_exam_candidate', $id);

        return $this->candidateResource($principal->tenantId, $establishmentId, $session, $updated);
    }

    /**
     * Photo déposée en stockage privé ; référence opaque et URL signée seulement.
     * @return array<string, mixed>
     */
    public function setPhoto(Principal $principal, string $establishmentId, string $id, string $base64): array
    {
        [$candidate, $session] = $this->writableCandidate($principal, $establishmentId, $id);
        $this->assertOpen($session);
        $content = base64_decode($base64, true);
        if ($content === false || $content === '') {
            throw HttpException::validation(['photo' => ['Image encodée en base64 attendue.']]);
        }
        try {
            $ref = $this->media->put($principal->tenantId, self::MEDIA_NAMESPACE, $id, $content);
        } catch (\InvalidArgumentException $error) {
            throw HttpException::validation(['photo' => [$error->getMessage()]]);
        }
        if (!str_ends_with($ref->value, '.jpg') && !str_ends_with($ref->value, '.png')) {
            $this->media->delete($principal->tenantId, $ref);
            throw HttpException::validation(['photo' => ['Une photo JPEG ou PNG est attendue.']]);
        }
        $old = is_string($candidate['photo_ref']) ? MediaRef::tryFrom((string) $candidate['photo_ref']) : null;
        $this->store->update(self::CANDIDATES, $principal->tenantId, $establishmentId, ['photo_ref' => $ref->value, 'updated_at' => $this->clock->now()], ['id' => $id]);
        if ($old !== null) {
            $this->media->delete($principal->tenantId, $old);
        }
        $updated = $this->store->findOrFail(self::CANDIDATES, $principal->tenantId, $establishmentId, $id);
        $this->access->audit($principal, 'secondary.official_exam.candidate.photo_updated', 'se_official_exam_candidate', $id);

        return $this->candidateResource($principal->tenantId, $establishmentId, $session, $updated);
    }

    /** Export CSV générique : les en-têtes/champs sont définis par la session, jamais déduits d'un format officiel. */
    public function export(Principal $principal, string $establishmentId, string $sessionId): string
    {
        $session = $this->readableSession($principal, $establishmentId, $sessionId);
        $this->access->require($principal, $establishmentId, self::READ);
        $columns = self::exportColumns($session);
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new \RuntimeException('Flux CSV indisponible.');
        }
        fputcsv($out, array_column($columns, 'header'));
        foreach ($this->store->select(self::CANDIDATES, $principal->tenantId, $establishmentId, ['session_id' => $sessionId], 'full_name', 10000) as $candidate) {
            $resource = $this->candidateResource($principal->tenantId, $establishmentId, $session, $candidate);
            $values = [];
            foreach ($columns as $column) {
                $value = match ($column['field']) {
                    'national_id' => $resource['national_id'],
                    'person_id' => $resource['person_id'],
                    'full_name' => $resource['full_name'],
                    'class_label' => $resource['class_label'],
                    'fee_status' => $resource['fee_status'],
                    'candidate_status' => $resource['status'],
                    'selected_subjects' => implode('|', $resource['selected_subjects']),
                    'result_code' => $resource['result_code'] ?? '',
                    'result_mark' => $resource['result_mark'] ?? '',
                    default => '',
                };
                $values[] = (string) $value;
            }
            fputcsv($out, $values);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        $this->access->audit($principal, 'secondary.official_exam.exported', 'se_official_exam_session', $sessionId, null, ['candidate_count' => $this->store->count(self::CANDIDATES, $principal->tenantId, $establishmentId, ['session_id' => $sessionId])]);

        return "\xEF\xBB\xBF" . (is_string($csv) ? $csv : '');
    }

    /** @param list<array{key: string, result_code: string, result_mark: ?float, line: int}> $rows
     * @return array<string, mixed>
     */
    public function importResults(Principal $principal, string $establishmentId, string $sessionId, array $rows): array
    {
        $session = $this->writableSession($principal, $establishmentId, $sessionId, self::VALIDATE);
        $this->assertOpen($session);
        $byKey = [];
        foreach ($this->store->select(self::CANDIDATES, $principal->tenantId, $establishmentId, ['session_id' => $sessionId], 'id', 10000) as $candidate) {
            $byKey[(string) $candidate['person_id']] = $candidate;
            $nationalId = $this->nationalIds->of($principal->tenantId, $establishmentId, (string) $candidate['person_id']);
            if ($nationalId !== null) {
                $byKey[$nationalId] = $candidate;
            }
        }
        $imported = 0;
        $rejected = [];
        $newlyImported = [];
        $seenKeys = [];
        foreach ($rows as $row) {
            if (isset($seenKeys[$row['key']])) {
                $rejected[] = ['line' => $row['line'], 'key' => $row['key'], 'reason' => 'Identifiant dupliqué dans le fichier.'];
                continue;
            }
            $seenKeys[$row['key']] = true;
            $candidate = $byKey[$row['key']] ?? null;
            if ($candidate === null || $row['result_code'] === '') {
                $rejected[] = ['line' => $row['line'], 'key' => $row['key'], 'reason' => 'Élève absent de la liste de cette session ou code de résultat vide.'];
                continue;
            }
            if ($candidate['result_code'] === null) {
                $newlyImported[(string) $candidate['person_id']] = (string) $candidate['full_name'];
            }
            $this->store->update(self::CANDIDATES, $principal->tenantId, $establishmentId, [
                'result_code' => $row['result_code'], 'result_mark' => $row['result_mark'], 'result_imported_at' => $this->clock->now(), 'updated_at' => $this->clock->now(),
            ], ['id' => (string) $candidate['id']]);
            ++$imported;
        }
        foreach ($newlyImported as $personId => $fullName) {
            foreach ($this->people->guarantorsOf($principal->tenantId, $personId) as $guardian) {
                $this->notifyFamily($principal->tenantId, $establishmentId, $guardian->id, 'sec.not13.exam_result', [
                    'child_name' => $fullName, 'exam_label' => (string) $session['exam_label'], 'link' => self::FAMILY_LINK,
                ]);
            }
        }
        $this->access->audit($principal, 'secondary.official_exam.results.imported', 'se_official_exam_session', $sessionId, null, ['imported' => $imported, 'rejected' => count($rejected)]);

        return ['imported' => $imported, 'rejected' => $rejected, 'summary' => $this->buildSummary($principal->tenantId, $establishmentId, $session)];
    }

    /** @param array<string, mixed> $session */
    private function notifyUnpaid(Principal $principal, string $establishmentId, string $personId, string $fullName, array $session): void
    {
        foreach ($this->people->guarantorsOf($principal->tenantId, $personId) as $guardian) {
            $this->notifyFamily($principal->tenantId, $establishmentId, $guardian->id, 'sec.not10.exam_fees_unpaid', [
                'exam_label' => (string) $session['exam_label'], 'child_name' => $fullName,
                'due_on' => (string) $session['registration_deadline'], 'offset' => '0',
            ]);
        }
    }

    /** @param array<string, scalar> $variables */
    private function notifyFamily(string $tenantId, string $establishmentId, string $recipientId, string $template, array $variables): void
    {
        try {
            $this->messenger->send($tenantId, $establishmentId, $recipientId, $template, $variables, ['sms']);
        } catch (\Throwable) {
        }
    }

    /** @return array<string, mixed> */
    public function summary(Principal $principal, string $establishmentId, string $sessionId): array
    {
        $session = $this->readableSession($principal, $establishmentId, $sessionId);
        $this->access->require($principal, $establishmentId, self::READ);

        return $this->buildSummary($principal->tenantId, $establishmentId, $session);
    }

    /** @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function buildSummary(string $tenantId, string $establishmentId, array $session): array
    {
        $candidates = $this->store->select(self::CANDIDATES, $tenantId, $establishmentId, ['session_id' => (string) $session['id']], 'id', 10000);
        $codes = self::stringList($session['passing_codes'] ?? '[]');
        $withResult = 0;
        $passed = 0;
        $marks = [];
        $outcomes = [];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate['result_code']) || $candidate['result_code'] === '') {
                continue;
            }
            ++$withResult;
            $outcomes[(string) $candidate['result_code']] = ($outcomes[(string) $candidate['result_code']] ?? 0) + 1;
            if (in_array((string) $candidate['result_code'], $codes, true)) {
                ++$passed;
            }
            if ($candidate['result_mark'] !== null) {
                $marks[] = (float) $candidate['result_mark'];
            }
        }
        $comparison = null;
        if (is_string($session['comparison_period_id']) && $session['comparison_period_id'] !== '') {
            $classRows = $this->classResults->compute($tenantId, $establishmentId, (string) $session['class_structure_id'], (string) $session['comparison_period_id']);
            $candidateIds = array_fill_keys(array_map(static fn (array $candidate): string => (string) $candidate['person_id'], $candidates), true);
            $classAverages = [];
            foreach ($classRows as $row) {
                $personId = $row['person_id'];
                if (isset($candidateIds[$personId]) && is_numeric($row['average'] ?? null)) {
                    $classAverages[] = (float) $row['average'];
                }
            }
            $examAverage = $marks === [] ? null : array_sum($marks) / count($marks);
            $schoolAverage = $classAverages === [] ? null : array_sum($classAverages) / count($classAverages);
            $comparison = ['period_id' => $session['comparison_period_id'], 'exam_average' => $examAverage === null ? null : round($examAverage, 2), 'school_average' => $schoolAverage === null ? null : round($schoolAverage, 2), 'difference' => $examAverage === null || $schoolAverage === null ? null : round($examAverage - $schoolAverage, 2), 'students_with_school_average' => count($classAverages)];
        }

        return [
            'session_id' => (string) $session['id'], 'candidate_count' => count($candidates), 'results_count' => $withResult,
            'passed_count' => $passed, 'success_rate' => $withResult === 0 || $codes === [] ? null : round(100 * $passed / $withResult, 2),
            'result_counts' => $outcomes, 'passing_codes' => $codes, 'comparison' => $comparison,
        ];
    }

    /** @param array<string, mixed> $session
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function candidateResource(string $tenantId, string $establishmentId, array $session, array $candidate): array
    {
        $personId = (string) $candidate['person_id'];
        $nationalId = $this->nationalIds->of($tenantId, $establishmentId, $personId);
        $paid = $this->examFees->seen($tenantId, $establishmentId, $personId, (string) $session['exam_code']);
        $pieces = self::stringList($candidate['pieces_received'] ?? '[]');
        $subjects = self::stringList($candidate['selected_subjects'] ?? '[]');
        $required = self::stringList($session['required_pieces'] ?? '[]');
        $missingPieces = array_values(array_diff($required, $pieces));
        $photoRef = is_string($candidate['photo_ref']) ? MediaRef::tryFrom((string) $candidate['photo_ref']) : null;
        $photoUrl = $photoRef === null ? null : $this->media->signedUrl($tenantId, $photoRef, 900, self::PHOTO_ROUTE . rawurlencode((string) $candidate['id']) . '/photo');
        $status = !$paid ? 'blocked' : ($nationalId === null || $photoRef === null || $missingPieces !== [] ? 'incomplete' : 'eligible');
        $class = $this->classes->find($tenantId, $establishmentId, (string) $session['class_structure_id']);

        return [
            'id' => (string) $candidate['id'], 'session_id' => (string) $session['id'], 'person_id' => $personId,
            'enrollment_id' => (string) $candidate['enrollment_id'], 'full_name' => (string) $candidate['full_name'],
            'class_id' => (string) $session['class_structure_id'], 'class_label' => $class === null ? '' : $class->label,
            'national_id' => $nationalId, 'national_id_status' => $this->nationalIds->statusOf($tenantId, $establishmentId, $personId),
            'photo_ref' => $photoRef?->value, 'photo_url' => $photoUrl, 'fee_status' => $paid ? 'seen' : 'not_seen',
            'missing_pieces' => $missingPieces, 'pieces_received' => $pieces, 'selected_subjects' => $subjects,
            'result_code' => is_string($candidate['result_code']) ? $candidate['result_code'] : null,
            'result_mark' => $candidate['result_mark'] === null ? null : (float) $candidate['result_mark'], 'status' => $status,
            'status_message' => !$paid ? 'frais non constatés' : ($status === 'incomplete' ? 'dossier incomplet' : 'dossier prêt'),
        ];
    }

    /** @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function insertCandidate(Principal $principal, string $establishmentId, array $session, EnrollmentInfo $enrollment, string $fullName): array
    {
        $now = $this->clock->now();
        $row = [
            'id' => (string) new Ulid(), 'session_id' => (string) $session['id'], 'enrollment_id' => $enrollment->id, 'person_id' => $enrollment->personId,
            'class_structure_id' => (string) $session['class_structure_id'],
            'full_name' => $fullName, 'photo_ref' => null, 'pieces_received' => '[]', 'selected_subjects' => '[]',
            'result_code' => null, 'result_mark' => null, 'result_imported_at' => null, 'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ];
        $this->store->insert(self::CANDIDATES, $principal->tenantId, $establishmentId, $row);

        return $this->store->findOrFail(self::CANDIDATES, $principal->tenantId, $establishmentId, $row['id']);
    }

    /** @param array<string, mixed> $query
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function photo(Principal $principal, string $establishmentId, string $id, array $query): array
    {
        [$candidate, $session] = $this->readableCandidate($principal, $establishmentId, $id);
        $ref = MediaRef::tryFrom((string) ($query['ref'] ?? ''));
        $expires = filter_var($query['expires'] ?? null, FILTER_VALIDATE_INT);
        $signature = $query['signature'] ?? null;
        if ($ref === null || $expires === false || !is_string($signature) || $candidate['photo_ref'] !== $ref->value) {
            throw HttpException::notFound('Photo de candidat introuvable.');
        }
        $content = $this->media->readSigned($principal->tenantId, $ref, $expires, $signature);
        if (!str_starts_with($content->mime, 'image/')) {
            throw HttpException::notFound('Photo de candidat introuvable.');
        }

        return [$candidate, ['content' => $content->content, 'mime' => $content->mime, 'session' => $session]];
    }

    /** @param list<string> $permissions
     * @return array<string, mixed>
     */
    private function writableSession(Principal $principal, string $establishmentId, string $id, array $permissions = self::MANAGE): array
    {
        $session = $this->readableSession($principal, $establishmentId, $id);
        $perimeter = $this->access->require($principal, $establishmentId, $permissions);
        $this->access->assertWritableClass($principal, $perimeter, (string) $session['class_structure_id'], 'se_official_exam_session', $id);

        return $session;
    }

    /** @return array<string, mixed> */
    private function readableSession(Principal $principal, string $establishmentId, string $id): array
    {
        $this->modules($principal->tenantId, $establishmentId);
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $session = $this->store->findOrFail(self::SESSIONS, $principal->tenantId, $establishmentId, $id, 'Session d’examen introuvable.');
        $this->access->assertReadableClass($perimeter, (string) $session['class_structure_id']);

        return $session;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function readableCandidate(Principal $principal, string $establishmentId, string $id): array
    {
        $candidate = $this->store->findOrFail(self::CANDIDATES, $principal->tenantId, $establishmentId, $id, 'Candidat introuvable.');
        $session = $this->readableSession($principal, $establishmentId, (string) $candidate['session_id']);

        return [$candidate, $session];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function writableCandidate(Principal $principal, string $establishmentId, string $id): array
    {
        [$candidate, $session] = $this->readableCandidate($principal, $establishmentId, $id);
        $this->writableSession($principal, $establishmentId, (string) $session['id']);

        return [$candidate, $session];
    }

    private function class(string $tenantId, string $establishmentId, string $classId, string $yearId): \Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfileInfo
    {
        $class = $this->classes->find($tenantId, $establishmentId, $classId);
        $structure = $this->tree->structure($tenantId, $establishmentId, $classId);
        if ($class === null || $class->academicYearId !== $yearId || $structure === null || $structure->establishmentId !== $establishmentId) {
            throw HttpException::notFound('Classe d’examen introuvable pour cette année et cet établissement.');
        }

        return $class;
    }

    private function activeEnrollment(string $tenantId, string $establishmentId, string $classId, string $yearId, string $personId): ?EnrollmentInfo
    {
        foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
            if ($enrollment->tenantId === $tenantId && $enrollment->establishmentId === $establishmentId && $enrollment->structureId === $classId
                && $enrollment->academicYearId === $yearId && $enrollment->status === EnrollmentInfo::ACTIVE) {
                return $enrollment;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $session */
    private function assertOpen(array $session): void
    {
        if ($session['status'] !== ExamRules::SESSION_OPEN) {
            throw HttpException::conflict('Session close : aucune modification de candidat.');
        }
    }

    private function modules(string $tenantId, string $establishmentId): void
    {
        $profile = $this->profiles->forEstablishment($tenantId, $establishmentId);
        if (!$profile->isActive(Modules::OFFICIAL_EXAMS) && !$profile->isReadOnly(Modules::OFFICIAL_EXAMS)) {
            throw HttpException::conflict('Le module official_exams n’est pas activé.');
        }
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) && array_is_list($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function sessionResource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'exam_code' => (string) $row['exam_code'], 'exam_label' => (string) $row['exam_label'],
            'academic_year_id' => (string) $row['academic_year_id'], 'class_structure_id' => (string) $row['class_structure_id'],
            'comparison_period_id' => is_string($row['comparison_period_id']) ? $row['comparison_period_id'] : null,
            'registration_deadline' => is_string($row['registration_deadline']) ? $row['registration_deadline'] : null,
            'exam_on' => is_string($row['exam_on']) ? $row['exam_on'] : null,
            'required_pieces' => self::stringList($row['required_pieces']), 'optional_subjects' => self::stringList($row['optional_subjects']),
            'export_columns' => self::exportColumns($row), 'passing_codes' => self::stringList($row['passing_codes']), 'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'], 'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @param array<string, mixed> $session
     * @return list<array{field: string, header: string}>
     */
    private static function exportColumns(array $session): array
    {
        $columns = json_decode((string) ($session['export_columns'] ?? '[]'), true);
        if (!is_array($columns) || !array_is_list($columns) || $columns === []) {
            return [
                ['field' => 'full_name', 'header' => 'full_name'], ['field' => 'national_id', 'header' => 'national_id'],
                ['field' => 'candidate_status', 'header' => 'candidate_status'], ['field' => 'result_code', 'header' => 'result_code'],
                ['field' => 'result_mark', 'header' => 'result_mark'],
            ];
        }

        return array_values(array_filter($columns, static fn (mixed $column): bool => is_array($column) && is_string($column['field'] ?? null) && is_string($column['header'] ?? null)));
    }

    private function dates(?string $deadline, ?string $examOn): void
    {
        foreach (['registration_deadline' => $deadline, 'exam_on' => $examOn] as $field => $value) {
            if ($value !== null && (\DateTimeImmutable::createFromFormat('!Y-m-d', $value) === false || \DateTimeImmutable::createFromFormat('!Y-m-d', $value)->format('Y-m-d') !== $value)) {
                throw HttpException::validation([$field => ['Date au format AAAA-MM-JJ attendue.']]);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Exams\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Domain\ExamResults;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Infrastructure\PdoExams;
use Scholaris\Modules\Apps\PublicPrimary\Support\PrimaryClock;
use Scholaris\Modules\Apps\PublicPrimary\Support\Rules;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PublicMessages;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PupilRegistry;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/**
 * Résultats et attestations (EX-EXA-05, 06, T683, T684, API-10, DOC-16, DOC-17, NOT-09) :
 *  - import des résultats OFFICIELS (admis, ajourné, absent) par la délégation départementale (R06) ou la direction des examens (R02),
 *    sur les candidats validés de son territoire ; aucune note, aucun calcul d'admission (épreuves, coefficients et note d'admission
 *    non publiés : paramètres « à confirmer ») ;
 *  - taux de réussite = admis ÷ candidats présents, par école et par territoire ;
 *  - publication aux familles (NOT-09) ; relevé de l'école DOC-17 ;
 *  - attestations de réussite DOC-16 préparées par l'application, signées à la main par le délégué départemental qui en saisit le
 *    statut (Q17). L'application ne délivre JAMAIS le CEP ni le FSLC (RG-EXA-3) : le modèle le dit en toutes lettres.
 */
final class ExamResultService
{
    public const RESULT_TEMPLATE = 'pub.not09.result';
    public const ATTESTATION_TEMPLATE = 'pub.not09.parent';
    public const ATTESTATION_TYPE = 'pp_doc16_attestation';
    public const STATEMENT_TYPE = 'pp_doc17_exam_results';
    private const LABELS = [
        'fr' => [ExamResults::PASSED => 'admis', ExamResults::FAILED => 'ajourné', ExamResults::ABSENT => 'absent'],
        'en' => [ExamResults::PASSED => 'passed', ExamResults::FAILED => 'failed', ExamResults::ABSENT => 'absent'],
    ];

    public function __construct(
        private readonly PdoExams $repository,
        private readonly ExamAccess $access,
        private readonly ExamSupport $support,
        private readonly PupilRegistry $pupils,
        private readonly PublicMessages $messages,
        private readonly Rules $rules,
        private readonly PrimaryClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * API-10 : import des résultats officiels sur un territoire (`territory_id`) : lignes `rows` (liste JSON) ou `csv` (texte).
     * Clé : numéro de candidat, à défaut identifiant national. Les lignes invalides sont rejetées avec leur motif.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function import(Principal $principal, string $establishmentId, string $sessionId, array $input): array
    {
        $territoryId = $this->access->territoryInput($principal, $establishmentId, $input, ExamAccess::IMPORT);
        $scope = $this->access->territory($principal, $establishmentId, $territoryId, ExamAccess::IMPORT);
        $tenantId = $principal->tenantId;
        $this->support->session($tenantId, $sessionId);
        if (is_string($input['csv'] ?? null)) {
            $rows = ExamResults::fromCsv((string) $input['csv']);
        } elseif (is_array($input['rows'] ?? null) && array_is_list($input['rows'])) {
            $rows = ExamResults::fromList($input['rows']);
        } else {
            throw HttpException::validation(['rows' => ['Lignes `rows` (liste) ou texte `csv` attendus.']]);
        }
        $byNumber = [];
        $byPerson = [];
        foreach ($this->repository->candidatesIn($tenantId, $sessionId, $scope['establishments']) as $candidate) {
            if (is_string($candidate['candidate_number']) && $candidate['candidate_number'] !== '') {
                $byNumber[$candidate['candidate_number']] = $candidate;
            }
            $byPerson[(string) $candidate['person_id']] = $candidate;
        }
        $imported = 0;
        $rejected = [];
        $now = $this->clock->now();
        foreach ($rows as $row) {
            $candidate = $row['candidate_number'] !== '' ? ($byNumber[$row['candidate_number']] ?? null) : null;
            if ($candidate === null && $row['learner_number'] !== '') {
                $pupil = $this->pupils->findByLearnerNumber($tenantId, $row['learner_number']);
                $candidate = $pupil === null ? null : ($byPerson[$pupil->personId] ?? null);
            }
            $reason = match (true) {
                $candidate === null => 'Candidat inconnu dans cette session et ce territoire.',
                $candidate['candidate_state'] !== 'validated' => 'Candidat non validé (état ' . $candidate['candidate_state'] . ').',
                $row['result'] === null => 'Résultat attendu : passed, failed ou absent (admis, ajourné, absent).',
                default => null,
            };
            if ($reason !== null) {
                $rejected[] = ['line' => $row['line'], 'key' => $row['candidate_number'] !== '' ? $row['candidate_number'] : $row['learner_number'], 'reason' => (string) $reason];
                continue;
            }
            $this->repository->update('pp_exam_candidates', $tenantId, (string) $candidate['id'], [
                'outcome' => $row['result'], 'was_present' => ExamResults::isPresent((string) $row['result']) ? 1 : 0, 'result_imported_at' => $now, 'updated_at' => $now,
            ]);
            ++$imported;
        }
        $this->audit->append($tenantId, $principal->userId, 'public_primary.exams.results_imported', 'pp_exam_session', $sessionId, null, ['territory_id' => $territoryId, 'imported' => $imported, 'rejected' => count($rejected)]);

        return ['imported' => $imported, 'rejected' => $rejected, 'summary' => $this->summaryOf($this->repository->candidatesIn($tenantId, $sessionId, $scope['establishments']), true)];
    }

    /**
     * Publication des résultats importés d'un territoire : NOT-09 aux familles (une fois par candidat).
     *
     * @param array<string, mixed> $input territory_id
     * @return array<string, mixed>
     */
    public function publish(Principal $principal, string $establishmentId, string $sessionId, array $input): array
    {
        $territoryId = $this->access->territoryInput($principal, $establishmentId, $input, ExamAccess::IMPORT);
        $scope = $this->access->territory($principal, $establishmentId, $territoryId, ExamAccess::IMPORT);
        $tenantId = $principal->tenantId;
        $session = $this->support->session($tenantId, $sessionId);
        $now = $this->clock->now();
        $published = 0;
        $messages = 0;
        foreach ($this->repository->candidatesIn($tenantId, $sessionId, $scope['establishments']) as $candidate) {
            if ($candidate['outcome'] === null || $candidate['result_published_at'] !== null) {
                continue;
            }
            $this->repository->update('pp_exam_candidates', $tenantId, (string) $candidate['id'], ['result_published_at' => $now, 'updated_at' => $now]);
            ++$published;
            $pupil = $this->pupils->find($tenantId, (string) $candidate['person_id']);
            $messages += $this->messages->notifyGuardians($tenantId, (string) $candidate['establishment_id'], (string) $candidate['person_id'], self::RESULT_TEMPLATE, [
                'child_name' => $pupil === null ? '' : $pupil->firstName . ' ' . $pupil->lastName,
                'exam_label' => $this->support->examLabel($session), 'details' => self::LABELS['fr'][(string) $candidate['outcome']] ?? (string) $candidate['outcome'],
            ]);
        }
        $this->audit->append($tenantId, $principal->userId, 'public_primary.exams.results_published', 'pp_exam_session', $sessionId, null, ['territory_id' => $territoryId, 'published' => $published]);

        return ['published' => $published, 'messages' => $messages];
    }

    /**
     * Résultats de l'école : compteurs et taux (admis ÷ présents) ; l'école ne les voit qu'une fois publiés.
     *
     * @return array<string, mixed>
     */
    public function school(Principal $principal, string $establishmentId, string $sessionId): array
    {
        $reach = $this->access->reader($principal, $establishmentId);
        $tenantId = $principal->tenantId;
        $session = $this->support->session($tenantId, $sessionId);
        $supervision = $reach->isTerritorial() || $this->access->isNational($reach);
        $rows = $this->repository->candidatesIn($tenantId, $sessionId, [$establishmentId]);

        return ['session' => ExamSupport::sessionResource($session), 'summary' => $this->summaryOf($rows, $supervision)];
    }

    /**
     * Taux de réussite par école et pour le territoire (EX-EXA-05), en effectifs : aucune donnée nominative.
     *
     * @return array<string, mixed>
     */
    public function territory(Principal $principal, string $establishmentId, string $sessionId, string $territoryId): array
    {
        $scope = $this->access->territory($principal, $establishmentId, $territoryId, ExamAccess::VALIDATE, ExamAccess::CONSOLIDATE, ExamAccess::IMPORT);
        $tenantId = $principal->tenantId;
        $session = $this->support->session($tenantId, $sessionId);
        $bySchool = [];
        $all = $this->repository->candidatesIn($tenantId, $sessionId, $scope['establishments']);
        foreach ($all as $row) {
            $bySchool[(string) $row['establishment_id']][] = $row;
        }
        $schools = [];
        foreach ($bySchool as $schoolId => $rows) {
            $schools[] = ['establishment_id' => (string) $schoolId, 'official_code' => $this->support->schoolCode($tenantId, (string) $schoolId), 'summary' => $this->summaryOf($rows, true)];
        }

        return ['session' => ExamSupport::sessionResource($session), 'territory_id' => $territoryId, 'schools' => $schools, 'summary' => $this->summaryOf($all, true)];
    }

    /**
     * DOC-17 : relevé des résultats de l'école (après publication).
     *
     * @return array<string, mixed>
     */
    public function statement(Principal $principal, string $establishmentId, string $sessionId, string $locale): array
    {
        $this->access->reader($principal, $establishmentId, ExamAccess::WRITE, ExamAccess::IMPORT);
        $tenantId = $principal->tenantId;
        $session = $this->support->session($tenantId, $sessionId);
        $rows = array_values(array_filter($this->repository->candidatesIn($tenantId, $sessionId, [$establishmentId]), static fn (array $row): bool => $row['result_published_at'] !== null));
        if ($rows === []) {
            throw HttpException::conflict('Aucun résultat publié pour cette école.');
        }
        $en = $locale === 'en';
        $labels = self::LABELS[$en ? 'en' : 'fr'];
        $lines = [];
        foreach ($rows as $row) {
            $candidate = $this->support->candidate($tenantId, $row);
            $lines[] = sprintf('%-12s %-16s %-34s %s', (string) ($row['candidate_number'] ?? '-'), (string) ($candidate['learner_number'] ?? '-'), mb_strtoupper((string) $candidate['last_name']) . ' ' . $candidate['first_name'], $labels[(string) $row['outcome']] ?? (string) $row['outcome']);
        }
        $summary = $this->summaryOf($rows, false);
        $document = $this->support->issue($principal, $establishmentId, self::STATEMENT_TYPE, null, [
            'exam_label' => $this->support->examLabel($session), 'school_year' => (string) $session['year_label'], 'results_block' => implode("\n", $lines),
            'summary_line' => sprintf($en ? 'Candidates: %d · present: %d · passed: %d · success rate (passed / present): %s %%' : 'Candidats : %d · présents : %d · admis : %d · taux de réussite (admis ÷ présents) : %s %%', $summary['candidates'], $summary['present'], $summary['passed'], $summary['success_rate'] === null ? '-' : number_format((float) $summary['success_rate'], 1, $en ? '.' : ',', ' ')),
        ], $locale);

        return ['document' => ['id' => $document->id, 'number' => $document->number]];
    }

    /**
     * Prépare les attestations DOC-16 des admis publiés de l'école (une par candidat, jamais un diplôme).
     *
     * @return array<string, mixed>
     */
    public function prepareAttestations(Principal $principal, string $establishmentId, string $sessionId, string $locale): array
    {
        $reach = $this->access->reader($principal, $establishmentId, ExamAccess::IMPORT, ExamAccess::ATTEST);
        if ($reach->isInSchool() && !$this->access->isNational($reach)) {
            throw HttpException::forbidden('Les attestations sont préparées par la délégation (DOC-16).');
        }
        $tenantId = $principal->tenantId;
        $session = $this->support->session($tenantId, $sessionId);
        if ($session['exam_kind'] !== 'certificate') {
            throw HttpException::conflict('Un concours d\'entrée ne donne pas lieu à une attestation de réussite.');
        }
        $signer = $this->rules->string($tenantId, $establishmentId, 'public_primary.exams.attestation_signer');
        $prepared = [];
        foreach ($this->repository->candidatesIn($tenantId, $sessionId, [$establishmentId]) as $row) {
            if ($row['outcome'] !== ExamResults::PASSED || $row['result_published_at'] === null || $row['attestation_document_id'] !== null) {
                continue;
            }
            $candidate = $this->support->candidate($tenantId, $row);
            $document = $this->support->issue($principal, $establishmentId, self::ATTESTATION_TYPE, (string) $row['person_id'], [
                'exam_label' => $this->support->examLabel($session), 'school_year' => (string) $session['year_label'],
                'full_name' => mb_strtoupper((string) $candidate['last_name']) . ' ' . $candidate['first_name'], 'learner_number' => (string) ($candidate['learner_number'] ?? '-'),
                'birth_line' => (string) ($candidate['birth_date'] ?? '-'), 'candidate_number' => (string) ($row['candidate_number'] ?? '-'), 'signer_label' => $signer,
            ], $locale);
            $this->repository->update('pp_exam_candidates', $tenantId, (string) $row['id'], ['attestation_state' => 'prepared', 'attestation_document_id' => $document->id, 'updated_at' => $this->clock->now()]);
            $prepared[] = ['candidate_id' => (string) $row['id'], 'document' => ['id' => $document->id, 'number' => $document->number]];
        }
        $this->audit->append($tenantId, $principal->userId, 'public_primary.exams.attestations_prepared', 'pp_exam_session', $sessionId, null, ['establishment_id' => $establishmentId, 'count' => count($prepared)]);

        return ['prepared' => $prepared];
    }

    /**
     * Statut « signé » saisi par le signataire paramétré (délégué départemental par défaut, Q17) ; la famille est prévenue (NOT-09).
     *
     * @return array<string, mixed>
     */
    public function sign(Principal $principal, string $establishmentId, string $candidateId): array
    {
        $tenantId = $principal->tenantId;
        $reach = $this->access->reader($principal, $establishmentId, ExamAccess::ATTEST);
        $signer = $this->rules->string($tenantId, $establishmentId, 'public_primary.exams.attestation_signer');
        if (!in_array($signer, $reach->roles, true)) {
            throw HttpException::forbidden('L\'attestation est signée par le ' . $signer . ' (DOC-16).');
        }
        $row = $this->repository->candidate($tenantId, $establishmentId, $candidateId) ?? throw HttpException::notFound();
        if ($row['attestation_state'] !== 'prepared') {
            throw HttpException::conflict('Aucune attestation préparée en attente de signature.');
        }
        $now = $this->clock->now();
        $this->repository->update('pp_exam_candidates', $tenantId, $candidateId, ['attestation_state' => 'signed', 'attestation_signed_by' => $principal->userId, 'attestation_signed_at' => $now, 'updated_at' => $now]);
        $session = $this->support->session($tenantId, (string) $row['session_id']);
        $pupil = $this->pupils->find($tenantId, (string) $row['person_id']);
        $this->messages->notifyGuardians($tenantId, $establishmentId, (string) $row['person_id'], self::ATTESTATION_TEMPLATE, [
            'child_name' => $pupil === null ? '' : $pupil->firstName . ' ' . $pupil->lastName, 'exam_label' => $this->support->examLabel($session),
        ]);
        $this->audit->append($tenantId, $principal->userId, 'public_primary.exams.attestation_signed', 'pp_exam_candidate', $candidateId, null, ['document_id' => $row['attestation_document_id']]);

        return $this->support->candidate($tenantId, $this->repository->candidate($tenantId, $establishmentId, $candidateId) ?? $row, true);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{candidates: int, present: int, passed: int, failed: int, absent: int, success_rate: float|null, pending: int}
     */
    private function summaryOf(array $rows, bool $withUnpublished): array
    {
        $counts = [];
        $pending = 0;
        foreach ($rows as $row) {
            if ($row['candidate_state'] !== 'validated') {
                continue;
            }
            $outcome = $row['outcome'];
            if ($outcome === null || (!$withUnpublished && $row['result_published_at'] === null)) {
                ++$pending;
                continue;
            }
            $counts[(string) $outcome] = ($counts[(string) $outcome] ?? 0) + 1;
        }

        return ExamResults::summary($counts) + ['pending' => $pending];
    }
}

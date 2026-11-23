<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Exams\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Primary\Academic\Contracts\LevelDirectory;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\ClassRoster;
use Scholaris\Modules\Apps\Primary\Support\Guardianship;
use Scholaris\Modules\Apps\Primary\Support\PrimaryClock;
use Scholaris\Modules\Apps\Primary\Support\PrimarySettings;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeInvoice;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeLine;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDemographics;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Examen de fin de cycle (EX-PRI-42 ; Q11, à confirmer) : préparation par l'école du CEP et du FSLC de ses élèves.
 *  - session : examen choisi parmi `primary.exam.kinds` (CEP francophone, FSLC anglophone par défaut), niveau d'examen, centre et
 *    sous-centre de composition, période d'inscription, date des épreuves, frais facultatifs (code du catalogue) ;
 *  - candidats : inscription en lot des élèves du niveau, retrait conservé, numéro (saisi ou attribué par l'école avec le préfixe
 *    paramétré), fiche d'inscription aux champs paramétrés (`primary.exam.registration_fields`) ;
 *  - résultats : seulement le résultat OFFICIEL publié (admis, ajourné, absent), saisi ou importé ; aucune note ni barème inventés ;
 *    publication aux familles (NOT-PRI-09) ; taux de réussite = admis / (admis + ajournés).
 */
final class ExamService
{
    public const SESSIONS = 'pri_exam_sessions';
    public const CANDIDATES = 'pri_exam_candidates';
    public const READ = ['primary.exam.read', 'primary.exam.manage'];
    public const MANAGE = ['primary.exam.manage'];
    public const STATUSES = ['draft', 'open', 'closed'];
    public const OUTCOMES = ['pending', 'passed', 'failed', 'absent'];
    public const TEMPLATE = 'pri.not09.exam_results';
    private const TRANSITIONS = ['draft' => ['open'], 'open' => ['closed', 'draft'], 'closed' => ['open']];
    private const OUTCOME_WORDS = [
        'passed' => 'passed', 'admis' => 'passed', 'admise' => 'passed', 'pass' => 'passed',
        'failed' => 'failed', 'ajourne' => 'failed', 'ajournee' => 'failed', 'fail' => 'failed', 'echec' => 'failed',
        'absent' => 'absent', 'absente' => 'absent', 'pending' => 'pending',
    ];

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly ClassRoster $classes,
        private readonly PrimarySettings $settings,
        private readonly LevelDirectory $levels,
        private readonly OrgUnitTree $tree,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly PersonDemographics $demographics,
        private readonly InvoiceIssuer $invoices,
        private readonly Messenger $messenger,
        private readonly Guardianship $family,
        private readonly PrimaryClock $clock,
    ) {
    }

    /** @return array<string, mixed> examens proposés et champs de la fiche (paramètres) */
    public function catalogue(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, self::READ);
        $t = $principal->tenantId;

        return ['kinds' => $this->kinds($t, $establishmentId), 'fields' => $this->fields($t, $establishmentId), 'outcomes' => self::OUTCOMES];
    }

    /** @return list<array<string, mixed>> */
    public function sessions(Principal $principal, string $establishmentId, ?string $yearId): array
    {
        $this->access->require($principal, $establishmentId, self::READ);
        $rows = $this->store->select(self::SESSIONS, $principal->tenantId, $establishmentId, $yearId === null ? [] : ['academic_year_id' => $yearId], 'academic_year_id DESC, exam_code', 200);

        return array_map(fn (array $r): array => self::sessionResource($r) + ['stats' => $this->stats($principal->tenantId, $establishmentId, (string) $r['id'])], $rows);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createSession(Principal $principal, string $establishmentId, array $input): array
    {
        $this->manage($principal, $establishmentId);
        $t = $principal->tenantId;
        $kinds = $this->kinds($t, $establishmentId);
        $code = (string) $input['exam_code'];
        if (!isset($kinds[$code])) {
            throw HttpException::validation(['exam_code' => ['Valeurs possibles : ' . implode(', ', array_keys($kinds)) . ' (paramètre primary.exam.kinds).']]);
        }
        $level = (string) $input['level_structure_id'];
        $profile = $this->levels->profile($t, $establishmentId, $level);
        if ($profile === null || !$profile->isExamLevel) {
            throw HttpException::validation(['level_structure_id' => ['Ce niveau n\'est pas marqué « niveau d\'examen » dans les profils de niveau.']]);
        }
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert(self::SESSIONS, $t, $establishmentId, [
            'id' => $id, 'academic_year_id' => (string) $input['academic_year_id'], 'exam_code' => $code,
            'label' => (string) ($input['label'] ?? $kinds[$code]['fr']), 'level_structure_id' => $level,
            'centre_label' => $input['centre_label'] ?? null, 'sub_centre_label' => $input['sub_centre_label'] ?? null,
            'registration_opens_on' => $input['registration_opens_on'] ?? null, 'registration_closes_on' => $input['registration_closes_on'] ?? null,
            'written_on' => $input['written_on'] ?? null, 'fee_code' => $input['fee_code'] ?? null, 'status' => 'draft', 'results_published_at' => null,
            'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'primary.exam.session_created', self::SESSIONS, $id, null, ['exam_code' => $code]);

        return $this->session($principal, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function session(Principal $principal, string $establishmentId, string $id): array
    {
        $this->access->require($principal, $establishmentId, self::READ);
        $row = $this->store->findOrFail(self::SESSIONS, $principal->tenantId, $establishmentId, $id, 'Session d\'examen introuvable.');

        return self::sessionResource($row) + ['stats' => $this->stats($principal->tenantId, $establishmentId, $id)];
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updateSession(Principal $principal, string $establishmentId, string $id, array $changes): array
    {
        $this->manage($principal, $establishmentId);
        $t = $principal->tenantId;
        $this->store->findOrFail(self::SESSIONS, $t, $establishmentId, $id, 'Session d\'examen introuvable.');
        if ($changes !== []) {
            $this->store->update(self::SESSIONS, $t, $establishmentId, $changes + ['updated_at' => $this->clock->now()], ['id' => $id]);
        }

        return $this->session($principal, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function transition(Principal $principal, string $establishmentId, string $id, string $status): array
    {
        $this->manage($principal, $establishmentId);
        $t = $principal->tenantId;
        $row = $this->store->findOrFail(self::SESSIONS, $t, $establishmentId, $id, 'Session d\'examen introuvable.');
        if (!in_array($status, self::TRANSITIONS[(string) $row['status']] ?? [], true)) {
            throw HttpException::conflict(sprintf('Passage de « %s » à « %s » impossible.', (string) $row['status'], $status));
        }
        $this->store->update(self::SESSIONS, $t, $establishmentId, ['status' => $status, 'updated_at' => $this->clock->now()], ['id' => $id]);
        $this->access->audit($principal, 'primary.exam.session_status', self::SESSIONS, $id, ['status' => (string) $row['status']], ['status' => $status]);

        return $this->session($principal, $establishmentId, $id);
    }

    /** @return list<array<string, mixed>> */
    public function candidates(Principal $principal, string $establishmentId, string $sessionId): array
    {
        $this->access->require($principal, $establishmentId, self::READ);
        $t = $principal->tenantId;
        $this->store->findOrFail(self::SESSIONS, $t, $establishmentId, $sessionId, 'Session d\'examen introuvable.');
        $rows = $this->store->select(self::CANDIDATES, $t, $establishmentId, ['session_id' => $sessionId], 'id', 5000);
        $names = $this->classes->names($t, array_map(static fn (array $r): string => (string) $r['person_id'], $rows));
        $out = array_map(static fn (array $r): array => self::candidateResource($r) + ['name' => $names[(string) $r['person_id']] ?? (string) $r['person_id']], $rows);
        usort($out, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $out;
    }

    /** @return array{registered: int, existing: int} inscription en lot des élèves actifs des classes du niveau d'examen */
    public function enrolLevel(Principal $principal, string $establishmentId, string $sessionId): array
    {
        $this->manage($principal, $establishmentId);
        $t = $principal->tenantId;
        $session = $this->registrable($t, $establishmentId, $sessionId);
        $counts = ['registered' => 0, 'existing' => 0];
        foreach ($this->tree->structureDescendantsOf($t, $establishmentId, (string) $session['level_structure_id']) as $structureId) {
            $structure = $this->tree->structure($t, $establishmentId, $structureId);
            if ($structure === null || $structure->kind !== 'class') {
                continue;
            }
            foreach ($this->enrollments->personIdsInStructure($t, $structureId) as $personId) {
                $enrollment = $this->enrollmentOf($t, $establishmentId, $personId, (string) $session['academic_year_id'], $structureId);
                if ($enrollment === null) {
                    continue;
                }
                if ($this->store->select(self::CANDIDATES, $t, $establishmentId, ['session_id' => $sessionId, 'person_id' => $personId], 'id', 1) !== []) {
                    ++$counts['existing'];
                    continue;
                }
                $this->insertCandidate($t, $establishmentId, $sessionId, $personId, $enrollment, $structureId);
                ++$counts['registered'];
            }
        }
        $this->access->audit($principal, 'primary.exam.level_enrolled', self::SESSIONS, $sessionId, null, $counts);

        return $counts;
    }

    /** @return array<string, mixed> */
    public function addCandidate(Principal $principal, string $establishmentId, string $sessionId, string $personId): array
    {
        $this->manage($principal, $establishmentId);
        $t = $principal->tenantId;
        $session = $this->registrable($t, $establishmentId, $sessionId);
        $existing = $this->store->select(self::CANDIDATES, $t, $establishmentId, ['session_id' => $sessionId, 'person_id' => $personId], 'id', 1)[0] ?? null;
        if ($existing !== null && $existing['registration_status'] === 'registered') {
            throw HttpException::conflict('Cet élève est déjà candidat à cette session.');
        }
        if ($existing !== null) {
            $this->store->update(self::CANDIDATES, $t, $establishmentId, ['registration_status' => 'registered', 'withdrawn_at' => null, 'updated_at' => $this->clock->now()], ['id' => (string) $existing['id']]);

            return self::candidateResource($this->store->findOrFail(self::CANDIDATES, $t, $establishmentId, (string) $existing['id']));
        }
        $enrollment = null;
        $class = null;
        foreach ($this->enrollments->forPerson($t, $personId) as $e) {
            if ($e->establishmentId === $establishmentId && $e->academicYearId === (string) $session['academic_year_id'] && in_array($e->status, ['active', 'conditional'], true)
                && $e->structureId !== null && $this->tree->levelOf($t, $establishmentId, $e->structureId) === (string) $session['level_structure_id']) {
                $enrollment = $e->id;
                $class = $e->structureId;
            }
        }
        if ($enrollment === null) {
            throw HttpException::validation(['person_id' => ['Cet élève n\'est pas inscrit cette année dans le niveau d\'examen.']]);
        }
        $id = $this->insertCandidate($t, $establishmentId, $sessionId, $personId, $enrollment, $class);

        return self::candidateResource($this->store->findOrFail(self::CANDIDATES, $t, $establishmentId, $id));
    }

    /**
     * @param array<string, mixed> $changes candidate_number, sub_centre_label, fields
     * @return array<string, mixed>
     */
    public function updateCandidate(Principal $principal, string $establishmentId, string $candidateId, array $changes): array
    {
        $this->manage($principal, $establishmentId);
        $t = $principal->tenantId;
        $row = $this->store->findOrFail(self::CANDIDATES, $t, $establishmentId, $candidateId, 'Candidat introuvable.');
        $set = [];
        if (array_key_exists('candidate_number', $changes)) {
            $number = $changes['candidate_number'];
            if ($number !== null && $this->numberTaken($t, $establishmentId, (string) $row['session_id'], (string) $number, $candidateId)) {
                throw HttpException::conflict('Ce numéro de candidat est déjà attribué dans cette session.');
            }
            $set['candidate_number'] = $number;
        }
        if (array_key_exists('sub_centre_label', $changes)) {
            $set['sub_centre_label'] = $changes['sub_centre_label'];
        }
        if (isset($changes['fields']) && is_array($changes['fields'])) {
            $known = array_column($this->fields($t, $establishmentId), null, 'code');
            $current = json_decode((string) ($row['fields_json'] ?? '{}'), true);
            $values = is_array($current) ? $current : [];
            foreach ($changes['fields'] as $code => $value) {
                if (!isset($known[$code])) {
                    throw HttpException::validation(['fields.' . $code => ['Champ inconnu de la fiche (paramètre primary.exam.registration_fields).']]);
                }
                if ($value !== null && (!is_string($value) || mb_strlen($value) > 160)) {
                    throw HttpException::validation(['fields.' . $code => ['Texte de 160 caractères au plus attendu.']]);
                }
                $values[$code] = $value;
            }
            $set['fields_json'] = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        if ($set !== []) {
            $this->store->update(self::CANDIDATES, $t, $establishmentId, $set + ['updated_at' => $this->clock->now()], ['id' => $candidateId]);
        }

        return self::candidateResource($this->store->findOrFail(self::CANDIDATES, $t, $establishmentId, $candidateId));
    }

    /** @return array<string, mixed> retrait : la ligne est conservée (historique), jamais supprimée */
    public function withdraw(Principal $principal, string $establishmentId, string $candidateId, string $reason): array
    {
        $this->manage($principal, $establishmentId);
        $t = $principal->tenantId;
        $row = $this->store->findOrFail(self::CANDIDATES, $t, $establishmentId, $candidateId, 'Candidat introuvable.');
        if ($row['registration_status'] !== 'registered') {
            throw HttpException::conflict('Ce candidat est déjà retiré.');
        }
        $now = $this->clock->now();
        $this->store->update(self::CANDIDATES, $t, $establishmentId, ['registration_status' => 'withdrawn', 'withdrawal_reason' => $reason, 'withdrawn_at' => $now, 'updated_at' => $now], ['id' => $candidateId]);
        $this->access->audit($principal, 'primary.exam.candidate_withdrawn', self::CANDIDATES, $candidateId, null, ['reason' => $reason]);

        return self::candidateResource($this->store->findOrFail(self::CANDIDATES, $t, $establishmentId, $candidateId));
    }

    /** @return array{assigned: int} numéros attribués par l'école (ordre alphabétique) aux candidats qui n'en ont pas */
    public function assignNumbers(Principal $principal, string $establishmentId, string $sessionId): array
    {
        $this->manage($principal, $establishmentId);
        $t = $principal->tenantId;
        $session = $this->store->findOrFail(self::SESSIONS, $t, $establishmentId, $sessionId, 'Session d\'examen introuvable.');
        $prefix = $this->settings->string($t, $establishmentId, 'primary.exam.candidate_number_prefix') ?? strtoupper((string) $session['exam_code']) . '-';
        $all = $this->candidates($principal, $establishmentId, $sessionId);
        $taken = array_filter(array_map(static fn (array $c): ?string => $c['candidate_number'], $all));
        $next = 1;
        $assigned = 0;
        foreach ($all as $candidate) {
            if ($candidate['registration_status'] !== 'registered' || $candidate['candidate_number'] !== null) {
                continue;
            }
            do {
                $number = $prefix . str_pad((string) $next++, 4, '0', STR_PAD_LEFT);
            } while (in_array($number, $taken, true));
            $this->store->update(self::CANDIDATES, $t, $establishmentId, ['candidate_number' => $number, 'updated_at' => $this->clock->now()], ['id' => $candidate['id']]);
            $taken[] = $number;
            ++$assigned;
        }

        return ['assigned' => $assigned];
    }

    /**
     * Résultats officiels saisis ou importés : chaque ligne invalide est rejetée avec son motif, les autres sont enregistrées.
     *
     * @param list<array{candidate_id?: mixed, candidate_number?: mixed, outcome?: mixed, detail?: mixed}> $entries
     * @return array{recorded: int, rejected: list<array{line: int, reason: string}>}
     */
    public function recordResults(Principal $principal, string $establishmentId, string $sessionId, array $entries): array
    {
        $this->manage($principal, $establishmentId);
        $t = $principal->tenantId;
        $session = $this->store->findOrFail(self::SESSIONS, $t, $establishmentId, $sessionId, 'Session d\'examen introuvable.');
        if ($session['status'] !== 'closed') {
            throw HttpException::conflict('Les résultats se saisissent quand les inscriptions sont closes.');
        }
        $byId = [];
        $byNumber = [];
        foreach ($this->store->select(self::CANDIDATES, $t, $establishmentId, ['session_id' => $sessionId], 'id', 5000) as $row) {
            $byId[(string) $row['id']] = $row;
            if ($row['candidate_number'] !== null) {
                $byNumber[(string) $row['candidate_number']] = $row;
            }
        }
        $recorded = 0;
        $rejected = [];
        $now = $this->clock->now();
        foreach ($entries as $i => $entry) {
            $line = $i + 1;
            $candidate = is_string($entry['candidate_id'] ?? null) ? ($byId[$entry['candidate_id']] ?? null) : (is_string($entry['candidate_number'] ?? null) ? ($byNumber[trim($entry['candidate_number'])] ?? null) : null);
            $word = is_string($entry['outcome'] ?? null) ? self::normalise($entry['outcome']) : '';
            $outcome = self::OUTCOME_WORDS[$word] ?? null;
            if ($candidate === null) {
                $rejected[] = ['line' => $line, 'reason' => 'Candidat introuvable dans cette session.'];
            } elseif ($candidate['registration_status'] !== 'registered') {
                $rejected[] = ['line' => $line, 'reason' => 'Candidat retiré.'];
            } elseif ($outcome === null) {
                $rejected[] = ['line' => $line, 'reason' => 'Résultat inconnu : admis, ajourné ou absent attendu.'];
            } else {
                $detail = is_string($entry['detail'] ?? null) && trim($entry['detail']) !== '' ? mb_substr(trim($entry['detail']), 0, 160) : null;
                $this->store->update(self::CANDIDATES, $t, $establishmentId, ['outcome' => $outcome, 'outcome_detail' => $detail, 'recorded_by' => $principal->userId, 'recorded_at' => $now, 'updated_at' => $now], ['id' => (string) $candidate['id']]);
                ++$recorded;
            }
        }
        $this->access->audit($principal, 'primary.exam.results_recorded', self::SESSIONS, $sessionId, null, ['recorded' => $recorded, 'rejected' => count($rejected)]);

        return ['recorded' => $recorded, 'rejected' => $rejected];
    }

    /**
     * Import CSV (« numéro;résultat;mention ») : séparateur « ; » ou « , », en-tête facultatif.
     *
     * @return array{recorded: int, rejected: list<array{line: int, reason: string}>}
     */
    public function importResults(Principal $principal, string $establishmentId, string $sessionId, string $csv): array
    {
        $entries = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($csv)) ?: [] as $i => $raw) {
            $cells = str_getcsv($raw, str_contains($raw, ';') ? ';' : ',');
            if ($i === 0 && self::normalise((string) ($cells[1] ?? '')) !== '' && !isset(self::OUTCOME_WORDS[self::normalise((string) ($cells[1] ?? ''))])) {
                $entries[] = ['candidate_number' => '__header__'];
                continue;
            }
            $entries[] = ['candidate_number' => (string) ($cells[0] ?? ''), 'outcome' => (string) ($cells[1] ?? ''), 'detail' => (string) ($cells[2] ?? '')];
        }
        $result = $this->recordResults($principal, $establishmentId, $sessionId, $entries);
        $result['rejected'] = array_values(array_filter($result['rejected'], static fn (array $r): bool => !($r['line'] === 1 && ($entries[0]['candidate_number'] ?? '') === '__header__')));

        return $result;
    }

    /** @return array{notified: int, published_at: string} */
    public function publishResults(Principal $principal, string $establishmentId, string $sessionId): array
    {
        $this->manage($principal, $establishmentId);
        $t = $principal->tenantId;
        $session = $this->store->findOrFail(self::SESSIONS, $t, $establishmentId, $sessionId, 'Session d\'examen introuvable.');
        $done = array_filter($this->store->select(self::CANDIDATES, $t, $establishmentId, ['session_id' => $sessionId, 'registration_status' => 'registered'], 'id', 5000), static fn (array $r): bool => $r['outcome'] !== 'pending');
        if ($done === []) {
            throw HttpException::conflict('Aucun résultat saisi à publier.');
        }
        $now = $this->clock->now();
        $this->store->update(self::SESSIONS, $t, $establishmentId, ['results_published_at' => $now, 'updated_at' => $now], ['id' => $sessionId]);
        $notified = 0;
        foreach ($done as $row) {
            $child = $this->people->find($t, (string) $row['person_id']);
            foreach ($this->people->guarantorsOf($t, (string) $row['person_id']) as $guardian) {
                $result = $this->messenger->send($t, $establishmentId, $guardian->id, self::TEMPLATE, ['child_name' => $child === null ? '' : $child->fullName(), 'exam_label' => (string) $session['label'], 'link' => '/portail/famille']);
                $notified += $result->status === DeliveryResult::BLOCKED ? 0 : 1;
            }
        }
        $this->access->audit($principal, 'primary.exam.results_published', self::SESSIONS, $sessionId, null, ['notified' => $notified]);

        return ['notified' => $notified, 'published_at' => $now];
    }

    /** @return array{invoiced: int, already: int} facture des frais d'examen (code du catalogue) par candidat, idempotente */
    public function invoice(Principal $principal, string $establishmentId, string $sessionId): array
    {
        $this->manage($principal, $establishmentId);
        $t = $principal->tenantId;
        $session = $this->store->findOrFail(self::SESSIONS, $t, $establishmentId, $sessionId, 'Session d\'examen introuvable.');
        if ($session['fee_code'] === null || $session['fee_code'] === '') {
            throw HttpException::validation(['fee_code' => ['Aucun frais d\'examen n\'est paramétré pour cette session.']]);
        }
        $counts = ['invoiced' => 0, 'already' => 0];
        $due = (string) ($session['registration_closes_on'] ?? $this->clock->today());
        foreach ($this->store->select(self::CANDIDATES, $t, $establishmentId, ['session_id' => $sessionId, 'registration_status' => 'registered'], 'id', 5000) as $row) {
            if ($row['invoice_id'] !== null) {
                ++$counts['already'];
                continue;
            }
            $summary = $this->invoices->forCharges(new ChargeInvoice($t, $establishmentId, $principal->userId, (string) $row['person_id'], (string) $session['academic_year_id'], 'pri-exam:' . (string) $row['id'], [new ChargeLine((string) $session['fee_code'], $due)], null, $row['class_structure_id'] === null ? null : (string) $row['class_structure_id']));
            $this->store->update(self::CANDIDATES, $t, $establishmentId, ['invoice_id' => $summary->id, 'updated_at' => $this->clock->now()], ['id' => (string) $row['id']]);
            ++$counts['invoiced'];
        }

        return $counts;
    }

    /** @return array<string, mixed> fiche d'inscription imprimable */
    public function sheet(Principal $principal, string $establishmentId, string $candidateId): array
    {
        $this->access->require($principal, $establishmentId, self::READ);
        $t = $principal->tenantId;
        $row = $this->store->findOrFail(self::CANDIDATES, $t, $establishmentId, $candidateId, 'Candidat introuvable.');
        $session = $this->store->findOrFail(self::SESSIONS, $t, $establishmentId, (string) $row['session_id']);
        $person = $this->people->find($t, (string) $row['person_id']);
        $demo = $this->demographics->ofPersons($t, [(string) $row['person_id']])[(string) $row['person_id']] ?? ['gender' => null, 'birth_date' => null];
        $class = $row['class_structure_id'] === null ? null : $this->tree->structure($t, $establishmentId, (string) $row['class_structure_id']);
        $values = json_decode((string) ($row['fields_json'] ?? '{}'), true);
        $values = is_array($values) ? $values : [];
        $fields = array_map(static fn (array $f): array => $f + ['value' => isset($values[$f['code']]) && is_string($values[$f['code']]) ? $values[$f['code']] : null], $this->fields($t, $establishmentId));

        return [
            'session' => self::sessionResource($session), 'candidate' => self::candidateResource($row),
            'learner' => ['last_name' => $person === null ? '' : $person->lastName, 'first_name' => $person === null ? '' : $person->firstName, 'gender' => $demo['gender'], 'birth_date' => $demo['birth_date']],
            'class_name' => $class === null ? null : $class->name, 'fields' => $fields,
            'missing_required' => array_values(array_map(static fn (array $f): string => (string) $f['code'], array_filter($fields, static fn (array $f): bool => $f['required'] && ($f['value'] === null || $f['value'] === '')))),
        ];
    }

    /** @return array{headers: list<string>, rows: list<list<scalar|null>>} liste des candidats pour l'export */
    public function exportRows(Principal $principal, string $establishmentId, string $sessionId): array
    {
        $t = $principal->tenantId;
        $fields = $this->fields($t, $establishmentId);
        $candidates = array_values(array_filter($this->candidates($principal, $establishmentId, $sessionId), static fn (array $c): bool => $c['registration_status'] === 'registered'));
        $demo = $this->demographics->ofPersons($t, array_map(static fn (array $c): string => (string) $c['person_id'], $candidates));
        $classes = [];
        $rows = [];
        foreach ($candidates as $c) {
            $classId = $c['class_structure_id'];
            if (is_string($classId) && !isset($classes[$classId])) {
                $classes[$classId] = $this->tree->structure($t, $establishmentId, $classId)?->name;
            }
            $row = [$c['candidate_number'], $c['name'], $demo[$c['person_id']]['gender'] ?? null, $demo[$c['person_id']]['birth_date'] ?? null, is_string($classId) ? ($classes[$classId] ?? null) : null, $c['sub_centre_label']];
            foreach ($fields as $f) {
                $row[] = $c['fields'][$f['code']] ?? null;
            }
            $row[] = $c['outcome'];
            $rows[] = $row;
        }

        return ['headers' => ['numero', 'nom', 'sexe', 'date_naissance', 'classe', 'sous_centre', ...array_map(static fn (array $f): string => (string) $f['code'], $fields), 'resultat'], 'rows' => $rows];
    }

    /** @return list<array<string, mixed>> résultats publiés de l'enfant (portail parent) */
    public function forFamily(Principal $principal, string $establishmentId, string $childId): array
    {
        $this->family->assertChild($principal, $establishmentId, $childId);
        $t = $principal->tenantId;
        $out = [];
        foreach ($this->store->select(self::CANDIDATES, $t, $establishmentId, ['person_id' => $childId, 'registration_status' => 'registered'], 'id', 50) as $row) {
            $session = $this->store->find(self::SESSIONS, $t, $establishmentId, (string) $row['session_id']);
            if ($session === null) {
                continue;
            }
            $published = $session['results_published_at'] !== null;
            $out[] = ['exam' => (string) $session['label'], 'exam_code' => (string) $session['exam_code'], 'written_on' => $session['written_on'], 'sub_centre' => $row['sub_centre_label'] ?? $session['sub_centre_label'],
                'candidate_number' => $row['candidate_number'], 'outcome' => $published ? (string) $row['outcome'] : null, 'outcome_detail' => $published ? $row['outcome_detail'] : null];
        }

        return $out;
    }

    /** @return array{registered: int, withdrawn: int, passed: int, failed: int, absent: int, pending: int, pass_rate: ?float} */
    public function stats(string $tenantId, string $establishmentId, string $sessionId): array
    {
        $s = ['registered' => 0, 'withdrawn' => 0, 'passed' => 0, 'failed' => 0, 'absent' => 0, 'pending' => 0];
        foreach ($this->store->select(self::CANDIDATES, $tenantId, $establishmentId, ['session_id' => $sessionId], 'id', 5000) as $row) {
            if ($row['registration_status'] !== 'registered') {
                ++$s['withdrawn'];
                continue;
            }
            ++$s['registered'];
            match ((string) $row['outcome']) {
                'passed' => ++$s['passed'],
                'failed' => ++$s['failed'],
                'absent' => ++$s['absent'],
                default => ++$s['pending'],
            };
        }
        $present = $s['passed'] + $s['failed'];

        return $s + ['pass_rate' => $present === 0 ? null : round($s['passed'] * 100 / $present, 1)];
    }

    /** @return array<string, array<string, mixed>> */
    private function kinds(string $tenantId, string $establishmentId): array
    {
        return $this->settings->labels($tenantId, $establishmentId, 'primary.exam.kinds');
    }

    /** @return list<array{code: string, fr: string, en: string, required: bool}> */
    private function fields(string $tenantId, string $establishmentId): array
    {
        $raw = $this->settings->raw($tenantId, $establishmentId, 'primary.exam.registration_fields');
        $default = PrimarySettings::DEFAULTS['primary.exam.registration_fields']['default'];
        $out = [];
        foreach ([is_array($raw) && array_is_list($raw) ? $raw : $default, $default] as $candidate) {
            $out = [];
            foreach ((array) $candidate as $f) {
                if (!is_array($f) || !is_string($f['code'] ?? null) || preg_match('/^[a-z][a-z0-9_]{1,39}$/', $f['code']) !== 1 || !is_string($f['fr'] ?? null)) {
                    $out = null;
                    break;
                }
                $out[] = ['code' => $f['code'], 'fr' => $f['fr'], 'en' => is_string($f['en'] ?? null) ? $f['en'] : $f['fr'], 'required' => ($f['required'] ?? false) === true];
            }
            if ($out !== null) {
                return $out;
            }
        }

        return [];
    }

    private function manage(Principal $principal, string $establishmentId): void
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $this->access->assertWhole($principal, $perimeter, self::SESSIONS);
    }

    /** @return array<string, mixed> */
    private function registrable(string $tenantId, string $establishmentId, string $sessionId): array
    {
        $session = $this->store->findOrFail(self::SESSIONS, $tenantId, $establishmentId, $sessionId, 'Session d\'examen introuvable.');
        if ($session['status'] === 'closed') {
            throw HttpException::conflict('Les inscriptions de cette session sont closes.');
        }
        $closes = $session['registration_closes_on'];
        if (is_string($closes) && $closes !== '' && $closes < $this->clock->today()) {
            throw HttpException::conflict('La période d\'inscription est terminée (' . $closes . ').');
        }

        return $session;
    }

    private function enrollmentOf(string $tenantId, string $establishmentId, string $personId, string $yearId, string $classId): ?string
    {
        foreach ($this->enrollments->forPerson($tenantId, $personId) as $e) {
            if ($e->establishmentId === $establishmentId && $e->academicYearId === $yearId && $e->structureId === $classId && in_array($e->status, ['active', 'conditional'], true)) {
                return $e->id;
            }
        }

        return null;
    }

    private function insertCandidate(string $tenantId, string $establishmentId, string $sessionId, string $personId, string $enrollmentId, ?string $classId): string
    {
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert(self::CANDIDATES, $tenantId, $establishmentId, [
            'id' => $id, 'session_id' => $sessionId, 'person_id' => $personId, 'enrollment_id' => $enrollmentId, 'class_structure_id' => $classId,
            'candidate_number' => null, 'sub_centre_label' => null, 'registration_status' => 'registered', 'fields_json' => '{}', 'outcome' => 'pending',
            'outcome_detail' => null, 'invoice_id' => null, 'withdrawal_reason' => null, 'withdrawn_at' => null, 'recorded_by' => null, 'recorded_at' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    private function numberTaken(string $tenantId, string $establishmentId, string $sessionId, string $number, string $exceptId): bool
    {
        foreach ($this->store->select(self::CANDIDATES, $tenantId, $establishmentId, ['session_id' => $sessionId, 'candidate_number' => $number], 'id', 2) as $row) {
            if ((string) $row['id'] !== $exceptId) {
                return true;
            }
        }

        return false;
    }

    private static function normalise(string $word): string
    {
        $word = mb_strtolower(trim($word));

        return strtr($word, ['é' => 'e', 'è' => 'e', 'ê' => 'e']);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function sessionResource(array $row): array
    {
        $s = static fn (mixed $v): ?string => $v === null ? null : (string) $v;

        return [
            'id' => (string) $row['id'], 'academic_year_id' => (string) $row['academic_year_id'], 'exam_code' => (string) $row['exam_code'], 'label' => (string) $row['label'],
            'level_structure_id' => (string) $row['level_structure_id'], 'centre_label' => $s($row['centre_label']), 'sub_centre_label' => $s($row['sub_centre_label']),
            'registration_opens_on' => $s($row['registration_opens_on']), 'registration_closes_on' => $s($row['registration_closes_on']), 'written_on' => $s($row['written_on']),
            'fee_code' => $s($row['fee_code']), 'status' => (string) $row['status'], 'results_published_at' => $s($row['results_published_at']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function candidateResource(array $row): array
    {
        $s = static fn (mixed $v): ?string => $v === null ? null : (string) $v;
        $fields = json_decode((string) ($row['fields_json'] ?? '{}'), true);

        return [
            'id' => (string) $row['id'], 'session_id' => (string) $row['session_id'], 'person_id' => (string) $row['person_id'], 'enrollment_id' => (string) $row['enrollment_id'],
            'class_structure_id' => $s($row['class_structure_id']), 'candidate_number' => $s($row['candidate_number']), 'sub_centre_label' => $s($row['sub_centre_label']),
            'registration_status' => (string) $row['registration_status'], 'fields' => is_array($fields) ? $fields : [], 'outcome' => (string) $row['outcome'],
            'outcome_detail' => $s($row['outcome_detail']), 'invoice_id' => $s($row['invoice_id']), 'withdrawal_reason' => $s($row['withdrawal_reason']),
        ];
    }
}

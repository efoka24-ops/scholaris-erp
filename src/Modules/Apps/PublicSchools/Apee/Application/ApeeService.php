<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Apee\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Apee\Infrastructure\PdoApee;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PublicMessages;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * APEE des écoles primaires publiques (EX-APE-01 à 06, T650, T651, T655). Au primaire public, AUCUNE contribution n'est exigible
 * (circulaire d'exécution du budget des écoles publiques, visa MINFI du 7 mars 2022) : la cotisation APEE est volontaire, votée en
 * assemblée générale, tenue dans des comptes séparés de la scolarité, et n'est JAMAIS une condition d'accès à un service (RG-APE-1) :
 * aucun autre module ne lit ces tables. Règles :
 *  - RG-APE-2 : la liste nominative des familles non contributrices n'est lisible que par le bureau (`public_schools.apee.non_payers.read`) ;
 *  - RG-APE-3 : une dépense sans pièce reste « en attente » et figure comme telle dans l'état financier ;
 *  - RG-APE-4 : le directeur consulte (`apee.read`) mais ne modifie pas ; le bureau écrit (`apee.write`) sans toucher aux données scolaires ;
 *  - reçu numéroté DOC-24 (QR) à chaque contribution et SMS NOT-10 ; état financier DOC-25 ; bordereau DOC-27.
 * Encaissement mobile money : enregistré avec la référence de transaction de l'opérateur. Le contrat Billing/PaymentRequester n'est
 * pas utilisé : il exige une facture du socle, ce qui créerait un lien entre scolarité et paiement interdit par RG-ELV-1.
 */
final class ApeeService
{
    public const INCOME_CATEGORIES = ['contribution', 'donation', 'other_income'];
    public const EXPENSE_CATEGORIES = ['parents_teacher', 'maintenance', 'supplies', 'mock_exam', 'other'];
    public const METHODS = ['cash', 'mobile_money', 'bank'];
    public const EXEMPTION_REASONS = ['large_family', 'hardship', 'other'];
    public const RECEIPT_TYPE = 'pub_doc24_pta_receipt';
    public const STATEMENT_TYPE = 'pub_doc25_pta_statement';
    public const RECEIPT_TEMPLATE = 'pub.not10.payer';

    public function __construct(
        private readonly PdoApee $repository,
        private readonly ClassRoster $classes,
        private readonly CurrentPeriod $periods,
        private readonly PersonDirectory $people,
        private readonly DocumentIssuer $documents,
        private readonly UserPersonLink $users,
        private readonly PublicMessages $messages,
        private readonly ApeeClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * EX-APE-01 : montant voté en assemblée générale, avec la référence du compte rendu numérisé.
     *
     * @param array<string, mixed> $input year_label, amount_per_pupil, voted_on, minutes_ref?, expected_pupils?
     * @return array<string, mixed>
     */
    public function vote(Principal $principal, string $establishmentId, array $input): array
    {
        $tenantId = $principal->tenantId;
        $errors = [];
        $year = $input['year_label'] ?? null;
        if (!is_string($year) || preg_match('/^(\d{4})-(\d{4})$/', $year, $m) !== 1 || (int) $m[2] !== (int) $m[1] + 1) {
            $errors['year_label'][] = 'Année scolaire AAAA-AAAA attendue.';
        }
        $amount = $input['amount_per_pupil'] ?? null;
        if (!is_int($amount) || $amount < 0) {
            $errors['amount_per_pupil'][] = 'Montant entier en FCFA (0 ou plus) attendu.';
        }
        $votedOn = $input['voted_on'] ?? null;
        if (!self::isDate($votedOn)) {
            $errors['voted_on'][] = 'Date de l\'assemblée générale AAAA-MM-JJ requise.';
        }
        $minutes = self::text($input['minutes_ref'] ?? null, 255, 'minutes_ref', $errors);
        $expected = $input['expected_pupils'] ?? $this->enrolledCount($tenantId, $establishmentId);
        if (!is_int($expected) || $expected < 0) {
            $errors['expected_pupils'][] = 'Effectif entier attendu.';
        }
        if ($errors === [] && $this->repository->yearByLabel($tenantId, $establishmentId, (string) $year) !== null) {
            throw HttpException::conflict('Le montant de cette année est déjà voté.');
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $id = PdoApee::newId();
        $this->repository->insert('pub_apee_years', [
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'year_label' => (string) $year, 'amount_per_pupil' => (int) $amount,
            'voted_on' => (string) $votedOn, 'minutes_ref' => $minutes, 'expected_pupils' => (int) $expected, 'created_by' => $principal->userId, 'created_at' => $this->clock->now(),
        ]);
        $this->audit->append($tenantId, $principal->userId, 'public_schools.apee.year_voted', 'pub_apee_year', $id, null, ['amount_per_pupil' => $amount, 'year_label' => $year]);

        return self::yearResource($this->repository->year($tenantId, $establishmentId, $id) ?? []);
    }

    /**
     * API-14 : encaissement (contribution, don) ou dépense. Idempotent par `client_uuid`.
     *
     * @param array<string, mixed> $input apee_year_id, direction, category, amount, method?, operator_ref?, family_ref?, payer_person_id?, label?, proof_ref?, entered_on?, client_uuid?
     * @return array{entry: array<string, mixed>, replayed: bool}
     */
    public function record(Principal $principal, string $establishmentId, array $input): array
    {
        $tenantId = $principal->tenantId;
        $uuid = $input['client_uuid'] ?? null;
        if (is_string($uuid) && $uuid !== '') {
            $previous = $this->repository->entryByUuid($tenantId, $uuid);
            if ($previous !== null) {
                return ['entry' => self::entryResource($previous), 'replayed' => true];
            }
        }
        $errors = [];
        $year = is_string($input['apee_year_id'] ?? null) ? $this->repository->year($tenantId, $establishmentId, (string) $input['apee_year_id']) : null;
        if ($year === null) {
            $errors['apee_year_id'][] = 'Année APEE (montant voté) requise.';
        }
        $direction = $input['direction'] ?? null;
        if (!in_array($direction, ['income', 'expense'], true)) {
            $errors['direction'][] = 'Valeur attendue : income ou expense.';
        }
        $categories = $direction === 'expense' ? self::EXPENSE_CATEGORIES : self::INCOME_CATEGORIES;
        $category = $input['category'] ?? null;
        if (!is_string($category) || !in_array($category, $categories, true)) {
            $errors['category'][] = 'Catégorie attendue : ' . implode(', ', $categories) . '.';
        }
        $amount = $input['amount'] ?? null;
        if (!is_int($amount) || $amount <= 0) {
            $errors['amount'][] = 'Montant entier positif en FCFA attendu.';
        }
        $method = $input['method'] ?? 'cash';
        if (!is_string($method) || !in_array($method, self::METHODS, true)) {
            $errors['method'][] = 'Moyen attendu : ' . implode(', ', self::METHODS) . '.';
        }
        $operatorRef = self::text($input['operator_ref'] ?? null, 80, 'operator_ref', $errors);
        if ($method === 'mobile_money' && $operatorRef === null) {
            $errors['operator_ref'][] = 'Référence de la transaction mobile money requise.';
        }
        $family = self::text($input['family_ref'] ?? null, 40, 'family_ref', $errors);
        $payer = self::text($input['payer_person_id'] ?? null, 40, 'payer_person_id', $errors);
        if ($category === 'contribution') {
            if ($family === null || !in_array($family, $this->enrolledPupils($tenantId, $establishmentId), true)) {
                $errors['family_ref'][] = 'Élève inscrit dans l\'école requis pour une contribution.';
            } elseif ($payer !== null && !in_array($payer, array_map(static fn ($g): string => $g->id, $this->people->guarantorsOf($tenantId, $family)), true)) {
                $errors['payer_person_id'][] = 'Le payeur doit être un parent ou garant de l\'élève.';
            }
        }
        $label = self::text($input['label'] ?? null, 200, 'label', $errors);
        $proof = self::text($input['proof_ref'] ?? null, 255, 'proof_ref', $errors);
        $enteredOn = $input['entered_on'] ?? $this->clock->today();
        if (!self::isDate($enteredOn)) {
            $errors['entered_on'][] = 'Date AAAA-MM-JJ attendue.';
        }
        if (is_string($uuid) && strlen($uuid) > 40) {
            $errors['client_uuid'][] = 'Identifiant client de 40 caractères au plus.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $state = $direction === 'expense' && $proof === null ? 'pending' : 'recorded';
        if ($category === 'contribution' && $payer === null) {
            $guardians = $this->people->guarantorsOf($tenantId, (string) $family);
            $payer = $guardians === [] ? null : $guardians[0]->id;
        }
        $id = PdoApee::newId();
        $this->repository->insert('pub_apee_entries', [
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'apee_year_id' => (string) ($year['id'] ?? ''), 'direction' => (string) $direction,
            'category' => (string) $category, 'amount' => (int) $amount, 'method' => $direction === 'income' ? (string) $method : $method, 'operator_ref' => $operatorRef,
            'payer_person_id' => $payer, 'family_ref' => $family, 'label' => $label, 'proof_ref' => $proof, 'entry_state' => $state, 'client_uuid' => is_string($uuid) && $uuid !== '' ? $uuid : null,
            'entered_by' => $principal->userId, 'entered_on' => (string) $enteredOn, 'created_at' => $this->clock->now(),
        ]);
        if ($direction === 'income' && $category === 'contribution') {
            $this->issueReceipt($principal, $establishmentId, $id, (array) $year);
        }
        $this->audit->append($tenantId, $principal->userId, 'public_schools.apee.entry_recorded', 'pub_apee_entry', $id, null, ['direction' => $direction, 'category' => $category, 'amount' => $amount, 'state' => $state]);

        return ['entry' => self::entryResource($this->repository->entry($tenantId, $establishmentId, $id) ?? []), 'replayed' => false];
    }

    /**
     * RG-APE-3 : une pièce jointe à une dépense en attente la fait passer à « enregistrée ».
     *
     * @return array<string, mixed>
     */
    public function attachProof(Principal $principal, string $establishmentId, string $entryId, string $proofRef): array
    {
        $entry = $this->repository->entry($principal->tenantId, $establishmentId, $entryId) ?? throw HttpException::notFound();
        if ($entry['direction'] !== 'expense') {
            throw HttpException::conflict('Seule une dépense porte une pièce justificative.');
        }
        $this->repository->updateEntry($principal->tenantId, $entryId, $proofRef, 'recorded', $entry['receipt_document_id'] === null ? null : (string) $entry['receipt_document_id'], $entry['receipt_number'] === null ? null : (string) $entry['receipt_number']);
        $this->audit->append($principal->tenantId, $principal->userId, 'public_schools.apee.proof_attached', 'pub_apee_entry', $entryId, ['state' => $entry['entry_state']], ['state' => 'recorded']);

        return self::entryResource($this->repository->entry($principal->tenantId, $establishmentId, $entryId) ?? []);
    }

    /**
     * DOC-25 (EX-APE-04, T651) : état financier présenté à l'assemblée générale. Effectifs seulement (aucun nom) : visible du bureau,
     * du directeur, du conseil d'école, de l'inspection et des parents (comptes de l'association).
     *
     * @return array<string, mixed>
     */
    public function statement(string $tenantId, string $establishmentId, string $yearId): array
    {
        $year = $this->repository->year($tenantId, $establishmentId, $yearId) ?? throw HttpException::notFound();
        $expected = (int) $year['amount_per_pupil'] * (int) $year['expected_pupils'];
        $income = [];
        $expenses = [];
        $pending = [];
        foreach ($this->repository->totals($tenantId, $establishmentId, $yearId) as $row) {
            $category = (string) $row['category'];
            if ($row['direction'] === 'income') {
                $income[$category] = ($income[$category] ?? 0) + (int) $row['total'];
            } elseif ($row['entry_state'] === 'pending') {
                $pending[$category] = ($pending[$category] ?? 0) + (int) $row['total'];
            } else {
                $expenses[$category] = ($expenses[$category] ?? 0) + (int) $row['total'];
            }
        }
        $collected = $income['contribution'] ?? 0;
        $families = count($this->repository->contributingFamilies($tenantId, $establishmentId, $yearId));
        $exempted = count($this->repository->exemptions($tenantId, $establishmentId, $yearId));
        $incomeTotal = array_sum($income);
        $expenseTotal = array_sum($expenses);

        return [
            'apee_year_id' => $yearId, 'year_label' => (string) $year['year_label'], 'amount_per_pupil' => (int) $year['amount_per_pupil'],
            'expected_pupils' => (int) $year['expected_pupils'], 'expected' => $expected, 'collected' => $collected,
            'collection_rate' => $expected > 0 ? round($collected * 100 / $expected, 1) : null,
            'contributing_families' => $families, 'exempted_families' => $exempted,
            'non_contributing_families' => max(0, (int) $year['expected_pupils'] - $families - $exempted),
            'income' => $income, 'income_total' => $incomeTotal, 'expenses' => $expenses, 'expenses_total' => $expenseTotal,
            'pending_expenses' => $pending, 'pending_total' => array_sum($pending), 'balance' => $incomeTotal - $expenseTotal,
        ];
    }

    /**
     * Émet l'état financier DOC-25 (document de l'association, sans QR).
     *
     * @return array<string, mixed>
     */
    public function issueStatement(Principal $principal, string $establishmentId, string $yearId, string $locale): array
    {
        $statement = $this->statement($principal->tenantId, $establishmentId, $yearId);
        $en = $locale === 'en';
        $lines = [];
        $lines[] = sprintf('%-34s %12s', $en ? 'Expected' : 'Attendu', number_format($statement['expected'], 0, ',', ' '));
        $lines[] = sprintf('%-34s %12s', $en ? 'Collected (contributions)' : 'Encaissé (contributions)', number_format($statement['collected'], 0, ',', ' '));
        $lines[] = sprintf('%-34s %11s%%', $en ? 'Collection rate' : 'Taux de contribution', $statement['collection_rate'] === null ? '-' : number_format((float) $statement['collection_rate'], 1, ',', ' '));
        foreach ($statement['income'] as $category => $total) {
            if ($category !== 'contribution') {
                $lines[] = sprintf('%-34s %12s', ($en ? 'Income: ' : 'Recette : ') . $category, number_format($total, 0, ',', ' '));
            }
        }
        foreach ($statement['expenses'] as $category => $total) {
            $lines[] = sprintf('%-34s %12s', ($en ? 'Expense: ' : 'Dépense : ') . $category, number_format($total, 0, ',', ' '));
        }
        $lines[] = sprintf('%-34s %12s', $en ? 'Total expenses' : 'Total des dépenses', number_format($statement['expenses_total'], 0, ',', ' '));
        $lines[] = sprintf('%-34s %12s', $en ? 'Non-contributing families' : 'Familles non contributrices', (string) $statement['non_contributing_families']);
        $subject = $this->users->personIdForUser($principal->tenantId, $principal->userId) ?? throw HttpException::validation(['user' => ['Compte sans personne liée : émission impossible.']]);
        $document = $this->documents->issue($principal->tenantId, $establishmentId, self::STATEMENT_TYPE, $subject, [
            'school_year' => $statement['year_label'],
            'voted_line' => ($en ? 'Contribution voted by the general assembly: ' : 'Contribution votée en assemblée générale : ') . number_format($statement['amount_per_pupil'], 0, ',', ' ') . ' FCFA × ' . $statement['expected_pupils'],
            'statement_block' => implode("\n", $lines),
            'pending_line' => ($en ? 'Expenses awaiting a voucher (pending): ' : 'Dépenses sans pièce (en attente) : ') . number_format($statement['pending_total'], 0, ',', ' ') . ' FCFA',
            'balance_line' => ($en ? 'Balance: ' : 'Solde : ') . number_format($statement['balance'], 0, ',', ' ') . ' FCFA',
        ], $principal->userId, $en ? 'en' : 'fr');
        $this->audit->append($principal->tenantId, $principal->userId, 'public_schools.apee.statement_issued', 'pub_apee_year', $yearId, null, ['document_id' => $document->id]);

        return ['document' => ['id' => $document->id, 'number' => $document->number], 'statement' => $statement];
    }

    /**
     * RG-APE-2 : familles inscrites sans contribution ni exonération, pour le bureau seulement (le contrôleur vérifie le droit).
     *
     * @return list<array{person_id: string, name: string}>
     */
    public function nonContributors(string $tenantId, string $establishmentId, string $yearId): array
    {
        $this->repository->year($tenantId, $establishmentId, $yearId) ?? throw HttpException::notFound();
        $excluded = array_flip(array_merge(
            $this->repository->contributingFamilies($tenantId, $establishmentId, $yearId),
            array_map(static fn (array $row): string => (string) $row['family_ref'], $this->repository->exemptions($tenantId, $establishmentId, $yearId)),
        ));
        $ids = array_values(array_filter($this->enrolledPupils($tenantId, $establishmentId), static fn (string $id): bool => !isset($excluded[$id])));
        $rows = [];
        foreach ($this->people->findMany($tenantId, $ids) as $person) {
            $rows[] = ['person_id' => $person->id, 'name' => $person->fullName()];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * EX-APE-06 : exonération décidée par le bureau, sans publicité nominative (lecture réservée au bureau).
     *
     * @param array<string, mixed> $input family_ref, reason_code
     * @return array<string, mixed>
     */
    public function exempt(Principal $principal, string $establishmentId, string $yearId, array $input): array
    {
        $tenantId = $principal->tenantId;
        $this->repository->year($tenantId, $establishmentId, $yearId) ?? throw HttpException::notFound();
        $errors = [];
        $family = $input['family_ref'] ?? null;
        if (!is_string($family) || !in_array($family, $this->enrolledPupils($tenantId, $establishmentId), true)) {
            $errors['family_ref'][] = 'Élève inscrit dans l\'école requis.';
        }
        $reason = $input['reason_code'] ?? null;
        if (!in_array($reason, self::EXEMPTION_REASONS, true)) {
            $errors['reason_code'][] = 'Motif attendu : ' . implode(', ', self::EXEMPTION_REASONS) . '.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        foreach ($this->repository->exemptions($tenantId, $establishmentId, $yearId) as $row) {
            if ($row['family_ref'] === $family) {
                throw HttpException::conflict('Famille déjà exonérée pour cette année.');
            }
        }
        $id = PdoApee::newId();
        $this->repository->insert('pub_apee_exemptions', [
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'apee_year_id' => $yearId, 'family_ref' => (string) $family,
            'reason_code' => (string) $reason, 'decided_by' => $principal->userId, 'decided_on' => $this->clock->today(),
        ]);
        $this->audit->append($tenantId, $principal->userId, 'public_schools.apee.exemption_decided', 'pub_apee_exemption', $id, null, ['reason_code' => $reason]);

        return ['id' => $id, 'family_ref' => $family, 'reason_code' => $reason];
    }

    /**
     * Recherche d'élève minimale pour le bureau (nom seulement), afin d'établir un reçu : aucune autre donnée scolaire.
     *
     * @return list<array{person_id: string, name: string}>
     */
    public function findFamilies(string $tenantId, string $establishmentId, string $term): array
    {
        $needle = mb_strtolower(trim($term));
        $rows = [];
        foreach ($this->people->findMany($tenantId, $this->enrolledPupils($tenantId, $establishmentId)) as $person) {
            if (str_contains(mb_strtolower($person->fullName()), $needle)) {
                $rows[] = ['person_id' => $person->id, 'name' => $person->fullName()];
            }
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return array_slice($rows, 0, 20);
    }

    /** @return list<string> élèves inscrits dans les classes de l'année en cours */
    public function enrolledPupils(string $tenantId, string $establishmentId): array
    {
        $period = $this->periods->at($tenantId, $establishmentId, $this->clock->today());
        if ($period === null) {
            return [];
        }
        $ids = [];
        foreach ($this->classes->classesOf($tenantId, $establishmentId, $period->academicYearId) as $class) {
            foreach ($this->classes->pupilIdsIn($tenantId, $establishmentId, $class->structureId, $this->clock->today()) as $id) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function yearResource(array $row): array
    {
        return $row === [] ? [] : [
            'id' => (string) $row['id'], 'year_label' => (string) $row['year_label'], 'amount_per_pupil' => (int) $row['amount_per_pupil'],
            'voted_on' => (string) $row['voted_on'], 'minutes_ref' => $row['minutes_ref'], 'expected_pupils' => (int) $row['expected_pupils'],
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function entryResource(array $row): array
    {
        return $row === [] ? [] : [
            'id' => (string) $row['id'], 'apee_year_id' => (string) $row['apee_year_id'], 'direction' => (string) $row['direction'], 'category' => (string) $row['category'],
            'amount' => (int) $row['amount'], 'method' => $row['method'], 'operator_ref' => $row['operator_ref'], 'family_ref' => $row['family_ref'],
            'label' => $row['label'], 'proof_ref' => $row['proof_ref'], 'state' => (string) $row['entry_state'], 'receipt_document_id' => $row['receipt_document_id'],
            'receipt_number' => $row['receipt_number'], 'entered_on' => (string) $row['entered_on'],
        ];
    }

    /** @param array<string, mixed> $year */
    private function issueReceipt(Principal $principal, string $establishmentId, string $entryId, array $year): void
    {
        $tenantId = $principal->tenantId;
        $entry = $this->repository->entry($tenantId, $establishmentId, $entryId) ?? throw HttpException::notFound();
        $family = (string) $entry['family_ref'];
        $pupil = $this->people->find($tenantId, $family);
        $subject = $entry['payer_person_id'] !== null ? (string) $entry['payer_person_id'] : ($this->users->personIdForUser($tenantId, $principal->userId) ?? $family);
        $treasurer = $this->users->personIdForUser($tenantId, $principal->userId);
        $document = $this->documents->issue($tenantId, $establishmentId, self::RECEIPT_TYPE, $subject, [
            'school_year' => (string) ($year['year_label'] ?? ''), 'pupil_name' => $pupil?->fullName() ?? '', 'amount' => number_format((int) $entry['amount'], 0, ',', ' '),
            'payment_method' => (string) $entry['method'], 'received_on' => (string) $entry['entered_on'],
            'treasurer_name' => $treasurer === null ? '' : ($this->people->find($tenantId, $treasurer)?->fullName() ?? ''),
        ], $principal->userId);
        $this->repository->updateEntry($tenantId, $entryId, $entry['proof_ref'] === null ? null : (string) $entry['proof_ref'], (string) $entry['entry_state'], $document->id, $document->number);
        $this->messages->notifyGuardians($tenantId, $establishmentId, $family, self::RECEIPT_TEMPLATE, [
            'receipt_number' => $document->number, 'amount' => number_format((int) $entry['amount'], 0, ',', ' '),
        ]);
    }

    private function enrolledCount(string $tenantId, string $establishmentId): int
    {
        return count($this->enrolledPupils($tenantId, $establishmentId));
    }

    public static function isDate(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    /** @param array<string, list<string>> $errors */
    public static function text(mixed $value, int $max, string $field, array &$errors): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (!is_string($value) || mb_strlen(trim($value)) > $max) {
            $errors[$field][] = 'Texte de ' . $max . ' caractères au plus attendu.';

            return null;
        }

        return trim($value);
    }
}

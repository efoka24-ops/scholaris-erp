<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Census\Application;

use DI\Container;
use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\PublicSchools\Contracts\CensusSectionProvider;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PublicMessages;
use Scholaris\Modules\Apps\PublicSchools\Support\Perimeter;
use Scholaris\Modules\Apps\PublicSchools\Support\ScopedByTerritory;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;
use Scholaris\Modules\Socle\Reporting\Contracts\ApprovalWorkflow;

/**
 * Questionnaire statistique annuel (STA, T652, T653, DOC-23, API-11) :
 *  - ouverture d'une campagne (rentrée ou fin d'année) : questionnaire pré-rempli par les sections enregistrées sous
 *    `public_schools.census_sections.*` (effectifs, infrastructures, passage de la Primaire publique…) ;
 *  - saisie par le directeur ou, pour une école sans équipement, par l'agent de l'inspection (R20, RG-STA-2, marqué « par procuration ») ;
 *  - contrôles de cohérence (EX-STA-02) : un contrôle bloquant renvoie 422 avec la liste (TST-08) ; un contrôle « à justifier » exige
 *    une justification ;
 *  - circuit (EX-STA-03, contrat ApprovalWorkflow) : inspection (arrondissement) > délégation départementale > délégation régionale ;
 *    renvoi motivé à chaque niveau ; gel à la validation régionale (RG-STA-1, instantané figé) ; la planification (national) consolide ;
 *  - correction après gel : nouvelle version motivée qui reprend le circuit ; l'ancienne reste la version officielle jusque-là.
 * Notification NOT-11 : file `GET /census/pending` du niveau qui doit valider (pas d'annuaire des inspecteurs au socle) et message au
 * directeur lors d'un renvoi.
 */
final class CensusService
{
    public const CAMPAIGNS = ['start_of_year', 'end_of_year'];
    public const STEPS = ['district' => 'arrondissement', 'department' => 'departement', 'region' => 'region'];
    public const PLANNING_ROLE = 'pub_planning';
    public const SECTIONS_PREFIX = 'public_schools.census_sections.';
    public const DOCUMENT_TYPE = 'pub_doc23_census';
    private const BLOCKING_MARKS = ['mismatch', 'negative', 'missing'];

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly Container $container,
        private readonly ScopedByTerritory $scope,
        private readonly TerritoryDirectory $territories,
        private readonly ApprovalWorkflow $workflow,
        private readonly DocumentIssuer $documents,
        private readonly UserPersonLink $users,
        private readonly PublicMessages $messages,
        private readonly SettingsReader $settings,
        private readonly CensusClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /** Écriture : directeur (école entière) ou agent de saisie de l'inspection (affectation territoriale, RG-STA-2). */
    public function writer(Principal $principal, string $establishmentId): Perimeter
    {
        $perimeter = $this->scope->perimeter($principal, 'public_schools.census.write', $establishmentId);
        if (!$perimeter->wholeSchool && !$perimeter->isTerritorial()) {
            $this->scope->assertWritable($principal, Perimeter::none($principal->tenantId, $establishmentId, 'public_schools.census.write'), null, 'pub_census', $establishmentId);
        }

        return $perimeter;
    }

    /** Lecture : qui écrit ou valide le questionnaire de l'école (aucune donnée nominative). */
    public function reader(Principal $principal, string $establishmentId): void
    {
        foreach (['public_schools.census.write', 'public_schools.census.validate'] as $permission) {
            if (!$this->scope->perimeter($principal, $permission, $establishmentId)->isEmpty()) {
                return;
            }
        }

        throw HttpException::notFound();
    }

    /**
     * Ouvre (ou rend) le questionnaire de la campagne, pré-rempli.
     *
     * @param array<string, mixed> $input year_label, campaign
     * @return array<string, mixed>
     */
    public function open(Principal $principal, string $establishmentId, array $input): array
    {
        $perimeter = $this->writer($principal, $establishmentId);
        $tenantId = $principal->tenantId;
        $year = $input['year_label'] ?? null;
        $campaign = $input['campaign'] ?? 'start_of_year';
        $errors = [];
        if (!is_string($year) || preg_match('/^(\d{4})-(\d{4})$/', $year, $m) !== 1 || (int) $m[2] !== (int) $m[1] + 1) {
            $errors['year_label'][] = 'Année scolaire AAAA-AAAA attendue.';
        }
        if (!in_array($campaign, self::CAMPAIGNS, true)) {
            $errors['campaign'][] = 'Campagne attendue : ' . implode(', ', self::CAMPAIGNS) . '.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $existing = $this->latest($tenantId, $establishmentId, (string) $year, (string) $campaign);
        if ($existing !== null) {
            return self::resource($existing);
        }
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->connections->pdo()->prepare('INSERT INTO pub_census_forms (id, tenant_id, establishment_id, year_label, campaign, version, form_state, payload, entered_by_proxy, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $tenantId, $establishmentId, $year, $campaign, 1, 'draft', json_encode($this->prefill($tenantId, $establishmentId, (string) $year, (string) $campaign), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $perimeter->wholeSchool ? 0 : 1, $principal->userId, $now, $now]);
        $this->audit->append($tenantId, $principal->userId, 'public_schools.census.opened', 'pub_census_form', $id, null, ['year_label' => $year, 'campaign' => $campaign, 'proxy' => !$perimeter->wholeSchool]);

        return self::resource($this->find($tenantId, $establishmentId, $id));
    }

    /**
     * Modifie des valeurs (brouillon ou renvoyé seulement) : `values` = {"<section>.<champ>": nombre ou null}.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function edit(Principal $principal, string $establishmentId, string $formId, array $input): array
    {
        $perimeter = $this->writer($principal, $establishmentId);
        $form = $this->find($principal->tenantId, $establishmentId, $formId);
        if (!in_array($form['form_state'], ['draft', 'returned'], true)) {
            throw HttpException::conflict('Questionnaire transmis ou gelé : une correction après gel passe par une nouvelle version motivée.');
        }
        $payload = (array) json_decode((string) $form['payload'], true);
        $values = $input['values'] ?? null;
        if (!is_array($values) || $values === []) {
            throw HttpException::validation(['values' => ['Valeurs attendues : {"section.champ": nombre}.']]);
        }
        $errors = [];
        foreach ($values as $key => $value) {
            [$section, $field] = array_pad(explode('.', (string) $key, 2), 2, '');
            if (!isset($payload['sections'][$section]) || $field === '' || !array_key_exists($field, (array) $payload['sections'][$section]['values'])) {
                $errors['values.' . $key][] = 'Champ inconnu du questionnaire.';
                continue;
            }
            if ($value !== null && !is_int($value) && !is_float($value)) {
                $errors['values.' . $key][] = 'Nombre attendu.';
                continue;
            }
            $payload['sections'][$section]['values'][$field] = $value;
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $payload['edited'] = true;
        $this->update($principal->tenantId, $formId, ['payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'entered_by_proxy' => $perimeter->wholeSchool ? (int) $form['entered_by_proxy'] : 1]);

        return self::resource($this->find($principal->tenantId, $establishmentId, $formId));
    }

    /**
     * API-11 : transmet au niveau suivant. Par l'école (ou R20) : contrôles puis entrée dans le circuit ; par un validateur : validation
     * de son étape (gel à la région).
     *
     * @param array<string, mixed> $input justifications? {code: texte}
     * @return array<string, mixed>
     */
    public function submit(Principal $principal, string $establishmentId, string $formId, array $input): array
    {
        $tenantId = $principal->tenantId;
        $form = $this->find($tenantId, $establishmentId, $formId);
        if (in_array($form['form_state'], ['draft', 'returned'], true)) {
            $this->writer($principal, $establishmentId);
            $checks = $this->check($tenantId, $establishmentId, $form);
            $justifications = is_array($input['justifications'] ?? null) ? $input['justifications'] : [];
            $blocking = array_values(array_filter($checks, static fn (string $code): bool => self::isBlocking($code)));
            $unjustified = array_values(array_filter($checks, static fn (string $code): bool => !self::isBlocking($code) && !(is_string($justifications[$code] ?? null) && trim($justifications[$code]) !== '')));
            $this->update($tenantId, $formId, ['check_results' => json_encode(['anomalies' => $checks, 'justifications' => $justifications], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            if ($blocking !== [] || $unjustified !== []) {
                throw HttpException::validation(['checks' => array_merge($blocking, $unjustified)], 'Questionnaire incohérent : corrigez ou justifiez les contrôles en erreur.');
            }
            $this->workflow->submit($tenantId, $formId, $principal->userId, array_keys(self::STEPS));
            $this->update($tenantId, $formId, ['form_state' => 'in_review', 'current_step' => 'district', 'return_reason' => null]);
            $this->audit->append($tenantId, $principal->userId, 'public_schools.census.submitted', 'pub_census_form', $formId, null, ['checks' => $checks]);

            return self::resource($this->find($tenantId, $establishmentId, $formId));
        }
        if ($form['form_state'] === 'frozen') {
            $this->assertStep($principal, $establishmentId, 'planning');
            $this->update($tenantId, $formId, ['form_state' => 'consolidated', 'current_step' => null]);
            $this->audit->append($tenantId, $principal->userId, 'public_schools.census.consolidated', 'pub_census_form', $formId, null, []);

            return self::resource($this->find($tenantId, $establishmentId, $formId));
        }
        if ($form['form_state'] !== 'in_review') {
            throw HttpException::conflict('Questionnaire déjà consolidé.');
        }
        $step = (string) $form['current_step'];
        $this->assertStep($principal, $establishmentId, $step);
        $next = $this->workflow->approve($tenantId, $formId, $step, $principal->userId);
        if ($next === null) {
            $payload = (array) json_decode((string) $form['payload'], true);
            $this->workflow->freezeSnapshot($tenantId, $formId, 'v' . (int) $form['version'], $payload + ($form['correction_reason'] !== null ? ['reason' => (string) $form['correction_reason']] : []));
            $this->update($tenantId, $formId, ['form_state' => 'frozen', 'current_step' => 'planning']);
        } else {
            $this->update($tenantId, $formId, ['current_step' => $next]);
        }
        $this->audit->append($tenantId, $principal->userId, 'public_schools.census.approved', 'pub_census_form', $formId, null, ['step' => $step, 'next' => $next]);

        return self::resource($this->find($tenantId, $establishmentId, $formId));
    }

    /**
     * Renvoi motivé au niveau de l'école (EX-STA-03).
     *
     * @return array<string, mixed>
     */
    public function sendBack(Principal $principal, string $establishmentId, string $formId, string $reason): array
    {
        $tenantId = $principal->tenantId;
        $form = $this->find($tenantId, $establishmentId, $formId);
        if ($form['form_state'] !== 'in_review') {
            throw HttpException::conflict('Seul un questionnaire en cours de validation peut être renvoyé.');
        }
        $step = (string) $form['current_step'];
        $this->assertStep($principal, $establishmentId, $step);
        $this->workflow->returnForRevision($tenantId, $formId, $step, $principal->userId, $reason);
        $this->update($tenantId, $formId, ['form_state' => 'returned', 'current_step' => null, 'return_reason' => $reason]);
        $this->messages->notifyDirector($tenantId, $establishmentId, 'pub.not11.supervisor', ['school_year' => (string) $form['year_label']]);
        $this->audit->append($tenantId, $principal->userId, 'public_schools.census.returned', 'pub_census_form', $formId, null, ['step' => $step, 'reason' => $reason]);

        return self::resource($this->find($tenantId, $establishmentId, $formId));
    }

    /**
     * RG-STA-1 : correction après gel = nouvelle version motivée qui reprend le circuit.
     *
     * @return array<string, mixed>
     */
    public function correct(Principal $principal, string $establishmentId, string $formId, string $reason): array
    {
        $perimeter = $this->writer($principal, $establishmentId);
        $tenantId = $principal->tenantId;
        $form = $this->find($tenantId, $establishmentId, $formId);
        if (!in_array($form['form_state'], ['frozen', 'consolidated'], true)) {
            throw HttpException::conflict('Seul un questionnaire gelé se corrige par une nouvelle version ; un brouillon se modifie directement.');
        }
        $latest = $this->latest($tenantId, $establishmentId, (string) $form['year_label'], (string) $form['campaign']);
        if ($latest !== null && $latest['id'] !== $formId) {
            throw HttpException::conflict('Une version plus récente existe déjà.');
        }
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->connections->pdo()->prepare('INSERT INTO pub_census_forms (id, tenant_id, establishment_id, year_label, campaign, version, form_state, payload, entered_by_proxy, correction_reason, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $tenantId, $establishmentId, $form['year_label'], $form['campaign'], (int) $form['version'] + 1, 'draft', $form['payload'], $perimeter->wholeSchool ? 0 : 1, $reason, $principal->userId, $now, $now]);
        $this->audit->append($tenantId, $principal->userId, 'public_schools.census.corrected', 'pub_census_form', $id, ['version' => (int) $form['version']], ['version' => (int) $form['version'] + 1, 'reason' => $reason]);

        return self::resource($this->find($tenantId, $establishmentId, $id));
    }

    /**
     * File des questionnaires qui attendent l'acteur (NOT-11), pour l'école consultée.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(Principal $principal, string $establishmentId): array
    {
        $rows = [];
        $query = $this->connections->pdo()->prepare("SELECT * FROM pub_census_forms WHERE tenant_id = ? AND establishment_id = ? AND form_state IN ('in_review', 'frozen') ORDER BY id");
        $query->execute([$principal->tenantId, $establishmentId]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($this->canApprove($principal, $establishmentId, (string) $row['current_step'])) {
                $rows[] = self::resource($row);
            }
        }

        return $rows;
    }

    /**
     * DOC-23 : questionnaire pré-rempli imprimable (directeur, inspecteur, délégué signent).
     *
     * @return array<string, mixed>
     */
    public function document(Principal $principal, string $establishmentId, string $formId, string $locale): array
    {
        $this->reader($principal, $establishmentId);
        $tenantId = $principal->tenantId;
        $form = $this->find($tenantId, $establishmentId, $formId);
        $payload = (array) json_decode((string) $form['payload'], true);
        $en = $locale === 'en';
        $lines = [];
        foreach ((array) ($payload['sections'] ?? []) as $section) {
            $lines[] = '== ' . (string) ($section[$en ? 'title_en' : 'title_fr'] ?? '');
            foreach ((array) ($section['values'] ?? []) as $field => $value) {
                $lines[] = sprintf('%-40s %10s', (string) $field, $value === null ? '-' : (string) $value);
            }
        }
        $checks = (array) json_decode((string) ($form['check_results'] ?? '[]'), true);
        $subject = $this->users->personIdForUser($tenantId, $principal->userId) ?? throw HttpException::validation(['user' => ['Compte sans personne liée : émission impossible.']]);
        $document = $this->documents->issue($tenantId, $establishmentId, self::DOCUMENT_TYPE, $subject, [
            'school_year' => (string) $form['year_label'], 'campaign_label' => $form['campaign'] === 'end_of_year' ? ($en ? 'End of year' : 'Fin d\'année') : ($en ? 'Start of year' : 'Rentrée'),
            'version_label' => ($en ? 'version ' : 'version ') . (int) $form['version'], 'sections_block' => implode("\n", $lines),
            'checks_block' => implode("\n", array_map('strval', (array) ($checks['anomalies'] ?? []))),
        ], $principal->userId, $en ? 'en' : 'fr');

        return ['document' => ['id' => $document->id, 'number' => $document->number]];
    }

    /**
     * Export au gabarit par défaut (Q3 : format MINEDUB à obtenir) : une ligne par champ.
     *
     * @return list<array<string, scalar|null>>
     */
    public function exportRows(string $tenantId, string $establishmentId, string $formId): array
    {
        $form = $this->find($tenantId, $establishmentId, $formId);
        $payload = (array) json_decode((string) $form['payload'], true);
        $rows = [];
        foreach ((array) ($payload['sections'] ?? []) as $key => $section) {
            foreach ((array) ($section['values'] ?? []) as $field => $value) {
                $rows[] = ['establishment_id' => $establishmentId, 'year_label' => (string) $form['year_label'], 'campaign' => (string) $form['campaign'], 'version' => (int) $form['version'], 'section' => (string) $key, 'field' => (string) $field, 'value' => is_scalar($value) ? $value : null];
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function find(string $tenantId, string $establishmentId, string $formId): array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_census_forms WHERE tenant_id = ? AND establishment_id = ? AND id = ?');
        $query->execute([$tenantId, $establishmentId, $formId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : throw HttpException::notFound('Questionnaire introuvable.');
    }

    /** @return list<array<string, mixed>> */
    public function list(string $tenantId, string $establishmentId, ?string $afterId, int $limit): array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_census_forms WHERE tenant_id = ? AND establishment_id = ? AND id > ? ORDER BY id LIMIT ' . max(1, $limit));
        $query->execute([$tenantId, $establishmentId, $afterId ?? '']);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'year_label' => (string) $row['year_label'], 'campaign' => (string) $row['campaign'], 'version' => (int) $row['version'],
            'state' => (string) $row['form_state'], 'current_step' => $row['current_step'], 'entered_by_proxy' => (int) $row['entered_by_proxy'] === 1,
            'correction_reason' => $row['correction_reason'], 'return_reason' => $row['return_reason'],
            'payload' => (array) json_decode((string) $row['payload'], true), 'checks' => $row['check_results'] === null ? null : (array) json_decode((string) $row['check_results'], true),
        ];
    }

    /** @return array<string, mixed> */
    private function prefill(string $tenantId, string $establishmentId, string $year, string $campaign): array
    {
        $sections = [];
        foreach ($this->providers() as $provider) {
            $sections[$provider->sectionKey()] = ['title_fr' => $provider->title('fr'), 'title_en' => $provider->title('en'), 'values' => $provider->values($tenantId, $establishmentId, $year)];
        }

        return ['campaign' => $campaign, 'sections' => $sections, 'edited' => false];
    }

    /**
     * @param array<string, mixed> $form
     * @return list<string>
     */
    private function check(string $tenantId, string $establishmentId, array $form): array
    {
        $payload = (array) json_decode((string) $form['payload'], true);
        $anomalies = [];
        foreach ($this->providers() as $provider) {
            $values = (array) ($payload['sections'][$provider->sectionKey()]['values'] ?? []);
            foreach ($values as $value) {
                if ((is_int($value) || is_float($value)) && $value < 0) {
                    $anomalies[] = $provider->sectionKey() . '.negative';
                    break;
                }
            }
            array_push($anomalies, ...$provider->controls($tenantId, $establishmentId, (string) $form['year_label'], $values));
        }
        $pupils = $payload['sections']['enrolment']['values']['pupils.total'] ?? null;
        $rooms = $payload['sections']['infrastructure']['values']['classrooms_in_use'] ?? null;
        $ceiling = $this->settings->value($tenantId, $establishmentId, 'public_schools.class_size.alert', EnrolmentCensusSection::DEFAULT_CLASS_CEILING);
        if (is_int($pupils) && is_int($rooms) && $rooms > 0 && $pupils / $rooms > (is_numeric($ceiling) ? (int) $ceiling : EnrolmentCensusSection::DEFAULT_CLASS_CEILING)) {
            $anomalies[] = 'ratio.pupils_per_room_above_ceiling';
        }

        return array_values(array_unique($anomalies));
    }

    /** @return list<CensusSectionProvider> */
    private function providers(): array
    {
        $names = $this->container->getKnownEntryNames();
        sort($names);
        $found = [];
        foreach ($names as $name) {
            if (!str_starts_with($name, self::SECTIONS_PREFIX)) {
                continue;
            }
            foreach ((array) $this->container->get($name) as $entry) {
                $provider = is_string($entry) ? $this->container->get($entry) : $entry;
                if ($provider instanceof CensusSectionProvider) {
                    $found[$provider->sectionKey()] = $provider;
                }
            }
        }

        return array_values($found);
    }

    private function assertStep(Principal $principal, string $establishmentId, string $step): void
    {
        if (!$this->canApprove($principal, $establishmentId, $step)) {
            throw HttpException::forbidden('Ce questionnaire attend la validation d\'un autre niveau (' . $step . ').');
        }
    }

    private function canApprove(Principal $principal, string $establishmentId, string $step): bool
    {
        $perimeter = $this->scope->perimeter($principal, 'public_schools.census.validate', $establishmentId);
        if ($perimeter->isEmpty()) {
            return false;
        }
        if ($step === 'planning') {
            return in_array(self::PLANNING_ROLE, $perimeter->roles, true);
        }
        $type = self::STEPS[$step] ?? null;
        $now = $this->clock->now();
        foreach ($perimeter->territoryIds as $territoryId) {
            if ($this->territories->find($perimeter->tenantId, $territoryId, $now)?->type === $type) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    private function latest(string $tenantId, string $establishmentId, string $year, string $campaign): ?array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_census_forms WHERE tenant_id = ? AND establishment_id = ? AND year_label = ? AND campaign = ? ORDER BY version DESC LIMIT 1');
        $query->execute([$tenantId, $establishmentId, $year, $campaign]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, scalar|null> $values */
    private function update(string $tenantId, string $formId, array $values): void
    {
        $sets = [];
        foreach (array_keys($values) as $column) {
            $sets[] = $column . ' = ?';
        }
        $this->connections->pdo()->prepare('UPDATE pub_census_forms SET ' . implode(', ', $sets) . ', updated_at = ? WHERE tenant_id = ? AND id = ?')
            ->execute([...array_values($values), $this->clock->now(), $tenantId, $formId]);
    }

    private static function isBlocking(string $code): bool
    {
        foreach (self::BLOCKING_MARKS as $mark) {
            if (str_contains($code, $mark)) {
                return true;
            }
        }

        return false;
    }
}

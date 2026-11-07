<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Inspection\Application;

use DateTimeImmutable;
use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PublicMessages;
use Scholaris\Modules\Apps\PublicSchools\Support\Perimeter;
use Scholaris\Modules\Apps\PublicSchools\Support\ScopedByTerritory;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Inspection et suivi pédagogique (INS, T686) :
 *  - grilles de visite paramétrables et versionnées (EX-INS-02), réglées au niveau national (`public_schools.inspection.manage`) ;
 *    une grille d'EXEMPLE `example_*` (préparation, déroulement, évaluation, tenue des registres) à valider par l'Inspection générale ;
 *  - planning des visites de l'inspecteur et du conseiller (EX-INS-01) ;
 *  - rapport saisi hors ligne (lot `sync.handlers.public.inspection_visit`) ou en ligne, idempotent par `client_uuid` ; une visite déjà
 *    rapportée sous un autre identifiant n'est jamais écrasée (409) ;
 *  - comptage sur place comparé à l'effectif du registre de l'application à la date de la visite (EX-INS-04, objectif O1) ;
 *  - recommandations suivies jusqu'à la visite suivante (EX-INS-03), rapport DOC-26 transmis au directeur (message) et lisible par
 *    l'enseignant visité (sa classe) ;
 *  - signalement d'un frais exigé illégalement, traité par l'inspection (EX-INS-05).
 * Échelle de cotation : paramètre `public_schools.inspection.rating_scale` (à confirmer, aucun barème officiel publié).
 */
final class InspectionService
{
    public const READ = 'public_schools.inspection.read';
    public const WRITE = 'public_schools.inspection.write';
    public const MANAGE = 'public_schools.inspection.manage';
    public const SCALE_KEY = 'public_schools.inspection.rating_scale';
    public const DEFAULT_SCALE = [
        ['code' => 'S', 'label_fr' => 'Satisfaisant', 'label_en' => 'Satisfactory'],
        ['code' => 'PS', 'label_fr' => 'Partiellement satisfaisant', 'label_en' => 'Partly satisfactory'],
        ['code' => 'NS', 'label_fr' => 'Non satisfaisant', 'label_en' => 'Unsatisfactory'],
        ['code' => 'NO', 'label_fr' => 'Non observé', 'label_en' => 'Not observed'],
    ];
    public const FOLLOWUP_STATES = ['open', 'in_progress', 'done', 'not_done'];
    public const FEE_STATES = ['open', 'investigating', 'confirmed', 'dismissed'];
    public const REPORTERS = ['parent', 'inspection', 'teacher', 'other'];
    public const DOCUMENT_TYPE = 'pub_doc26_visit_report';
    public const REPORT_TEMPLATE = 'pub.ins.report';

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly ScopedByTerritory $scope,
        private readonly ClassRoster $classes,
        private readonly PersonDirectory $people,
        private readonly UserPersonLink $users,
        private readonly DocumentIssuer $documents,
        private readonly PublicMessages $messages,
        private readonly SettingsReader $settings,
        private readonly InspectionClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /** Lecture : direction et enseignant (leurs rapports), supervision ; 404 hors périmètre. */
    public function reader(Principal $principal, string $establishmentId): Perimeter
    {
        foreach ([self::WRITE, self::READ] as $permission) {
            $perimeter = $this->scope->perimeter($principal, $permission, $establishmentId);
            if (!$perimeter->isEmpty()) {
                return $perimeter;
            }
        }

        throw HttpException::notFound();
    }

    /** Écriture : inspecteur ou conseiller pédagogique dont le territoire contient l'école ; 403 journalisé sinon. */
    public function writer(Principal $principal, string $establishmentId, string $objectType, string $objectId): Perimeter
    {
        $perimeter = $this->scope->perimeter($principal, self::WRITE, $establishmentId);
        if ($perimeter->isEmpty()) {
            $this->scope->assertWritable($principal, $perimeter, null, $objectType, $objectId);
        }

        return $perimeter;
    }

    /** @return list<array{code: string, label_fr: string, label_en: string}> */
    public function scale(string $tenantId, string $establishmentId): array
    {
        $scale = $this->settings->value($tenantId, $establishmentId, self::SCALE_KEY, self::DEFAULT_SCALE);
        $valid = [];
        foreach (is_array($scale) ? $scale : [] as $level) {
            if (is_array($level) && is_string($level['code'] ?? null)) {
                $valid[] = ['code' => (string) $level['code'], 'label_fr' => (string) ($level['label_fr'] ?? $level['code']), 'label_en' => (string) ($level['label_en'] ?? $level['code'])];
            }
        }

        return $valid === [] ? self::DEFAULT_SCALE : $valid;
    }

    // ------------------------------------------------------------------------------------------------------------- grilles

    /** @return list<array<string, mixed>> grilles actives (dernière version de chaque code) */
    public function grids(Principal $principal, string $establishmentId): array
    {
        if ($this->scope->perimeter($principal, self::MANAGE, $establishmentId)->isEmpty()) {
            $this->reader($principal, $establishmentId);
        }
        $query = $this->pdo()->prepare('SELECT * FROM pub_visit_grids WHERE tenant_id = ? AND active = 1 ORDER BY code');
        $query->execute([$principal->tenantId]);

        return array_map(static fn (array $row): array => self::gridResource($row), array_values($query->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * Nouvelle grille ou nouvelle version d'une grille (la précédente devient inactive, les visites gardent la leur).
     *
     * @param array<string, mixed> $input code, label_fr, label_en, items [{code, section, label_fr, label_en}]
     * @return array<string, mixed>
     */
    public function saveGrid(Principal $principal, string $establishmentId, array $input): array
    {
        $this->manager($principal, $establishmentId);
        $errors = [];
        $code = $input['code'] ?? null;
        if (!is_string($code) || preg_match('/^[a-z][a-z0-9_]{1,39}$/', $code) !== 1) {
            $errors['code'][] = 'Code en minuscules (40 caractères au plus).';
        }
        foreach (['label_fr', 'label_en'] as $field) {
            if (!is_string($input[$field] ?? null) || trim($input[$field]) === '' || mb_strlen($input[$field]) > 160) {
                $errors[$field][] = 'Libellé requis (160 caractères au plus).';
            }
        }
        $items = self::items($input['items'] ?? null, $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return $this->insertGrid($principal, (string) $code, trim((string) $input['label_fr']), trim((string) $input['label_en']), $items);
    }

    /**
     * Grille d'exemple étiquetée `example_class_visit` (rubriques de EX-INS-02), à remplacer par la grille officielle.
     *
     * @return array<string, mixed>
     */
    public function importExample(Principal $principal, string $establishmentId): array
    {
        $this->manager($principal, $establishmentId);
        $items = [];
        foreach ([
            'preparation' => [['prep_journal', 'Cahier de préparation à jour', 'Lesson notes up to date'], ['prep_objectives', 'Objectifs de la leçon formulés', 'Lesson objectives stated']],
            'delivery' => [['delivery_participation', 'Participation des élèves', 'Pupil participation'], ['delivery_materials', 'Matériel didactique utilisé', 'Teaching aids used']],
            'evaluation' => [['eval_criteria', 'Vérification des critères de la compétence', 'Competence criteria checked'], ['eval_remediation', 'Remédiation organisée', 'Remediation organised']],
            'registers' => [['reg_attendance', 'Cahier d\'appel tenu', 'Attendance register kept'], ['reg_marks', 'Registre des évaluations tenu', 'Assessment register kept']],
        ] as $section => $lines) {
            foreach ($lines as [$itemCode, $fr, $en]) {
                $items[] = ['code' => $itemCode, 'section' => $section, 'label_fr' => $fr, 'label_en' => $en];
            }
        }

        return $this->insertGrid($principal, 'example_class_visit', 'Grille d\'exemple : visite de classe (à valider)', 'Example grid: classroom visit (to be validated)', $items);
    }

    // ------------------------------------------------------------------------------------------------------------- visites

    /**
     * Planifie une visite (EX-INS-01).
     *
     * @param array<string, mixed> $input planned_on, structure_id?, teacher_person_id?, grid_id?
     * @return array<string, mixed>
     */
    public function plan(Principal $principal, string $establishmentId, array $input): array
    {
        $this->writer($principal, $establishmentId, 'pub_visit', $establishmentId);
        $errors = [];
        $planned = $input['planned_on'] ?? null;
        if (!self::isDate($planned)) {
            $errors['planned_on'][] = 'Date AAAA-MM-JJ requise.';
        }
        [$structureId, $teacherId, $gridId] = $this->target($principal->tenantId, $establishmentId, $input, $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $id = $this->insertVisit($principal, $establishmentId, (string) $planned, $structureId, $teacherId, $gridId);
        $this->audit->append($principal->tenantId, $principal->userId, 'public_schools.inspection.visit_planned', 'pub_visit', $id, null, ['planned_on' => $planned]);

        return $this->visit($principal->tenantId, $establishmentId, $id);
    }

    /**
     * Visites de l'école : la supervision et le directeur voient tout, l'enseignant les visites de sa classe.
     *
     * @return list<array<string, mixed>>
     */
    public function visits(Principal $principal, string $establishmentId): array
    {
        $perimeter = $this->reader($principal, $establishmentId);
        $query = $this->pdo()->prepare('SELECT * FROM pub_visits WHERE tenant_id = ? AND establishment_id = ? ORDER BY planned_on DESC, id');
        $query->execute([$principal->tenantId, $establishmentId]);
        $rows = array_values(array_filter(
            $query->fetchAll(PDO::FETCH_ASSOC),
            static fn (array $row): bool => $perimeter->wholeSchool || $perimeter->isTerritorial() || ($row['structure_id'] !== null && $perimeter->coversClass((string) $row['structure_id'])),
        ));

        return array_map(fn (array $row): array => $this->resource($principal->tenantId, $row), $rows);
    }

    /**
     * Planning de l'acteur (toutes écoles du client), entre deux dates facultatives.
     *
     * @return list<array<string, mixed>>
     */
    public function planning(Principal $principal, string $establishmentId, ?string $from, ?string $to): array
    {
        $this->writer($principal, $establishmentId, 'pub_visit', 'planning');
        $query = $this->pdo()->prepare('SELECT * FROM pub_visits WHERE tenant_id = ? AND inspector_id = ? AND planned_on >= ? AND planned_on <= ? ORDER BY planned_on, id');
        $query->execute([$principal->tenantId, $principal->userId, $from ?? '0000-01-01', $to ?? '9999-12-31']);

        return array_map(fn (array $row): array => $this->resource($principal->tenantId, $row), array_values($query->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @return array<string, mixed> */
    public function show(Principal $principal, string $establishmentId, string $visitId): array
    {
        $perimeter = $this->reader($principal, $establishmentId);
        $row = $this->row($principal->tenantId, $establishmentId, $visitId);
        if (!$perimeter->wholeSchool && !$perimeter->isTerritorial() && ($row['structure_id'] === null || !$perimeter->coversClass((string) $row['structure_id']))) {
            throw HttpException::notFound();
        }

        return $this->resource($principal->tenantId, $row);
    }

    /**
     * Rapport de visite (en ligne ou rejoué depuis un lot hors ligne) : réponses à la grille, comptage, observations, nouvelles
     * recommandations et suivi des recommandations ouvertes de l'école. Idempotent par `client_uuid`.
     *
     * @param array<string, mixed> $input client_uuid, done_on, grid_id?, answers {item: code}, counted_present?, observations?,
     *                                    recommendations [texte], followups [{id, state, note?}]
     * @return array<string, mixed> visite, avec `replayed`
     */
    public function report(Principal $principal, string $establishmentId, string $visitId, array $input): array
    {
        $this->writer($principal, $establishmentId, 'pub_visit', $visitId);
        $tenantId = $principal->tenantId;
        $row = $this->row($tenantId, $establishmentId, $visitId);
        $uuid = $input['client_uuid'] ?? null;
        if (!is_string($uuid) || preg_match('/^[A-Za-z0-9-]{8,40}$/', $uuid) !== 1) {
            throw HttpException::validation(['client_uuid' => ['Identifiant client requis (8 à 40 caractères).']]);
        }
        if ($row['visit_state'] === 'done') {
            if ($row['client_uuid'] === $uuid) {
                return $this->visit($tenantId, $establishmentId, $visitId) + ['replayed' => true];
            }
            throw HttpException::conflict('Visite déjà rapportée : le rapport existant n\'est jamais écrasé.');
        }
        $errors = [];
        $doneOn = $input['done_on'] ?? null;
        if (!self::isDate($doneOn)) {
            $errors['done_on'][] = 'Date AAAA-MM-JJ requise.';
        }
        $gridId = is_string($input['grid_id'] ?? null) ? (string) $input['grid_id'] : ($row['grid_id'] !== null ? (string) $row['grid_id'] : null);
        $grid = $gridId === null ? null : $this->grid($tenantId, $gridId);
        if ($gridId !== null && $grid === null) {
            $errors['grid_id'][] = 'Grille inconnue.';
        }
        $answers = $this->answers($tenantId, $establishmentId, $grid, $input['answers'] ?? [], $errors);
        $counted = $input['counted_present'] ?? null;
        if ($counted !== null && (!is_int($counted) || $counted < 0)) {
            $errors['counted_present'][] = 'Entier positif ou nul attendu.';
        }
        $observations = $input['observations'] ?? null;
        if ($observations !== null && (!is_string($observations) || mb_strlen($observations) > 2000)) {
            $errors['observations'][] = 'Texte de 2000 caractères au plus.';
        }
        $recommendations = $input['recommendations'] ?? [];
        if (!is_array($recommendations) || array_filter($recommendations, static fn ($r): bool => !is_string($r) || trim($r) === '' || mb_strlen($r) > 500) !== []) {
            $errors['recommendations'][] = 'Liste de textes de 500 caractères au plus.';
        }
        $followups = is_array($input['followups'] ?? null) ? $input['followups'] : [];
        foreach ($followups as $index => $followup) {
            if (!is_array($followup) || !is_string($followup['id'] ?? null) || !in_array($followup['state'] ?? null, ['in_progress', 'done', 'not_done'], true)) {
                $errors['followups.' . $index][] = 'Suivi attendu : {id, state: in_progress|done|not_done, note?}.';
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $registry = $row['structure_id'] !== null && is_int($counted) ? $this->classes->headcount($tenantId, $establishmentId, (string) $row['structure_id'], (string) $doneOn) : null;
        $now = $this->clock->now();
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE pub_visits SET visit_state = ?, done_on = ?, grid_id = ?, answers = ?, counted_present = ?, registry_count = ?, observations = ?, client_uuid = ?, updated_at = ? WHERE tenant_id = ? AND id = ?')
                ->execute(['done', $doneOn, $gridId, json_encode($answers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), is_int($counted) ? $counted : null, $registry, is_string($observations) ? trim($observations) : null, $uuid, $now, $tenantId, $visitId]);
            foreach ($followups as $followup) {
                $pdo->prepare("UPDATE pub_recommendations SET followup_state = ?, checked_visit_id = ?, checked_note = ?, updated_at = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ? AND visit_id <> ? AND followup_state IN ('open', 'in_progress')")
                    ->execute([(string) $followup['state'], $visitId, is_string($followup['note'] ?? null) ? mb_substr(trim((string) $followup['note']), 0, 500) : null, $now, $tenantId, $establishmentId, (string) $followup['id'], $visitId]);
            }
            foreach ($recommendations as $label) {
                $pdo->prepare('INSERT INTO pub_recommendations (id, tenant_id, establishment_id, visit_id, label, followup_state, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([(string) new Ulid(), $tenantId, $establishmentId, $visitId, trim((string) $label), 'open', $now, $now]);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        $this->audit->append($tenantId, $principal->userId, 'public_schools.inspection.visit_reported', 'pub_visit', $visitId, null, ['done_on' => $doneOn, 'counted' => $counted, 'registry' => $registry]);

        return $this->visit($tenantId, $establishmentId, $visitId) + ['replayed' => false];
    }

    /**
     * Visite non planifiée saisie sur le terrain (lot hors ligne) : création puis rapport, rejouable par `client_uuid`.
     *
     * @param array<string, mixed> $input mêmes champs que report(), plus structure_id?, teacher_person_id?
     * @return array<string, mixed>
     */
    public function recordUnplanned(Principal $principal, string $establishmentId, array $input): array
    {
        $this->writer($principal, $establishmentId, 'pub_visit', $establishmentId);
        $uuid = $input['client_uuid'] ?? null;
        if (is_string($uuid)) {
            $query = $this->pdo()->prepare('SELECT id FROM pub_visits WHERE tenant_id = ? AND establishment_id = ? AND client_uuid = ?');
            $query->execute([$principal->tenantId, $establishmentId, $uuid]);
            $existing = $query->fetchColumn();
            if (is_string($existing)) {
                return $this->report($principal, $establishmentId, $existing, $input);
            }
        }
        $errors = [];
        if (!self::isDate($input['done_on'] ?? null)) {
            $errors['done_on'][] = 'Date AAAA-MM-JJ requise.';
        }
        [$structureId, $teacherId, $gridId] = $this->target($principal->tenantId, $establishmentId, $input, $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $id = $this->insertVisit($principal, $establishmentId, (string) $input['done_on'], $structureId, $teacherId, $gridId);

        return $this->report($principal, $establishmentId, $id, $input);
    }

    /**
     * DOC-26 : rapport de visite, transmis au directeur (message) ; l'enseignant le lit dans l'application.
     *
     * @return array<string, mixed>
     */
    public function document(Principal $principal, string $establishmentId, string $visitId, string $locale): array
    {
        $this->writer($principal, $establishmentId, 'pub_visit', $visitId);
        $tenantId = $principal->tenantId;
        $row = $this->row($tenantId, $establishmentId, $visitId);
        if ($row['visit_state'] !== 'done') {
            throw HttpException::conflict('Le rapport suit la visite : saisissez-la d\'abord.');
        }
        $en = $locale === 'en';
        $visit = $this->resource($tenantId, $row);
        $grid = $row['grid_id'] !== null ? $this->grid($tenantId, (string) $row['grid_id']) : null;
        $labels = [];
        foreach ($grid === null ? [] : (array) json_decode((string) $grid['items'], true) as $item) {
            $labels[(string) $item['code']] = '[' . (string) $item['section'] . '] ' . (string) ($en ? $item['label_en'] : $item['label_fr']);
        }
        $answers = [];
        foreach ((array) $visit['answers'] as $code => $value) {
            $answers[] = sprintf('%-60s %s', $labels[$code] ?? (string) $code, (string) $value);
        }
        $recommendations = array_map(static fn (array $r): string => '- ' . $r['label'], $visit['recommendations']);
        $followups = array_map(static fn (array $r): string => '- ' . $r['label'] . ' : ' . $r['state'] . ($r['note'] !== null ? ' (' . $r['note'] . ')' : ''), $this->checkedBy($tenantId, $visitId));
        $subject = $this->users->personIdForUser($tenantId, $principal->userId) ?? throw HttpException::validation(['user' => ['Compte sans personne liée : émission impossible.']]);
        $document = $this->documents->issue($tenantId, $establishmentId, self::DOCUMENT_TYPE, $subject, [
            'visit_date' => (string) $row['done_on'], 'inspector_name' => $visit['inspector_name'], 'class_name' => $visit['class_name'], 'teacher_name' => $visit['teacher_name'],
            'grid_label' => $grid === null ? '-' : (string) ($en ? $grid['label_en'] : $grid['label_fr']) . ' v' . (int) $grid['version'],
            'answers_block' => implode("\n", $answers), 'headcount_line' => self::headcountLine($visit, $en), 'observations' => (string) ($row['observations'] ?? '-'),
            'recommendations_block' => implode("\n", $recommendations), 'followups_block' => implode("\n", $followups),
        ], $principal->userId, $en ? 'en' : 'fr');
        $this->pdo()->prepare('UPDATE pub_visits SET report_document_id = ?, updated_at = ? WHERE tenant_id = ? AND id = ?')->execute([$document->id, $this->clock->now(), $tenantId, $visitId]);
        $this->messages->notifyDirector($tenantId, $establishmentId, self::REPORT_TEMPLATE, ['date' => (string) $row['done_on'], 'class_name' => $visit['class_name'] !== '' ? $visit['class_name'] : '-']);

        return ['document' => ['id' => $document->id, 'number' => $document->number]];
    }

    /** @return list<array<string, mixed>> recommandations de l'école, filtrées par état */
    public function recommendations(Principal $principal, string $establishmentId, ?string $state): array
    {
        $visits = [];
        foreach ($this->visits($principal, $establishmentId) as $visit) {
            $visits[$visit['id']] = true;
        }
        $sql = 'SELECT r.* FROM pub_recommendations r WHERE r.tenant_id = ? AND r.establishment_id = ?';
        $params = [$principal->tenantId, $establishmentId];
        if ($state !== null) {
            $sql .= ' AND r.followup_state = ?';
            $params[] = $state;
        }
        $query = $this->pdo()->prepare($sql . ' ORDER BY r.id');
        $query->execute($params);

        return array_values(array_map(static fn (array $r): array => self::recommendationResource($r), array_filter($query->fetchAll(PDO::FETCH_ASSOC), static fn (array $r): bool => isset($visits[(string) $r['visit_id']]))));
    }

    // ------------------------------------------------------------------------------------------------------ frais illégaux

    /** @return list<array<string, mixed>> */
    public function feeReports(Principal $principal, string $establishmentId): array
    {
        $this->writer($principal, $establishmentId, 'pub_fee_report', $establishmentId);
        $query = $this->pdo()->prepare('SELECT * FROM pub_fee_reports WHERE tenant_id = ? AND establishment_id = ? ORDER BY id');
        $query->execute([$principal->tenantId, $establishmentId]);

        return array_map(static fn (array $row): array => self::feeResource($row), array_values($query->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * EX-INS-05 : un frais exigé illégalement (au primaire public, aucune contribution n'est exigible : circulaire MINFI-MINEDUB du
     * 7 mars 2022) est signalé à l'inspection, qui le traite.
     *
     * @param array<string, mixed> $input reported_on, reporter_kind, fee_label, amount?, description
     * @return array<string, mixed>
     */
    public function reportFee(Principal $principal, string $establishmentId, array $input): array
    {
        $this->writer($principal, $establishmentId, 'pub_fee_report', $establishmentId);
        $errors = [];
        if (!self::isDate($input['reported_on'] ?? null)) {
            $errors['reported_on'][] = 'Date AAAA-MM-JJ requise.';
        }
        if (!in_array($input['reporter_kind'] ?? null, self::REPORTERS, true)) {
            $errors['reporter_kind'][] = 'Valeur attendue : ' . implode(', ', self::REPORTERS) . '.';
        }
        foreach (['fee_label' => 160, 'description' => 1000] as $field => $max) {
            if (!is_string($input[$field] ?? null) || trim($input[$field]) === '' || mb_strlen($input[$field]) > $max) {
                $errors[$field][] = 'Texte requis (' . $max . ' caractères au plus).';
            }
        }
        $amount = $input['amount'] ?? null;
        if ($amount !== null && (!is_int($amount) || $amount < 0)) {
            $errors['amount'][] = 'Montant entier en FCFA attendu.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $id = (string) new Ulid();
        $this->pdo()->prepare('INSERT INTO pub_fee_reports (id, tenant_id, establishment_id, reported_on, reporter_kind, fee_label, amount, description, report_state, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $principal->tenantId, $establishmentId, $input['reported_on'], $input['reporter_kind'], trim((string) $input['fee_label']), $amount, trim((string) $input['description']), 'open', $principal->userId, $this->clock->now()]);
        $this->audit->append($principal->tenantId, $principal->userId, 'public_schools.inspection.fee_reported', 'pub_fee_report', $id, null, ['establishment_id' => $establishmentId]);

        return self::feeResource($this->fee($principal->tenantId, $establishmentId, $id));
    }

    /**
     * @param array<string, mixed> $input state (investigating|confirmed|dismissed), resolution
     * @return array<string, mixed>
     */
    public function handleFee(Principal $principal, string $establishmentId, string $reportId, array $input): array
    {
        $this->writer($principal, $establishmentId, 'pub_fee_report', $reportId);
        $row = $this->fee($principal->tenantId, $establishmentId, $reportId);
        $state = $input['state'] ?? null;
        $resolution = $input['resolution'] ?? null;
        $errors = [];
        if (!in_array($state, ['investigating', 'confirmed', 'dismissed'], true)) {
            $errors['state'][] = 'Valeur attendue : investigating, confirmed ou dismissed.';
        }
        if (!is_string($resolution) || trim($resolution) === '' || mb_strlen($resolution) > 1000) {
            $errors['resolution'][] = 'Suite donnée requise (1000 caractères au plus).';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if (in_array($row['report_state'], ['confirmed', 'dismissed'], true)) {
            throw HttpException::conflict('Signalement déjà clos.');
        }
        $this->pdo()->prepare('UPDATE pub_fee_reports SET report_state = ?, resolution = ?, handled_by = ?, handled_at = ? WHERE tenant_id = ? AND id = ?')
            ->execute([$state, trim((string) $resolution), $principal->userId, $this->clock->now(), $principal->tenantId, $reportId]);
        $this->audit->append($principal->tenantId, $principal->userId, 'public_schools.inspection.fee_handled', 'pub_fee_report', $reportId, ['state' => $row['report_state']], ['state' => $state]);

        return self::feeResource($this->fee($principal->tenantId, $establishmentId, $reportId));
    }

    // ------------------------------------------------------------------------------------------------------------- outils

    /** @return array<string, mixed> */
    private function visit(string $tenantId, string $establishmentId, string $visitId): array
    {
        return $this->resource($tenantId, $this->row($tenantId, $establishmentId, $visitId));
    }

    /** @return array<string, mixed> */
    private function row(string $tenantId, string $establishmentId, string $visitId): array
    {
        $query = $this->pdo()->prepare('SELECT * FROM pub_visits WHERE tenant_id = ? AND establishment_id = ? AND id = ?');
        $query->execute([$tenantId, $establishmentId, $visitId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : throw HttpException::notFound('Visite introuvable.');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function resource(string $tenantId, array $row): array
    {
        $inspector = $this->users->personIdForUser($tenantId, (string) $row['inspector_id']);
        $names = [];
        foreach ($this->people->findMany($tenantId, array_values(array_filter([$inspector, $row['teacher_person_id']], 'is_string'))) as $person) {
            $names[$person->id] = $person->fullName();
        }
        $recommendations = $this->pdo()->prepare('SELECT * FROM pub_recommendations WHERE tenant_id = ? AND visit_id = ? ORDER BY id');
        $recommendations->execute([$tenantId, (string) $row['id']]);
        $counted = $row['counted_present'] === null ? null : (int) $row['counted_present'];
        $registry = $row['registry_count'] === null ? null : (int) $row['registry_count'];

        return [
            'id' => (string) $row['id'], 'establishment_id' => (string) $row['establishment_id'], 'inspector_id' => (string) $row['inspector_id'],
            'inspector_name' => $inspector === null ? '' : ($names[$inspector] ?? ''), 'planned_on' => (string) $row['planned_on'], 'state' => (string) $row['visit_state'],
            'done_on' => $row['done_on'], 'structure_id' => $row['structure_id'],
            'class_name' => $row['structure_id'] === null ? '' : ($this->classes->find($tenantId, (string) $row['establishment_id'], (string) $row['structure_id'])->label ?? ''),
            'teacher_person_id' => $row['teacher_person_id'], 'teacher_name' => $row['teacher_person_id'] === null ? '' : ($names[(string) $row['teacher_person_id']] ?? ''),
            'grid_id' => $row['grid_id'], 'answers' => $row['answers'] === null ? [] : (array) json_decode((string) $row['answers'], true),
            'counted_present' => $counted, 'registry_count' => $registry,
            'headcount_gap' => $counted !== null && $registry !== null ? $registry - $counted : null,
            'headcount_gap_rate' => $counted !== null && $registry !== null && $registry > 0 ? round(abs($registry - $counted) * 100 / $registry, 1) : null,
            'observations' => $row['observations'], 'report_document_id' => $row['report_document_id'],
            'recommendations' => array_map(static fn (array $r): array => self::recommendationResource($r), array_values($recommendations->fetchAll(PDO::FETCH_ASSOC))),
        ];
    }

    /** @return list<array<string, mixed>> recommandations suivies lors de cette visite */
    private function checkedBy(string $tenantId, string $visitId): array
    {
        $query = $this->pdo()->prepare('SELECT * FROM pub_recommendations WHERE tenant_id = ? AND checked_visit_id = ? ORDER BY id');
        $query->execute([$tenantId, $visitId]);

        return array_map(static fn (array $r): array => self::recommendationResource($r), array_values($query->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function target(string $tenantId, string $establishmentId, array $input, array &$errors): array
    {
        $structureId = is_string($input['structure_id'] ?? null) && $input['structure_id'] !== '' ? (string) $input['structure_id'] : null;
        if ($structureId !== null && $this->classes->find($tenantId, $establishmentId, $structureId) === null) {
            $errors['structure_id'][] = 'Classe inconnue dans cette école.';
        }
        $teacherId = is_string($input['teacher_person_id'] ?? null) && $input['teacher_person_id'] !== '' ? (string) $input['teacher_person_id'] : null;
        if ($teacherId !== null && $this->people->find($tenantId, $teacherId) === null) {
            $errors['teacher_person_id'][] = 'Enseignant inconnu.';
        }
        $gridId = is_string($input['grid_id'] ?? null) && $input['grid_id'] !== '' ? (string) $input['grid_id'] : null;
        if ($gridId !== null && $this->grid($tenantId, $gridId) === null) {
            $errors['grid_id'][] = 'Grille inconnue.';
        }

        return [$structureId, $teacherId, $gridId];
    }

    private function insertVisit(Principal $principal, string $establishmentId, string $plannedOn, ?string $structureId, ?string $teacherId, ?string $gridId): string
    {
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->pdo()->prepare('INSERT INTO pub_visits (id, tenant_id, establishment_id, inspector_id, planned_on, visit_state, structure_id, teacher_person_id, grid_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $principal->tenantId, $establishmentId, $principal->userId, $plannedOn, 'planned', $structureId, $teacherId, $gridId, $now, $now]);

        return $id;
    }

    /**
     * @param array<string, mixed>|null $grid
     * @param array<string, list<string>> $errors
     * @return array<string, string>
     */
    private function answers(string $tenantId, string $establishmentId, ?array $grid, mixed $answers, array &$errors): array
    {
        if (!is_array($answers)) {
            $errors['answers'][] = 'Objet {item: cote} attendu.';

            return [];
        }
        if ($answers !== [] && $grid === null) {
            $errors['answers'][] = 'Une grille est requise pour saisir des réponses.';

            return [];
        }
        $codes = array_map(static fn (array $item): string => (string) $item['code'], $grid === null ? [] : (array) json_decode((string) $grid['items'], true));
        $scale = array_column($this->scale($tenantId, $establishmentId), 'code');
        $clean = [];
        foreach ($answers as $item => $value) {
            if (!in_array((string) $item, $codes, true) || !in_array($value, $scale, true)) {
                $errors['answers.' . $item][] = 'Rubrique de la grille et cote de l\'échelle (' . implode(', ', $scale) . ') attendues.';
                continue;
            }
            $clean[(string) $item] = (string) $value;
        }

        return $clean;
    }

    /**
     * @param list<array{code: string, section: string, label_fr: string, label_en: string}> $items
     * @return array<string, mixed>
     */
    private function insertGrid(Principal $principal, string $code, string $labelFr, string $labelEn, array $items): array
    {
        $tenantId = $principal->tenantId;
        $query = $this->pdo()->prepare('SELECT MAX(version) FROM pub_visit_grids WHERE tenant_id = ? AND code = ?');
        $query->execute([$tenantId, $code]);
        $version = (int) $query->fetchColumn() + 1;
        $id = (string) new Ulid();
        $this->pdo()->prepare('UPDATE pub_visit_grids SET active = 0 WHERE tenant_id = ? AND code = ?')->execute([$tenantId, $code]);
        $this->pdo()->prepare('INSERT INTO pub_visit_grids (id, tenant_id, code, version, label_fr, label_en, items, active, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $tenantId, $code, $version, $labelFr, $labelEn, json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 1, $principal->userId, $this->clock->now()]);
        $this->audit->append($tenantId, $principal->userId, 'public_schools.inspection.grid_saved', 'pub_visit_grid', $id, null, ['code' => $code, 'version' => $version]);

        return self::gridResource($this->grid($tenantId, $id) ?? throw HttpException::notFound());
    }

    /** @return array<string, mixed>|null */
    private function grid(string $tenantId, string $gridId): ?array
    {
        $query = $this->pdo()->prepare('SELECT * FROM pub_visit_grids WHERE tenant_id = ? AND id = ?');
        $query->execute([$tenantId, $gridId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    private function fee(string $tenantId, string $establishmentId, string $id): array
    {
        $query = $this->pdo()->prepare('SELECT * FROM pub_fee_reports WHERE tenant_id = ? AND establishment_id = ? AND id = ?');
        $query->execute([$tenantId, $establishmentId, $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : throw HttpException::notFound('Signalement introuvable.');
    }

    private function manager(Principal $principal, string $establishmentId): void
    {
        if ($this->scope->perimeter($principal, self::MANAGE, $establishmentId)->isEmpty()) {
            throw HttpException::forbidden('Les grilles de visite se règlent au niveau national (RG-TER-6) : permission ' . self::MANAGE . ' requise.');
        }
    }

    private function pdo(): PDO
    {
        return $this->connections->pdo();
    }

    /**
     * @param array<string, list<string>> $errors
     * @return list<array{code: string, section: string, label_fr: string, label_en: string}>
     */
    private static function items(mixed $items, array &$errors): array
    {
        if (!is_array($items) || !array_is_list($items) || $items === []) {
            $errors['items'][] = 'Liste de rubriques {code, section, label_fr, label_en} requise.';

            return [];
        }
        $clean = [];
        $seen = [];
        foreach ($items as $index => $item) {
            $code = is_array($item) ? ($item['code'] ?? null) : null;
            if (!is_string($code) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $code) !== 1 || isset($seen[$code])
                || !is_string($item['section'] ?? null) || !is_string($item['label_fr'] ?? null) || !is_string($item['label_en'] ?? null)) {
                $errors['items.' . $index][] = 'Rubrique invalide ou code en double.';
                continue;
            }
            $seen[$code] = true;
            $clean[] = ['code' => $code, 'section' => mb_substr((string) $item['section'], 0, 40), 'label_fr' => mb_substr((string) $item['label_fr'], 0, 200), 'label_en' => mb_substr((string) $item['label_en'], 0, 200)];
        }

        return $clean;
    }

    private static function isDate(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    /** @param array<string, mixed> $visit */
    private static function headcountLine(array $visit, bool $en): string
    {
        if ($visit['counted_present'] === null) {
            return $en ? 'not done' : 'non réalisé';
        }

        return sprintf($en ? 'counted %d, register %s, gap %s' : 'comptés %d, registre %s, écart %s', $visit['counted_present'], $visit['registry_count'] ?? '-', $visit['headcount_gap'] ?? '-');
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function gridResource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'code' => (string) $row['code'], 'version' => (int) $row['version'], 'label_fr' => (string) $row['label_fr'],
            'label_en' => (string) $row['label_en'], 'items' => (array) json_decode((string) $row['items'], true), 'active' => (int) $row['active'] === 1,
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function recommendationResource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'visit_id' => (string) $row['visit_id'], 'label' => (string) $row['label'], 'state' => (string) $row['followup_state'],
            'checked_visit_id' => $row['checked_visit_id'], 'note' => $row['checked_note'],
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function feeResource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'reported_on' => (string) $row['reported_on'], 'reporter_kind' => (string) $row['reporter_kind'], 'fee_label' => (string) $row['fee_label'],
            'amount' => $row['amount'] === null ? null : (int) $row['amount'], 'description' => (string) $row['description'], 'state' => (string) $row['report_state'],
            'resolution' => $row['resolution'], 'handled_at' => $row['handled_at'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Territory\Application;

use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Policies\RoleMatrix;
use Scholaris\Modules\Apps\PublicSchools\Support\Perimeter;
use Scholaris\Modules\Apps\PublicSchools\Support\ScopedByTerritory;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;
use Scholaris\Modules\Socle\Reporting\Contracts\TerritoryAggregates;

/**
 * Tableau de bord territorial (T690, DOC-28, API-12, écrans R01 à R07 et R19) : agrégats d'un territoire déjà calculés chaque nuit
 * par `reporting:aggregate-territories` et MASQUÉS par le socle (petites cases, TST-10), plus les indicateurs dérivés du CDC
 * (« Indicateurs et formules ») et le suivi des objectifs O1 à O6. Aucun nom. Un indicateur dont une case utile est masquée vaut null.
 *
 * Accès (`public_schools.aggregates.read`) : un rôle national (niveau client) atteint tout territoire ; un rôle territorial atteint son
 * territoire et ses descendants ; une école n'atteint aucun territoire.
 */
final class DashboardService
{
    public const PERMISSION = 'public_schools.aggregates.read';
    public const DOCUMENT_TYPE = 'pub_doc28_territory_dashboard';

    /** Objectifs du CDC (cibles après un an de pilote, valeurs de départ à valider) => indicateur dérivé suivi. */
    public const OBJECTIVES = [
        'O1' => ['indicator' => 'headcount_gap_rate', 'target_fr' => 'écart déclaré / présent < 2 %', 'target_en' => 'declared / present gap < 2%'],
        'O5' => ['indicator' => 'exam_blocked', 'target_fr' => '0 candidat rejeté pour état civil', 'target_en' => '0 candidate rejected for civil status'],
        'O4' => ['indicator' => 'presence_reporting_rate', 'target_fr' => '90 % des écoles remontent la présence', 'target_en' => '90% of schools report presence'],
        'O6' => ['indicator' => 'fee_reports_open', 'target_fr' => 'gratuité contrôlée, signalements traités', 'target_en' => 'free schooling checked, reports handled'],
    ];

    public function __construct(
        private readonly TerritoryAggregates $aggregates,
        private readonly TerritoryDirectory $territories,
        private readonly ScopedByTerritory $scope,
        private readonly DocumentIssuer $documents,
        private readonly UserPersonLink $users,
        private readonly EstablishmentDirectory $establishments,
    ) {
    }

    /**
     * Territoires du client qui portent au moins une école (régions, départements, arrondissements), pour choisir un territoire sans
     * école active : réservé aux rôles nationaux (niveau client). Le socle n'expose pas de liste des racines : la liste est reconstruite
     * par la lignée du territoire de chaque établissement (contrats Organisation seulement).
     *
     * @return list<array<string, mixed>> nœuds triés par type (région, département, arrondissement) puis libellé, avec `parent_id`
     */
    public function catalogue(Principal $principal): array
    {
        $schools = $this->establishments->forTenant($principal->tenantId);
        if ($schools === [] || !$this->isNational($principal, $schools[0]->id)) {
            throw HttpException::forbidden('La liste des territoires est réservée aux rôles nationaux.');
        }
        $at = (new DateTimeImmutable())->format(DATE_ATOM);
        $seen = [];
        $nodes = [];
        foreach ($schools as $school) {
            $own = $this->territories->territoryOf($principal->tenantId, $school->id, $at);
            if ($own === null || isset($seen[$own->id])) {
                continue;
            }
            $seen[$own->id] = true;
            foreach ($this->territories->lineage($principal->tenantId, $own->id, $at) as $node) {
                $nodes[$node->id] ??= ['id' => $node->id, 'type' => $node->type, 'label_fr' => $node->labelFr, 'label_en' => $node->labelEn, 'code' => $node->code, 'parent_id' => $node->parentId];
            }
        }
        $order = ['region' => 0, 'departement' => 1, 'arrondissement' => 2];
        $rows = array_values($nodes);
        usort($rows, static fn (array $a, array $b): int => [$order[$a['type']] ?? 9, $a['label_fr'], $a['id']] <=> [$order[$b['type']] ?? 9, $b['label_fr'], $b['id']]);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Principal $principal, string $territoryId, ?string $date): array
    {
        $territory = $this->authorise($principal, $territoryId);
        if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw HttpException::validation(['date' => ['Date AAAA-MM-JJ attendue.']]);
        }
        $raw = $this->aggregates->read($principal->tenantId, $territoryId, $date);

        return [
            'territory' => ['id' => $territory->id, 'type' => $territory->type, 'label_fr' => $territory->labelFr, 'label_en' => $territory->labelEn, 'parent_id' => $territory->parentId],
            'reference_date' => $raw['reference_date'], 'minimum_cell_size' => $raw['minimum_cell_size'],
            'derived' => self::derive($raw['indicators']), 'indicators' => $raw['indicators'],
        ];
    }

    /** @return list<array<string, mixed>> territoires enfants accessibles (descente dans le tableau de bord) */
    public function children(Principal $principal, string $territoryId): array
    {
        $this->authorise($principal, $territoryId);
        $at = (new DateTimeImmutable())->format(DATE_ATOM);
        $rows = [];
        foreach ($this->territories->descendantsOf($principal->tenantId, $territoryId, $at) as $node) {
            if ($node->parentId === $territoryId) {
                $rows[] = ['id' => $node->id, 'type' => $node->type, 'label_fr' => $node->labelFr, 'label_en' => $node->labelEn, 'code' => $node->code];
            }
        }

        return $rows;
    }

    /**
     * Point d'entrée : lignée du territoire de l'école active (arrondissement, département, région), limitée aux territoires accessibles.
     *
     * @return list<array<string, mixed>>
     */
    public function entry(Principal $principal, string $establishmentId): array
    {
        $at = (new DateTimeImmutable())->format(DATE_ATOM);
        $own = $this->territories->territoryOf($principal->tenantId, $establishmentId, $at);
        if ($this->scope->perimeter($principal, self::PERMISSION, $establishmentId)->isEmpty()) {
            throw HttpException::notFound();
        }
        $rows = [];
        foreach ($own === null ? [] : $this->territories->lineage($principal->tenantId, $own->id, $at) as $node) {
            if ($this->allowed($principal, $node->id)) {
                $rows[] = ['id' => $node->id, 'type' => $node->type, 'label_fr' => $node->labelFr, 'label_en' => $node->labelEn, 'code' => $node->code];
            }
        }

        return $rows;
    }

    /**
     * DOC-28 : tableau de bord imprimable (inspection, délégation, chaque mois), émis dans le contexte de l'école active.
     *
     * @return array<string, mixed>
     */
    public function document(Principal $principal, string $establishmentId, string $territoryId, string $locale): array
    {
        $dashboard = $this->show($principal, $territoryId, null);
        $en = $locale === 'en';
        $lines = [];
        foreach ($dashboard['derived'] as $indicator) {
            $lines[] = sprintf('%-48s %12s', (string) ($en ? $indicator['label_en'] : $indicator['label_fr']), $indicator['masked'] ? ($en ? 'masked' : 'masqué') : ($indicator['value'] === null ? '-' : (string) $indicator['value']));
        }
        $byCode = array_column($dashboard['derived'], null, 'code');
        $objectives = [];
        foreach (self::OBJECTIVES as $code => $objective) {
            $value = $byCode[$objective['indicator']]['value'] ?? null;
            $objectives[] = sprintf('%-4s %-48s %s', $code, $en ? $objective['target_en'] : $objective['target_fr'], $value === null ? '-' : (string) $value);
        }
        $subject = $this->users->personIdForUser($principal->tenantId, $principal->userId) ?? throw HttpException::validation(['user' => ['Compte sans personne liée : émission impossible.']]);
        $document = $this->documents->issue($principal->tenantId, $establishmentId, self::DOCUMENT_TYPE, $subject, [
            'territory_label' => (string) ($en ? $dashboard['territory']['label_en'] : $dashboard['territory']['label_fr']),
            'reference_date' => (string) ($dashboard['reference_date'] ?? '-'), 'indicators_block' => implode("\n", $lines), 'objectives_block' => implode("\n", $objectives),
            'masking_line' => sprintf($en ? 'Counts below %d are masked (small cells).' : 'Les effectifs inférieurs à %d sont masqués (petites cases).', $dashboard['minimum_cell_size']),
        ], $principal->userId, $en ? 'en' : 'fr');

        return ['document' => ['id' => $document->id, 'number' => $document->number]];
    }

    /**
     * Indicateurs dérivés (CDC, « Indicateurs et formules ») ; une case masquée rend l'indicateur masqué (valeur null).
     *
     * @param list<array{key: string, establishment_count: int, computed_at: string, cells: list<array{key: string, value: int|null, suppressed: bool}>}> $indicators
     * @return list<array{code: string, label_fr: string, label_en: string, formula: string, value: float|int|null, masked: bool}>
     */
    public static function derive(array $indicators): array
    {
        $cells = [];
        foreach ($indicators as $indicator) {
            foreach ($indicator['cells'] as $cell) {
                $cells[$indicator['key']][$cell['key']] = $cell['value'];
            }
        }
        $sum = static function (string $indicator, ?callable $filter = null) use ($cells): ?int {
            $total = 0;
            foreach ($cells[$indicator] ?? [] as $key => $value) {
                if ($filter !== null && !$filter((string) $key)) {
                    continue;
                }
                if ($value === null) {
                    return null;
                }
                $total += $value;
            }

            return $total;
        };
        $has = static fn (string $key, string $needle): bool => str_contains($key, $needle);
        $girls = $sum('public_schools.enrolment', static fn (string $k): bool => $has($k, 'sex=F'));
        $boys = $sum('public_schools.enrolment', static fn (string $k): bool => $has($k, 'sex=M'));
        $pupils = $girls === null || $boys === null ? null : $girls + $boys;
        $teachers = $sum('public_schools.teachers');
        $missing = $sum('public_schools.birth_act', static fn (string $k): bool => $has($k, 'act=missing'));
        $verified = $sum('public_schools.birth_act', static fn (string $k): bool => $has($k, 'act=verified'));
        $passed = $sum('public_primary.exam_results', static fn (string $k): bool => $has($k, 'result=passed'));
        $failed = $sum('public_primary.exam_results', static fn (string $k): bool => $has($k, 'result=failed'));
        $blocked = $sum('public_primary.exam_results', static fn (string $k): bool => $has($k, 'result=blocked'));
        $counted = $sum('public_schools.inspection_headcount', static fn (string $k): bool => $k === 'measure=counted');
        $registry = $sum('public_schools.inspection_headcount', static fn (string $k): bool => $k === 'measure=registry');
        $repeat = $sum('public_primary.promotion_decisions', static fn (string $k): bool => $has($k, 'decision=repeat'));
        $decisions = $sum('public_primary.promotion_decisions');
        $mentionA = $sum('public_primary.unit_mentions', static fn (string $k): bool => str_ends_with($k, 'mention=A'));
        $assessed = $sum('public_primary.unit_mentions', static fn (string $k): bool => !$has($k, 'mention=not_assessed'));
        $ratio = static fn (?int $a, ?int $b, int $scale, int $digits): ?float => $a === null || $b === null || $b === 0 ? null : round($a * $scale / $b, $digits);
        $pupilsPresent = $sum('public_schools.pupil_presence', static fn (string $k): bool => $k === 'state=present');
        $pupilsAbsent = $sum('public_schools.pupil_presence', static fn (string $k): bool => $k === 'state=absent');
        $reporting = $sum('public_schools.pupil_presence', static fn (string $k): bool => $k === 'reporting=school');
        $schoolCount = null;
        foreach ($indicators as $indicator) {
            if ($indicator['key'] === 'public_schools.pupil_presence') {
                $schoolCount = $indicator['establishment_count'];
            }
        }
        $teachersPresent = $sum('public_schools.teacher_presence', static fn (string $k): bool => $k === 'presence=present');
        $teachersDeclared = $sum('public_schools.teacher_presence');
        $rooms = $sum('public_schools.classrooms_in_use');
        $present = $passed === null || $failed === null ? null : $passed + $failed;
        $gap = $counted === null || $registry === null ? null : abs($registry - $counted);
        $line = static fn (string $code, string $fr, string $en, string $formula, float|int|null $value, bool $masked): array => ['code' => $code, 'label_fr' => $fr, 'label_en' => $en, 'formula' => $formula, 'value' => $value, 'masked' => $masked];
        $masked = static fn (string $indicator, ?int ...$parts): bool => isset($cells[$indicator]) && in_array(null, $parts, true);

        return [
            $line('pupils', 'Élèves inscrits', 'Enrolled pupils', 'somme des effectifs', $pupils, $masked('public_schools.enrolment', $girls, $boys)),
            $line('parity_index', 'Indice de parité (filles ÷ garçons)', 'Gender parity index (girls ÷ boys)', 'F / M', $ratio($girls, $boys, 1, 2), $masked('public_schools.enrolment', $girls, $boys)),
            $line('teachers', 'Enseignants en poste', 'Teachers in post', 'somme', $teachers, $masked('public_schools.teachers', $teachers)),
            $line('pupils_per_teacher', 'Élèves par enseignant', 'Pupils per teacher', 'élèves / enseignants', $ratio($pupils, $teachers, 1, 1), $masked('public_schools.teachers', $teachers, $pupils)),
            $line('pupils_per_classroom', 'Élèves par salle utilisée', 'Pupils per classroom in use', 'élèves / salles utilisées (recensement)', $ratio($pupils, $rooms, 1, 1), $masked('public_schools.classrooms_in_use', $rooms) || $masked('public_schools.enrolment', $girls, $boys)),
            $line('pupil_attendance_rate', 'Présence des élèves (%)', 'Pupil attendance (%)', 'présents / (présents + absents) à l\'appel', $ratio($pupilsPresent, $pupilsPresent === null || $pupilsAbsent === null ? null : $pupilsPresent + $pupilsAbsent, 100, 1), $masked('public_schools.pupil_presence', $pupilsPresent, $pupilsAbsent)),
            $line('teacher_attendance_rate', 'Présence des enseignants (%)', 'Teacher attendance (%)', 'présents / enseignants déclarés', $ratio($teachersPresent, $teachersDeclared, 100, 1), $masked('public_schools.teacher_presence', $teachersPresent, $teachersDeclared)),
            $line('presence_reporting_rate', 'Écoles qui remontent la présence (O4, %)', 'Schools reporting presence (O4, %)', 'écoles avec un appel / écoles du territoire', $ratio($reporting, $schoolCount, 100, 1), $masked('public_schools.pupil_presence', $reporting)),
            $line('birth_act_missing_rate', 'Élèves sans acte de naissance (%)', 'Pupils without birth certificate (%)', 'signalés / inscrits', $ratio($missing, $missing === null || $verified === null ? null : $missing + $verified, 100, 1), $masked('public_schools.birth_act', $missing, $verified)),
            $line('a_share', 'Part de A (%)', 'Share of A (%)', 'mentions A / élèves évalués', $ratio($mentionA, $assessed, 100, 1), $masked('public_primary.unit_mentions', $mentionA, $assessed)),
            $line('repeat_rate', 'Redoublement (%)', 'Repetition (%)', 'redoublants / décisions', $ratio($repeat, $decisions, 100, 1), $masked('public_primary.promotion_decisions', $repeat, $decisions)),
            $line('exam_success_rate', 'Réussite au CEP ou au FSLC (%)', 'CEP or FSLC success (%)', 'admis / candidats présents', $ratio($passed, $present, 100, 1), $masked('public_primary.exam_results', $passed, $failed)),
            $line('exam_blocked', 'Candidats bloqués pour état civil (O5)', 'Candidates blocked for civil status (O5)', 'somme', $blocked, $masked('public_primary.exam_results', $blocked)),
            $line('headcount_gap_rate', 'Écart effectifs déclarés / présents (O1, %)', 'Declared / present gap (O1, %)', '|registre - comptés| / registre', $ratio($gap, $registry, 100, 1), $masked('public_schools.inspection_headcount', $counted, $registry)),
            $line('health_treated', 'Enfants touchés par les campagnes de santé', 'Children reached by health campaigns', 'somme', $sum('public_schools.health_campaigns'), $masked('public_schools.health_campaigns', $sum('public_schools.health_campaigns'))),
            $line('fee_reports_open', 'Signalements de frais illégaux en cours', 'Open illegal fee reports', 'ouverts + en instruction', $sum('public_schools.fee_reports', static fn (string $k): bool => $k === 'state=open' || $k === 'state=investigating'), $masked('public_schools.fee_reports', $sum('public_schools.fee_reports', static fn (string $k): bool => $k === 'state=open' || $k === 'state=investigating'))),
        ];
    }

    private function authorise(Principal $principal, string $territoryId): \Scholaris\Modules\Socle\Organisation\Contracts\TerritoryInfo
    {
        $at = (new DateTimeImmutable())->format(DATE_ATOM);
        $territory = preg_match('/^[A-Za-z0-9_-]{1,40}$/', $territoryId) === 1 ? $this->territories->find($principal->tenantId, $territoryId, $at) : null;
        if ($territory === null) {
            throw HttpException::notFound('Territoire introuvable.');
        }
        if (!$this->allowed($principal, $territoryId)) {
            throw HttpException::forbidden('Ce territoire est hors de votre périmètre.');
        }

        return $territory;
    }

    /**
     * Un seul calcul de périmètre (première école du territoire) : rôle national (niveau client) ou affectation territoriale qui
     * contient le territoire demandé. Une école seule n'ouvre aucun tableau territorial.
     */
    private function allowed(Principal $principal, string $territoryId): bool
    {
        $at = (new DateTimeImmutable())->format(DATE_ATOM);
        $schools = $this->territories->establishmentsIn($principal->tenantId, $territoryId, $at);
        if ($schools === []) {
            return false;
        }
        $perimeter = $this->scope->perimeter($principal, self::PERMISSION, $schools[0]);
        if ($perimeter->isEmpty()) {
            return false;
        }
        if ($this->isNationalPerimeter($perimeter)) {
            return true;
        }
        $lineage = array_map(static fn ($node): string => $node->id, $this->territories->lineage($principal->tenantId, $territoryId, $at));

        return array_intersect($lineage, $perimeter->territoryIds) !== [];
    }

    /** Rôle national (niveau client) pour la permission du tableau de bord, jugé sur une école donnée. */
    private function isNational(Principal $principal, string $establishmentId): bool
    {
        return $this->isNationalPerimeter($this->scope->perimeter($principal, self::PERMISSION, $establishmentId));
    }

    private function isNationalPerimeter(Perimeter $perimeter): bool
    {
        if ($perimeter->isEmpty() || !$perimeter->wholeSchool) {
            return false;
        }
        foreach ($perimeter->roles as $role) {
            if (RoleMatrix::levelOf($role) === 'tenant') {
                return true;
            }
        }

        return false;
    }
}

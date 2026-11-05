<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\ReportCards\Application;

use Scholaris\Modules\Apps\Primary\Academic\Application\ReferentialService;
use Scholaris\Modules\Apps\Primary\Academic\Contracts\CompetencyDirectory;
use Scholaris\Modules\Apps\Primary\Councils\Application\CouncilService;
use Scholaris\Modules\Apps\Primary\Support\PrimarySettings;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Socle\Assessment\Contracts\ClassResults;
use Scholaris\Modules\Socle\Assessment\Contracts\CurriculumCatalog;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleCalculator;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDemographics;

/**
 * Détail chiffré du bulletin complet (modèle B, DOC-BUL / C-104), uniquement à partir de données qui existent :
 *  - notes par matière de chaque séquence du trimestre (SEQ1, SEQ2…), moyenne du trimestre (MOY), coefficient de la matière au
 *    catalogue (COEF), total = MOY × COEF et rang dans la classe pour la matière, tout cela par le moteur de notes du socle ;
 *  - totaux par domaine (somme des totaux, des coefficients, moyenne du domaine) d'après les domaines des matières du niveau ;
 *  - profil de la classe : élèves à la moyenne de passage des règles, moyenne de la classe, pourcentage de réussite, moyenne max et min ;
 *  - bloc Travail : tableau d'honneur, félicitations, encouragements, avertissement, blâme, selon les seuils PARAMÉTRÉS de l'école
 *    (`primary.report_card.distinctions`, « à confirmer » : sans seuil, aucune distinction n'est affichée) ;
 *  - date de naissance et matricule de l'élève quand ils sont connus.
 * Une donnée absente n'est jamais affichée ni inventée : la composition (COMP) et les exclusions n'ont aucune source dans les contrats
 * actuels du socle, elles n'apparaissent donc pas. Les résultats d'une classe sont gardés en mémoire le temps d'une émission en lot
 * (`flush()`), pour ne pas recalculer toute la classe pour chaque élève.
 */
final class BulletinDetails
{
    public const DISTINCTIONS = 'primary.report_card.distinctions';
    /** Seuils de distinction : clé du paramètre => (code de la mention, sens de la comparaison). */
    public const THRESHOLDS = [
        'honour_roll_min' => ['honour_roll', 'min'], 'congratulations_min' => ['congratulations', 'min'], 'encouragements_min' => ['encouragements', 'min'],
        'warning_max' => ['warning', 'max'], 'blame_max' => ['blame', 'max'],
    ];

    /** @var array<string, list<array<string, mixed>>> */
    private array $cache = [];

    public function __construct(
        private readonly Store $store,
        private readonly PrimarySettings $settings,
        private readonly ClassResults $results,
        private readonly CurriculumCatalog $catalog,
        private readonly RuleCalculator $calculator,
        private readonly CompetencyDirectory $competencies,
        private readonly PersonDemographics $demographics,
        private readonly EnrollmentDirectory $enrollments,
    ) {
    }

    /** Oublie les résultats de classe mémorisés (début et fin d'une émission en lot, aperçu). */
    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * @param array<string, mixed> $council
     * @return array{sequences: list<array{period_id: string, label: string}>, subjects: list<array<string, mixed>>, domains: list<array<string, mixed>>, class_profile: ?array<string, mixed>, work: ?array<string, mixed>, identity: array{birth_date: ?string, matricule: ?string}}
     */
    public function build(string $tenantId, string $establishmentId, array $council, string $personId): array
    {
        $detail = [
            'sequences' => [], 'subjects' => [], 'domains' => [], 'class_profile' => null, 'work' => null,
            'identity' => $this->identity($tenantId, $establishmentId, $personId),
        ];
        if (!$this->settings->bool($tenantId, $establishmentId, 'primary.report_card.numeric_averages')) {
            return $detail;
        }
        $classId = (string) $council['class_structure_id'];
        $term = $this->classResults($tenantId, $establishmentId, $classId, (string) $council['period_id'], (string) $council['period_label']);
        $mine = null;
        foreach ($term as $row) {
            if ($row['person_id'] === $personId) {
                $mine = $row;
            }
        }
        if ($mine === null || $mine['average'] === null) {
            return $detail;
        }

        $sequenceRows = [];
        foreach (CouncilService::children($council) as $index => $childId) {
            $rows = $this->classResults($tenantId, $establishmentId, $classId, $childId, null);
            $bySubject = [];
            foreach ($rows as $row) {
                if ($row['person_id'] === $personId) {
                    foreach ($row['subjects'] as $subject) {
                        $bySubject[$subject['subject_id']] = $subject['average'];
                    }
                }
            }
            if (array_filter($bySubject, static fn (?float $v): bool => $v !== null) !== []) {
                $label = 'SEQ' . ($index + 1);
                $detail['sequences'][] = ['period_id' => $childId, 'label' => $label];
                $sequenceRows[$childId] = $bySubject;
            }
        }

        $ranks = $this->subjectRanks($term);
        $domainOf = $this->domainOfSubjects($tenantId, $establishmentId, $council);
        $subjects = [];
        foreach ($mine['subjects'] as $subject) {
            $code = $subject['code'];
            $entry = $code === null ? null : $this->catalog->find($tenantId, $establishmentId, ReferentialService::SUBJECT_KEY_PREFIX . $code);
            $coefficient = $entry?->coefficient;
            $average = $subject['average'];
            $subjects[] = [
                'subject_id' => $subject['subject_id'], 'code' => $code, 'name' => $subject['name'] ?? $code ?? '', 'domain_id' => $domainOf[$subject['subject_id']] ?? null,
                'coefficient' => $coefficient, 'average' => $average, 'total' => $average !== null && $coefficient !== null ? round($average * $coefficient, 2) : null,
                'rank' => $ranks[$subject['subject_id']][$personId] ?? null,
                'sequences' => array_map(static fn (array $s): ?float => $sequenceRows[$s['period_id']][$subject['subject_id']] ?? null, $detail['sequences']),
            ];
        }
        $detail['subjects'] = $subjects;
        $detail['domains'] = $this->domains($tenantId, $establishmentId, $council, $subjects);
        $detail['class_profile'] = $this->profile($tenantId, $establishmentId, $council, $term);
        $detail['work'] = $this->work($tenantId, $establishmentId, (float) $mine['average']);

        return $detail;
    }

    /** @return array{birth_date: ?string, matricule: ?string} */
    public function identity(string $tenantId, string $establishmentId, string $personId): array
    {
        $birth = $this->demographics->ofPersons($tenantId, [$personId])[$personId]['birth_date'] ?? null;
        $matricule = null;
        foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && is_string($enrollment->internalNumber) && $enrollment->internalNumber !== '') {
                $matricule = $enrollment->internalNumber;
            }
        }

        return ['birth_date' => is_string($birth) && $birth !== '' ? $birth : null, 'matricule' => $matricule];
    }

    /** @return list<array<string, mixed>> */
    private function classResults(string $tenantId, string $establishmentId, string $classId, string $periodId, ?string $label): array
    {
        $key = $tenantId . '|' . $establishmentId . '|' . $classId . '|' . $periodId;

        return $this->cache[$key] ??= $this->results->compute($tenantId, $establishmentId, $classId, $periodId, $label);
    }

    /**
     * @param list<array<string, mixed>> $term
     * @return array<string, array<string, int>> rang par matière puis par personne (ex æquo au même rang)
     */
    private function subjectRanks(array $term): array
    {
        $bySubject = [];
        foreach ($term as $row) {
            foreach ($row['subjects'] as $subject) {
                if ($subject['average'] !== null) {
                    $bySubject[$subject['subject_id']][(string) $row['person_id']] = $subject['average'];
                }
            }
        }

        $ranks = [];
        foreach ($bySubject as $subjectId => $averages) {
            foreach ($this->calculator->ranks($averages) as $personId => $rank) {
                if (is_int($rank)) {
                    $ranks[(string) $subjectId][(string) $personId] = $rank;
                }
            }
        }

        return $ranks;
    }

    /**
     * @param array<string, mixed> $council
     * @return array<string, string> domaine par identifiant de matière
     */
    private function domainOfSubjects(string $tenantId, string $establishmentId, array $council): array
    {
        $levelId = $council['level_structure_id'] ?? null;
        if (!is_string($levelId)) {
            return [];
        }
        $map = [];
        foreach ($this->store->select('pri_level_subjects', $tenantId, $establishmentId, ['level_structure_id' => $levelId, 'academic_year_id' => (string) $council['academic_year_id'], 'active' => 1], 'sort_order', 500) as $row) {
            $map[(string) $row['subject_id']] = (string) $row['domain_id'];
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $council
     * @param list<array<string, mixed>> $subjects
     * @return list<array{domain_id: string, label_fr: string, label_en: string, coefficient: float, total: float, average: ?float}>
     */
    private function domains(string $tenantId, string $establishmentId, array $council, array $subjects): array
    {
        $labels = $this->competencies->domainsOf($tenantId, $establishmentId, (string) $council['academic_year_id']);
        $groups = [];
        foreach ($subjects as $subject) {
            if ($subject['domain_id'] === null || $subject['total'] === null || $subject['coefficient'] === null) {
                continue;
            }
            $groups[$subject['domain_id']]['coefficient'] = ($groups[$subject['domain_id']]['coefficient'] ?? 0.0) + $subject['coefficient'];
            $groups[$subject['domain_id']]['total'] = ($groups[$subject['domain_id']]['total'] ?? 0.0) + $subject['total'];
        }
        uksort($groups, static fn (string $a, string $b): int => ($labels[$a]['sort_order'] ?? 0) <=> ($labels[$b]['sort_order'] ?? 0));
        $out = [];
        foreach ($groups as $domainId => $sums) {
            $label = $labels[$domainId] ?? null;
            $out[] = [
                'domain_id' => $domainId, 'label_fr' => $label === null ? $domainId : $label['label_fr'], 'label_en' => $label === null ? $domainId : ($label['label_en'] ?? $label['label_fr']),
                'coefficient' => $sums['coefficient'], 'total' => round($sums['total'], 2), 'average' => $sums['coefficient'] > 0.0 ? round($sums['total'] / $sums['coefficient'], 2) : null,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $council
     * @param list<array<string, mixed>> $term
     * @return array{pass_mark: float, size: int, above_pass: int, class_average: float, success_rate: float, max: float, min: float}|null
     */
    private function profile(string $tenantId, string $establishmentId, array $council, array $term): ?array
    {
        $averages = array_values(array_filter(array_map(static fn (array $r): ?float => $r['average'], $term), static fn (?float $v): bool => $v !== null));
        if ($averages === []) {
            return null;
        }
        $passMark = $this->calculator->rulesFor($tenantId, $establishmentId, (string) $council['academic_year_id'], (string) $council['class_structure_id'])->passMark;
        $above = count(array_filter($averages, static fn (float $v): bool => $v >= $passMark));

        return [
            'pass_mark' => $passMark, 'size' => count($averages), 'above_pass' => $above, 'class_average' => round(array_sum($averages) / count($averages), 2),
            'success_rate' => round($above * 100 / count($averages), 1), 'max' => max($averages), 'min' => min($averages),
        ];
    }

    /**
     * Distinctions et sanctions d'après les seuils paramétrés ; null si l'école n'en a fixé aucun.
     *
     * @return array<string, bool>|null
     */
    private function work(string $tenantId, string $establishmentId, float $average): ?array
    {
        $raw = $this->settings->raw($tenantId, $establishmentId, self::DISTINCTIONS);
        if (!is_array($raw)) {
            return null;
        }
        $out = [];
        foreach (self::THRESHOLDS as $key => [$code, $direction]) {
            $threshold = $raw[$key] ?? null;
            if (!is_int($threshold) && !is_float($threshold)) {
                continue;
            }
            $out[$code] = $direction === 'min' ? $average >= $threshold : $average < $threshold;
        }

        return $out === [] ? null : $out;
    }
}

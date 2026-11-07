<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Curriculum\Domain;

/**
 * Contrôle de cohérence d'une maquette avant publication (EX-MAQ-06, RG-MAQ-2, RG-MAQ-3) :
 *  - LMD : exactement N crédits par semestre (paramètre, défaut 30 ; tolérance 0, R13) ; un groupe d'UE à choix compte
 *    `choice_count` × crédits d'une option (options d'un groupe à crédits égaux) ;
 *  - LMD et certificat : toute UE a au moins un EC (« UE sans EC » refusée) et ses crédits = somme des crédits de ses EC ;
 *  - BTS et HND : chaque matière a un coefficient et l'épreuve de l'examen national à laquelle elle est rattachée (EX-MAQ-03) ;
 *  - volumes : chaque EC a un volume présentiel (CM + TD + TP) ; écart avec « 1 crédit = N heures » signalé (avertissement) ;
 *  - LMD : la maquette cite la référence du programme approuvé et la filière a une tutelle pour l'année (RG-MAQ-3).
 * Fonction pure : aucune lecture de base.
 */
final class CoherenceChecker
{
    /**
     * @param list<array<string, mixed>> $semesters id, ordinal, label
     * @param list<array<string, mixed>> $units id, semester_id, code, credits, coefficient, choice_group, choice_count, national_exam_paper
     * @param list<array<string, mixed>> $elements unit_id, code, credits, cm_minutes, td_minutes, tp_minutes, personal_minutes
     * @return array{errors: list<string>, warnings: list<string>, semesters: list<array{ordinal: int, label: string, credits: int, volume_minutes: int}>}
     */
    public static function check(
        string $regime,
        int $creditsPerSemester,
        int $hoursPerCredit,
        array $semesters,
        array $units,
        array $elements,
        ?string $approvedProgrammeRef,
        bool $hasTutelage,
    ): array {
        $errors = [];
        $warnings = [];
        $creditBased = in_array($regime, ['lmd', 'certificate'], true);
        $nationalExam = in_array($regime, ['bts', 'hnd'], true);
        $elementsByUnit = [];
        foreach ($elements as $element) {
            $elementsByUnit[(string) $element['unit_id']][] = $element;
        }
        if ($units === []) {
            $errors[] = 'La maquette ne contient aucune UE.';
        }
        if ($regime === 'lmd') {
            if ($approvedProgrammeRef === null || trim($approvedProgrammeRef) === '') {
                $errors[] = 'Filière sous tutelle : citez la référence du programme approuvé par l\'université de tutelle (RG-MAQ-3).';
            }
            if (!$hasTutelage) {
                $errors[] = 'Filière LMD sans université de tutelle pour l\'année de la maquette (RG-MAQ-3, RG-ORG-8).';
            }
        }

        $totals = [];
        foreach ($semesters as $semester) {
            $semesterUnits = array_values(array_filter($units, static fn (array $u): bool => (string) $u['semester_id'] === (string) $semester['id']));
            $label = (string) $semester['label'];
            [$credits, $groupErrors] = self::semesterCredits($label, $semesterUnits);
            array_push($errors, ...$groupErrors);
            $volume = 0;
            if ($semesterUnits === []) {
                $errors[] = $label . ' : aucune UE.';
            }
            foreach ($semesterUnits as $unit) {
                $unitElements = $elementsByUnit[(string) $unit['id']] ?? [];
                $code = (string) $unit['code'];
                if ($creditBased && $unitElements === []) {
                    $errors[] = 'UE ' . $code . ' sans élément constitutif (EC) : ajoutez au moins un EC.';
                }
                if ($creditBased && $unitElements !== []) {
                    $sum = array_sum(array_map(static fn (array $e): int => (int) $e['credits'], $unitElements));
                    if ($sum !== (int) $unit['credits']) {
                        $errors[] = sprintf('UE %s : %d crédits déclarés, %d crédits dans ses EC.', $code, (int) $unit['credits'], $sum);
                    }
                }
                if ($nationalExam) {
                    if ((float) $unit['coefficient'] <= 0) {
                        $errors[] = 'Matière ' . $code . ' : coefficient obligatoire (BTS, HND).';
                    }
                    if (($unit['national_exam_paper'] ?? null) === null || trim((string) $unit['national_exam_paper']) === '') {
                        $errors[] = 'Matière ' . $code . ' : rattachez-la à une épreuve de l\'examen national (EX-MAQ-03).';
                    }
                }
                foreach ($unitElements as $element) {
                    $presential = (int) $element['cm_minutes'] + (int) $element['td_minutes'] + (int) $element['tp_minutes'];
                    $volume += $presential;
                    if ($presential <= 0) {
                        $errors[] = 'EC ' . $element['code'] . ' : aucun volume horaire (CM, TD ou TP).';
                    }
                    $total = $presential + (int) $element['personal_minutes'];
                    $expected = (int) $element['credits'] * $hoursPerCredit * 60;
                    if ($creditBased && $hoursPerCredit > 0 && (int) $element['credits'] > 0 && $total !== $expected) {
                        $warnings[] = sprintf('EC %s : %s h de travail total pour %d crédit(s), %d h attendues (1 crédit = %d h).', (string) $element['code'], self::hours($total), (int) $element['credits'], intdiv($expected, 60), $hoursPerCredit);
                    }
                }
            }
            if ($regime === 'lmd' && $credits !== $creditsPerSemester) {
                $errors[] = sprintf('%s : %d crédits au lieu de %d (RG-MAQ-2).', $label, $credits, $creditsPerSemester);
            }
            $totals[] = ['ordinal' => (int) $semester['ordinal'], 'label' => $label, 'credits' => $credits, 'volume_minutes' => $volume];
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'semesters' => $totals];
    }

    /**
     * Crédits d'un semestre : UE obligatoires + pour chaque groupe à choix, `choice_count` × crédits d'une option.
     *
     * @param list<array<string, mixed>> $units
     * @return array{0: int, 1: list<string>}
     */
    private static function semesterCredits(string $label, array $units): array
    {
        $credits = 0;
        $errors = [];
        $groups = [];
        foreach ($units as $unit) {
            $group = $unit['choice_group'] ?? null;
            if ($group === null || $group === '') {
                $credits += (int) $unit['credits'];
                continue;
            }
            $groups[(string) $group][] = $unit;
        }
        foreach ($groups as $group => $options) {
            $optionCredits = array_values(array_unique(array_map(static fn (array $u): int => (int) $u['credits'], $options)));
            $counts = array_values(array_unique(array_map(static fn (array $u): int => (int) ($u['choice_count'] ?? 1), $options)));
            if (count($optionCredits) !== 1) {
                $errors[] = sprintf('%s, groupe à choix %s : les options doivent avoir le même nombre de crédits.', $label, $group);
                continue;
            }
            if (count($counts) !== 1 || $counts[0] < 1 || $counts[0] > count($options)) {
                $errors[] = sprintf('%s, groupe à choix %s : nombre d\'UE à choisir incohérent (entre 1 et %d).', $label, $group, count($options));
                continue;
            }
            $credits += $counts[0] * $optionCredits[0];
        }

        return [$credits, $errors];
    }

    private static function hours(int $minutes): string
    {
        return rtrim(rtrim(number_format($minutes / 60, 2, ',', ''), '0'), ',');
    }
}

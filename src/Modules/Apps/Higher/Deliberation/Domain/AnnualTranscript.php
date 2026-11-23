<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Domain;

use Scholaris\Modules\Apps\Higher\Deliberation\Documents\DeliberationDocumentTypes as Types;

/**
 * Relevé de notes et de crédits DOC-10 (annuel et cumulatif) : assemble les relevés semestriels PUBLIÉS de l'année et la décision
 * de fin d'année enregistrée, puis produit les variables du modèle (tableau `annual_table`, emplacements a01_… des anciens modèles).
 * Calcul pur, sans accès aux données : MGP sur 4 pondérée par les crédits de toutes les UE de l'année, crédits capitalisés issus de la
 * décision annuelle, mention de chaque UE donnée par l'échelle de mentions des règles du jury. Rien n'est inventé : une donnée absente
 * s'affiche « — ».
 */
final class AnnualTranscript
{
    /**
     * @param list<array<string, mixed>> $semesters relevés semestriels (TranscriptService::build), UE enrichies d'une clé `mention` (ou null)
     * @param array<string, mixed> $decision ligne hi_year_decisions
     * @return array<string, mixed>
     */
    public static function compose(array $semesters, array $decision, string $locale): array
    {
        $first = $semesters[0];
        $points = 0.0;
        $credits = 0;
        foreach ($semesters as $semester) {
            foreach ($semester['units'] as $unit) {
                if (is_float($unit['points'])) {
                    $points += $unit['points'] * (int) $unit['credits'];
                    $credits += (int) $unit['credits'];
                }
            }
        }
        $last = $semesters[count($semesters) - 1];

        return [
            'locale' => $locale, 'academic_year_id' => (string) $decision['academic_year_id'], 'jury_id' => (string) $last['jury_id'],
            'structure_id' => (string) $first['structure_id'], 'school_id' => (string) $first['school_id'], 'program_id' => (string) $first['program_id'],
            'level_id' => (string) $first['level_id'], 'school' => $first['school'], 'program' => $first['program'], 'level' => $first['level'],
            'authorization_ref' => $first['authorization_ref'], 'tutelage' => $first['tutelage'], 'student' => $first['student'],
            'semesters' => array_map(static fn (array $s): array => [
                'period_id' => (string) $s['period_id'], 'period_label' => (string) $s['period_label'], 'session_label' => (string) $s['session_label'],
                'average' => $s['synthesis']['average'], 'credits_earned' => (int) $s['synthesis']['credits_earned'], 'credits_total' => (int) $s['synthesis']['credits_total'],
                'units' => $s['units'],
            ], $semesters),
            'synthesis' => [
                'average' => $decision['average'] === null ? null : (float) $decision['average'], 'gpa' => $credits > 0 ? round($points / $credits, 2) : null,
                'credits_earned' => (int) $decision['credits_earned'], 'credits_total' => (int) $decision['credits_total'],
                'cumulative_credits' => (int) $decision['cumulative_credits'], 'decision' => (string) $decision['decision_label'],
                'mention' => $decision['mention'] === null ? null : (string) $decision['mention'],
            ],
            'verification_notice' => $locale === 'en' ? 'Only the signed original or the document verified online is authentic' : 'Seul l\'original signé ou vérifié en ligne fait foi',
        ];
    }

    /**
     * @param array<string, mixed> $data résultat de compose()
     * @return array<string, scalar|null>
     */
    public static function variables(array $data): array
    {
        $locale = $data['locale'] === 'en' ? 'en' : 'fr';
        $synthesis = $data['synthesis'];
        $student = $data['student'];
        $vars = [
            'holder_last_name' => (string) $student['last_name'], 'holder_first_names' => (string) $student['first_names'], 'birth_info' => (string) $student['birth_date'],
            'holder_matricule' => (string) $student['matricule'], 'authorization_ref' => (string) $data['authorization_ref'], 'tutelage_name' => (string) $data['tutelage'],
            'academic_year' => (string) $data['academic_year_id'], 'jury_ref' => (string) $data['jury_id'],
            'semester_average' => self::num($synthesis['average'], $locale), 'gpa' => self::num($synthesis['gpa'], $locale),
            'credits_earned' => (string) $synthesis['credits_earned'], 'credits_total' => (string) $synthesis['credits_total'],
            'cumulative_credits' => (string) $synthesis['cumulative_credits'], 'decision' => (string) $synthesis['decision'],
            'mention' => (string) ($synthesis['mention'] ?? '—'), 'rank_info' => '—',
        ];
        foreach ([1, 2] as $n) {
            $semester = $data['semesters'][$n - 1] ?? null;
            $vars['s' . $n . '_label'] = $semester === null ? '—' : (string) $semester['period_label'];
            $vars['s' . $n . '_average'] = $semester === null ? '—' : self::num($semester['average'], $locale);
            $vars['s' . $n . '_credits'] = $semester === null ? '—' : (string) $semester['credits_earned'];
        }
        $flat = [];
        foreach ($data['semesters'] as $semester) {
            foreach ($semester['units'] as $unit) {
                $flat[] = $unit;
            }
        }
        foreach (array_slice($flat, 0, Types::ANNUAL_SLOTS) as $i => $unit) {
            $p = 'a' . Types::slot($i + 1) . '_';
            $vars += [
                $p . 'code' => (string) $unit['code'], $p . 'title' => (string) $unit['name'], $p . 'credits' => (string) $unit['credits'],
                $p . 'average' => self::num($unit['average'], $locale), $p . 'letter' => (string) $unit['letter'], $p . 'result' => (string) $unit['result_label'],
            ];
        }
        $vars['annual_table'] = self::table($data, $locale);

        return $vars;
    }

    /** @param array<string, mixed> $data */
    private static function table(array $data, string $locale): string
    {
        $en = $locale === 'en';
        $bi = static fn (string $fr, string $english): string => $en ? $english . "\n" . $fr : $fr . "\n" . $english;
        $synthesis = $data['synthesis'];
        $rows = [];
        foreach ($data['semesters'] as $semester) {
            $rows[] = ['kind' => 'section', 'cells' => [['text' => $semester['period_label'] . ' (' . $semester['session_label'] . ')', 'colspan' => 8]]];
            foreach ($semester['units'] as $unit) {
                $rows[] = [
                    (string) $unit['code'], ['text' => (string) $unit['name'], 'align' => 'left'], (string) $unit['credits'], self::num($unit['average'], $locale),
                    self::num($unit['points'], $locale), (string) $unit['letter'], (string) ($unit['mention'] ?? '—'), (string) $unit['result_label'],
                ];
            }
        }
        $rows[] = ['kind' => 'total', 'cells' => [
            ['text' => $en ? 'Year total' : 'Total de l\'année', 'colspan' => 2], (string) $synthesis['credits_total'], self::num($synthesis['average'], $locale),
            self::num($synthesis['gpa'], $locale), '', '', ['text' => (string) $synthesis['credits_earned'] . ' / ' . (string) $synthesis['credits_total'] . ($en ? ' credits' : ' crédits')],
        ]];

        return json_encode([
            'columns' => [
                ['label' => 'Code', 'width' => 9], ['label' => $bi('Unités d\'enseignement', 'Course units'), 'width' => 33, 'align' => 'left'],
                ['label' => $bi('Crédits', 'Credits'), 'align' => 'center'], ['label' => $bi('Note /20', 'Mark /20'), 'align' => 'center'],
                ['label' => $bi('Cote /4', 'Points /4'), 'align' => 'center'], ['label' => 'Grade', 'align' => 'center'],
                ['label' => $bi('Mention', 'Honours'), 'align' => 'center'], ['label' => 'Validation', 'align' => 'center'],
            ],
            'rows' => $rows,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public static function num(int|float|null $value, string $locale): string
    {
        if ($value === null) {
            return '—';
        }

        return $locale === 'en' ? number_format((float) $value, 2, '.', ',') : number_format((float) $value, 2, ',', ' ');
    }
}

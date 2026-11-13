<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Dashboards\Documents;

use Scholaris\Modules\Apps\Higher\Documents\Contracts\DocumentTypeProvider;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentType;

/**
 * DOC-32 « Statistiques annuelles d'effectifs MINESUP » (CDC : directeur général, sans QR, non bloquable). Document de synthèse
 * sans titulaire : ses lignes sont celles du tableau MINESUP (export CSV/XLSX `GET /higher/dashboards/minesup/export`), reprises
 * dans des emplacements numérotés (l01_…). Aucun texte officiel inventé : l'en-tête et les mentions viennent du profil documentaire.
 */
final class DashboardDocumentTypes implements DocumentTypeProvider
{
    public const MINESUP_STATISTICS = 'DOC-32';
    public const LINE_SLOTS = 40;

    public function types(): array
    {
        $lines = [];
        $fr = '';
        $en = '';
        for ($i = 1; $i <= self::LINE_SLOTS; ++$i) {
            $slot = 'l' . sprintf('%02d', $i);
            $lines[] = $slot;
            $fr .= '<li>{{' . $slot . '}}</li>';
            $en .= '<li>{{' . $slot . '}}</li>';
        }

        return [
            new HigherDocumentType(
                self::MINESUP_STATISTICS,
                'STA',
                'Statistiques annuelles d\'effectifs MINESUP',
                'Annual MINESUP enrolment statistics',
                'dashboards',
                'directeur_general',
                false,
                false,
                ['academic_year_label', 'total_enrolled', 'summary_line', ...$lines],
                [
                    'fr' => '<p>Année académique {{academic_year_label}} · {{total_enrolled}} inscrits.</p><p>{{summary_line}}</p><ol class="lines">' . $fr . '</ol>',
                    'en' => '<p>Academic year {{academic_year_label}} · {{total_enrolled}} enrolled students.</p><p>{{summary_line}}</p><ol class="lines">' . $en . '</ol>',
                ],
                'other',
                typeLetter: 'S',
            ),
        ];
    }
}

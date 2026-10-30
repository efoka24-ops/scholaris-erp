<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Referential\Application;

/**
 * Référentiel d'EXEMPLE (Q8 : le référentiel officiel validé par l'Inspection générale n'est pas encore fourni). Repris mot pour mot de
 * l'exemple « Paul E., CE1 » du CDC public (« Règles d'évaluation ») ; codes de discipline préfixés `example_` pour qu'aucun bulletin
 * ne les confonde avec le référentiel officiel. Le critère de perfectionnement est un libellé générique, à remplacer.
 */
final class ExampleReferential
{
    private function __construct()
    {
    }

    /** @return list<array{discipline_code: string, label_fr: string, label_en: string, criteria: list<array{code: string, label_fr: string, label_en: string, kind: string}>}> */
    public static function disciplines(string $subsystem): array
    {
        $french = [
            'discipline_code' => 'example_francais', 'label_fr' => 'Français', 'label_en' => 'French',
            'criteria' => [
                ['code' => 'C1', 'label_fr' => 'Pertinence de la production', 'label_en' => 'Relevance of the production', 'kind' => 'minimal'],
                ['code' => 'C2', 'label_fr' => 'Utilisation correcte des outils de la langue', 'label_en' => 'Correct use of language tools', 'kind' => 'minimal'],
                ['code' => 'C3', 'label_fr' => 'Cohérence', 'label_en' => 'Coherence', 'kind' => 'minimal'],
                ['code' => 'CP', 'label_fr' => 'Critère de perfectionnement (exemple)', 'label_en' => 'Excellence criterion (example)', 'kind' => 'perfection'],
            ],
        ];
        $maths = [
            'discipline_code' => 'example_mathematiques', 'label_fr' => 'Mathématiques', 'label_en' => 'Mathematics',
            'criteria' => [
                ['code' => 'C1', 'label_fr' => 'Interprétation correcte de la situation', 'label_en' => 'Correct interpretation of the situation', 'kind' => 'minimal'],
                ['code' => 'C2', 'label_fr' => 'Utilisation correcte des outils', 'label_en' => 'Correct use of tools', 'kind' => 'minimal'],
                ['code' => 'C3', 'label_fr' => 'Cohérence de la réponse', 'label_en' => 'Coherence of the answer', 'kind' => 'minimal'],
                ['code' => 'CP', 'label_fr' => 'Critère de perfectionnement (exemple)', 'label_en' => 'Excellence criterion (example)', 'kind' => 'perfection'],
            ],
        ];
        $english = [
            'discipline_code' => 'example_english', 'label_fr' => 'Anglais', 'label_en' => 'English',
            'criteria' => [
                ['code' => 'C1', 'label_fr' => 'Pertinence', 'label_en' => 'Relevance', 'kind' => 'minimal'],
                ['code' => 'C2', 'label_fr' => 'Utilisation correcte des outils de la langue', 'label_en' => 'Correct use of language tools', 'kind' => 'minimal'],
                ['code' => 'C3', 'label_fr' => 'Cohérence', 'label_en' => 'Coherence', 'kind' => 'minimal'],
                ['code' => 'CP', 'label_fr' => 'Critère de perfectionnement (exemple)', 'label_en' => 'Excellence criterion (example)', 'kind' => 'perfection'],
            ],
        ];

        return $subsystem === 'anglophone' ? [$english, $maths, $french] : [$french, $maths, $english];
    }
}

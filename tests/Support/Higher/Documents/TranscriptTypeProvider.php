<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Documents;

use Scholaris\Modules\Apps\Higher\Documents\Contracts\DocumentTypeProvider;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentType;

/**
 * Fournisseur de test d'un autre module Higher (comme le fera Deliberation pour DOC-09) : relevé de notes semestriel, préfixe REL,
 * pour vérifier le numéro RG-DOC-1 REL-EC-2026-000315 et que la page publique ne montre jamais les notes (RG-DOC-2).
 */
final class TranscriptTypeProvider implements DocumentTypeProvider
{
    public function types(): array
    {
        return [new HigherDocumentType(
            'DOC-09',
            'REL',
            'Relevé de notes semestriel',
            'Semester transcript',
            'deliberation',
            'directeur_ecole',
            true,
            true,
            ['semester_average', 'grades_table'],
            [
                'fr' => '<p>Relevé de {{holder_name}} : {{grades_table}}. Moyenne de semestre : {{semester_average}}.</p>',
                'en' => '<p>Transcript of {{holder_name}}: {{grades_table}}. Semester average: {{semester_average}}.</p>',
            ],
            'bulletin',
            typeLetter: 'R',
        )];
    }
}

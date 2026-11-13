<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Attendance\Documents;

use Scholaris\Modules\Apps\Higher\Documents\Contracts\DocumentTypeProvider;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentType;

/** DOC-31 Notification de sanction disciplinaire (directeur d'école ; QR ; jamais bloquée pour impayé). Modèle de travail (R35). */
final class AttendanceDocumentTypes implements DocumentTypeProvider
{
    public const SANCTION_NOTICE = 'DOC-31';

    public function types(): array
    {
        return [
            new HigherDocumentType(
                self::SANCTION_NOTICE,
                'SAN',
                'Notification de sanction disciplinaire',
                'Disciplinary sanction notice',
                'attendance',
                'directeur_ecole',
                true,
                false,
                ['sanction_label', 'sanction_reason', 'sanction_period', 'council_reference'],
                [
                    'fr' => '<p>Il est notifié à <strong>{{holder_name}}</strong> (matricule {{holder_matricule}}), {{program_name}}, {{level_name}}, la sanction suivante : <strong>{{sanction_label}}</strong>.</p><p>Motif : {{sanction_reason}}.</p><p>Période : {{sanction_period}}. Conseil de discipline : {{council_reference}}.</p>',
                    'en' => '<p><strong>{{holder_name}}</strong> (registration number {{holder_matricule}}), {{program_name}}, {{level_name}}, is hereby notified of the following sanction: <strong>{{sanction_label}}</strong>.</p><p>Reason: {{sanction_reason}}.</p><p>Period: {{sanction_period}}. Disciplinary board: {{council_reference}}.</p>',
                ],
                'other',
                typeLetter: 'S',
            ),
        ];
    }
}

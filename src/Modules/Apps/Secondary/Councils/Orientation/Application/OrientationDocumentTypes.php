<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Orientation\Application;

use Scholaris\Modules\Apps\Secondary\Documents\Contracts\DocumentTypeProvider;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\SecondaryDocumentType as T;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;

/**
 * DOC-16 fiche d'orientation (EX-PAS-03, CDC : conseiller d'orientation, sans QR) [orientation] : émise par le conseiller depuis le
 * dossier d'orientation de l'élève (vœux, avis, entretien, décision et motif), par le contrat SecondaryDocuments, jamais par la route
 * générique des documents. Type et modèles FR/EN du socle : `database/migrations/202611080403_secondary_document_type_doc16.sql`.
 */
final class OrientationDocumentTypes implements DocumentTypeProvider
{
    public const ORIENTATION_SHEET = 'orientation_sheet';

    public function types(): array
    {
        return [
            new T('DOC-16', self::ORIENTATION_SHEET, 'sec_doc16_orientation_sheet', 'Fiche d’orientation', 'Guidance record', false, T::SOURCE_CALLER, OrientationSheet::ISSUERS, Modules::ORIENTATION, false),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Documents\Application;

use Scholaris\Modules\Apps\Secondary\Documents\Contracts\DocumentTypeProvider;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\SecondaryDocumentType as T;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Cycles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;

/**
 * Documents de l'enseignement technique (E3-F, CDC [TEC], Q32) : DOC-21 et DOC-22 avec le module `internships`, DOC-23 et DOC-24 avec
 * `workshops`, et dans tous les cas un profil qui offre l'enseignement technique (aucun test du type d'établissement, D9). Émis par
 * le sous-module propriétaire du parcours par le contrat SecondaryDocuments (pas par la route des documents). Types et modèles FR/EN
 * du socle : `database/migrations/202611070302_secondary_document_types_e3.sql`.
 */
final class TechnicalDocumentTypes implements DocumentTypeProvider
{
    public const INTERNSHIP_AGREEMENT = 'internship_agreement';
    public const TRAINEE_APPRAISAL = 'trainee_appraisal';
    public const WORKSHOP_SHEET = 'workshop_sheet';
    public const MATERIAL_STOCK = 'material_stock';

    private const INTERNSHIPS = ['secondary.internships.manage'];
    private const WORKSHOP_TEACHER = ['secondary.workshops.manage', 'secondary.workshops.session'];
    private const HEAD_OF_WORKSHOPS = ['secondary.workshops.manage'];

    public function types(): array
    {
        $tec = Cycles::TEACHING_TECHNICAL;

        return [
            new T('DOC-21', self::INTERNSHIP_AGREEMENT, 'sec_doc21_internship_agreement', 'Convention de stage', 'Internship agreement', true, T::SOURCE_CALLER, self::INTERNSHIPS, Modules::INTERNSHIPS, false, $tec),
            new T('DOC-22', self::TRAINEE_APPRAISAL, 'sec_doc22_trainee_appraisal', 'Fiche d’appréciation du stagiaire', 'Trainee appraisal form', false, T::SOURCE_CALLER, self::INTERNSHIPS, Modules::INTERNSHIPS, false, $tec),
            new T('DOC-23', self::WORKSHOP_SHEET, 'sec_doc23_workshop_sheet', 'Fiche de travaux pratiques et grille d’évaluation', 'Workshop practical sheet and assessment grid', false, T::SOURCE_CALLER, self::WORKSHOP_TEACHER, Modules::WORKSHOPS, false, $tec),
            new T('DOC-24', self::MATERIAL_STOCK, 'sec_doc24_material_stock', 'État du stock de matière d’œuvre', 'Workshop materials stock report', false, T::SOURCE_CALLER, self::HEAD_OF_WORKSHOPS, Modules::WORKSHOPS, false, $tec),
        ];
    }
}

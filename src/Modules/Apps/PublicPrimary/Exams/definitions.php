<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicPrimary\Exams\Application\BirthActAlertService;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Application\ExamAccess;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Application\ExamAggregates;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Application\ExamCentreService;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Application\ExamListService;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Application\ExamResultService;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Application\ExamSupport;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Application\SecondaryTransferService;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Console\ExamBirthActAlertsCommand;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Http\ExamController;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Http\ExamTerritoryController;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Infrastructure\PdoExams;
use Scholaris\Modules\Socle\Integrations\Contracts\ConfiguredExportType;

/**
 * Examens de la Primaire publique (EXA). Points d'extension : indicateur territorial `public_primary.exam_results`, exports au gabarit
 * par défaut (format officiel des listes à obtenir, Q10 : colonnes paramétrables), tâche NOT-08 `public-primary:exam-birth-acts`.
 */
return [
    PdoExams::class => autowire(),
    ExamAccess::class => autowire(),
    ExamSupport::class => autowire(),
    ExamListService::class => autowire(),
    ExamResultService::class => autowire(),
    ExamCentreService::class => autowire(),
    SecondaryTransferService::class => autowire(),
    BirthActAlertService::class => autowire(),
    ExamAggregates::class => autowire(),
    ExamController::class => autowire(),
    ExamTerritoryController::class => autowire(),
    'reporting.territory_aggregates.public_primary.exams' => [ExamAggregates::class],
    'console.command.' . ExamBirthActAlertsCommand::NAME => autowire(ExamBirthActAlertsCommand::class),
    'integrations.export_types.public_primary.exams' => [
        new ConfiguredExportType(
            ExamController::LIST_EXPORT,
            'Liste des candidats aux examens (gabarit par défaut, format MINEDUB à confirmer, Q10)',
            ['exam', 'year_label', 'learner_number', 'last_name', 'first_name', 'sex', 'birth_date', 'birth_place', 'birth_act_ref', 'school_code', 'class_name', 'candidate_number', 'state'],
            ['examen' => 'exam', 'annee' => 'year_label', 'identifiant_national' => 'learner_number', 'nom' => 'last_name', 'prenoms' => 'first_name', 'sexe' => 'sex', 'date_naissance' => 'birth_date', 'lieu_naissance' => 'birth_place', 'reference_acte' => 'birth_act_ref', 'ecole' => 'school_code', 'classe' => 'class_name', 'numero_candidat' => 'candidate_number'],
            'public_primary.exams.export.columns',
        ),
        new ConfiguredExportType(
            ExamTerritoryController::VACATIONS_EXPORT,
            'État des vacations d\'examen (décret 2010/1738, taux en paramètres)',
            ['kind', 'centre_id', 'name', 'function_code', 'half_days', 'copies_marked', 'amount'],
            ['nature' => 'kind', 'centre' => 'centre_id', 'nom' => 'name', 'fonction' => 'function_code', 'demi_journees' => 'half_days', 'copies_ou_candidats' => 'copies_marked', 'montant' => 'amount'],
            'public_primary.exams.vacations.columns',
        ),
    ],
];

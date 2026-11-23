<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicSchools\Census\Application\CensusClock;
use Scholaris\Modules\Apps\PublicSchools\Census\Application\CensusService;
use Scholaris\Modules\Apps\PublicSchools\Census\Application\EnrolmentCensusSection;
use Scholaris\Modules\Apps\PublicSchools\Census\Http\CensusController;
use Scholaris\Modules\Socle\Integrations\Contracts\ConfiguredExportType;

/**
 * Questionnaire statistique (STA). Sections enregistrées sous `public_schools.census_sections.*` (effectifs ici, infrastructures par
 * Schools, passage par la Primaire publique) ; export au gabarit par défaut `public_schools.census` (format MINEDUB à obtenir, Q3).
 */
return [
    CensusClock::class => autowire(),
    CensusService::class => autowire(),
    EnrolmentCensusSection::class => autowire(),
    CensusController::class => autowire(),
    'public_schools.census_sections.census' => [EnrolmentCensusSection::class],
    'integrations.export_types.public_schools' => [new ConfiguredExportType(
        'public_schools.census',
        'Questionnaire statistique (gabarit par défaut, format MINEDUB à confirmer)',
        ['establishment_id', 'year_label', 'campaign', 'version', 'section', 'field', 'value'],
        ['etablissement' => 'establishment_id', 'annee' => 'year_label', 'campagne' => 'campaign', 'version' => 'version', 'section' => 'section', 'champ' => 'field', 'valeur' => 'value'],
        'public_schools.census.export.columns',
    )],
];

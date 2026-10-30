<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\Tutelage\Domain\PrimaryExportTypes;

/** Types d'export S14 du primaire (colonnes paramétrables par `primary.tutelage.<type>.columns`). */
return [
    'integrations.export_types.primary' => PrimaryExportTypes::types(),
];

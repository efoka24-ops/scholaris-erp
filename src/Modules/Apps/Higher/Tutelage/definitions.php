<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Higher\Tutelage\Application\TutelageClock;
use Scholaris\Modules\Apps\Higher\Tutelage\Infrastructure\TutelageExportTypes;

/** Liaisons de la tutelle (fusionnées par Higher/definitions.php) : horloge et type d'export `tutelle.enrolled` (DOC-18, S14). */
return [
    TutelageClock::class => autowire(),
    'integrations.export_types.higher_tutelage' => TutelageExportTypes::types(),
];

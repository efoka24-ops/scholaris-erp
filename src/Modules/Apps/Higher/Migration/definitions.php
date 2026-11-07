<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Higher\Academic\Contracts\StructureLookup;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\PdoStructureLookup;
use Scholaris\Modules\Apps\Higher\Billing\Application\OpeningBalanceWriter;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\OpeningBalances;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\UnitCreditImport;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\UnitCreditRecorder;
use Scholaris\Modules\Apps\Higher\Enrolment\Application\EnrolmentImport;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\EnrolmentImporter;
use Scholaris\Modules\Apps\Higher\Grades\Application\MarkImport;
use Scholaris\Modules\Apps\Higher\Grades\Contracts\MarkImporter;
use Scholaris\Modules\Apps\Higher\Migration\Application\MigrationClock;
use Scholaris\Modules\Apps\Higher\Migration\Console\PilotSetupCommand;
use Scholaris\Modules\Apps\Higher\Teaching\Application\TeachingImport;
use Scholaris\Modules\Apps\Higher\Teaching\Contracts\TeachingImporter;

/**
 * Liaisons de la reprise de données (lot I4) : les contrats d'écriture additifs de la reprise, chacun fourni par le sous-module qui
 * possède les données (Academic, Enrolment, Deliberation, Billing, Teaching, Grades), et la commande higher:pilot-setup.
 */
return [
    StructureLookup::class => autowire(PdoStructureLookup::class),
    EnrolmentImporter::class => autowire(EnrolmentImport::class),
    UnitCreditRecorder::class => autowire(UnitCreditImport::class),
    OpeningBalances::class => autowire(OpeningBalanceWriter::class),
    TeachingImporter::class => autowire(TeachingImport::class),
    MarkImporter::class => autowire(MarkImport::class),
    MigrationClock::class => autowire(),
    'console.command.' . PilotSetupCommand::NAME => autowire(PilotSetupCommand::class),
];

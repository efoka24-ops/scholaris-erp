<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\Staff\Application\PayrollDocumentTypes;
use Scholaris\Modules\Apps\Secondary\Staff\Application\SecondaryPayabilityCheck;

/** Personnel du secondaire : contrôle de payabilité ajouté au socle Staff (heures avec appel et cahier signé) et type DOC-30. */
return [
    'staff.vacation.payability_checks.secondary' => [SecondaryPayabilityCheck::class],
    'secondary.document_types.staff' => [PayrollDocumentTypes::class],
];

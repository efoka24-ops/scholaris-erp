<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Higher\Portals\Student\DocumentsSection;
use Scholaris\Modules\Apps\Higher\Portals\Student\FinanceSection;

/** Liaisons des portails de l'IPES : sections ajoutées aux portails du socle (/portal/learner, /portal/family, S20). */
return [
    'portals.sections.higher' => [FinanceSection::class, DocumentsSection::class],
];

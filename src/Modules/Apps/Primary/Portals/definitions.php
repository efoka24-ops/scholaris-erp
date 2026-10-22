<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\Portals\Application\PrimaryFamilySection;
use Scholaris\Modules\Apps\Primary\Portals\Application\PrimaryGradingTodo;

/** Section « primary » du portail famille (S20) et « à remplir » du portail enseignant : une clé de liste par application. */
return [
    'portals.sections.primary' => [PrimaryFamilySection::class],
    'portals.grading_todo.primary' => [PrimaryGradingTodo::class],
];

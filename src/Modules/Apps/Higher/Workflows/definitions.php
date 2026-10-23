<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Higher\Workflows\HigherWorkflows;

/** Circuits de validation de l'IPES, déclarés au socle (Socle\Workflows) : notes, corrections, bourses, admissions. */
return [
    HigherWorkflows::class => autowire(),
    'workflows.definitions.higher' => [HigherWorkflows::class],
];

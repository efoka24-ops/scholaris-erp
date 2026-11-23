<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumDirectory;
use Scholaris\Modules\Apps\Higher\Curriculum\Infrastructure\PdoCurriculumDirectory;

/**
 * Liaisons de Curriculum (fusionnées par Higher/definitions.php). Les sondes de notes (RG-MAQ-1) sont déclarées par les
 * sous-modules qui saisissent des notes sous `higher.curriculum.grade_probes.<module>` (Contracts\GradeProbe).
 */
return [
    CurriculumDirectory::class => autowire(PdoCurriculumDirectory::class),
];

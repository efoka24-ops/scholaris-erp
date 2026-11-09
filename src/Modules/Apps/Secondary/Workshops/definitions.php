<?php

declare(strict_types=1);

use function DI\get;

use Scholaris\Modules\Apps\Secondary\Workshops\Application\ConfiguredPracticalGrid;
use Scholaris\Modules\Apps\Secondary\Workshops\Contracts\PracticalGrid;
use Scholaris\Modules\Apps\Secondary\Workshops\Contracts\PracticalMarks;
use Scholaris\Modules\Apps\Secondary\Workshops\Infrastructure\PdoPracticalMarks;

return [
    PracticalGrid::class => get(ConfiguredPracticalGrid::class),
    PracticalMarks::class => get(PdoPracticalMarks::class),
];

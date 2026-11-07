<?php

declare(strict_types=1);

use function DI\get;

use Scholaris\Modules\Apps\Secondary\Workshops\Application\ConfiguredPracticalGrid;
use Scholaris\Modules\Apps\Secondary\Workshops\Contracts\PracticalGrid;

return [
    PracticalGrid::class => get(ConfiguredPracticalGrid::class),
];

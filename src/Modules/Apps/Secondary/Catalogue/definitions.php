<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Secondary\Catalogue\Application\StreamCatalogue;
use Scholaris\Modules\Apps\Secondary\Catalogue\Contracts\StreamDirectory;

/** Liaisons du catalogue des séries et spécialités : contrat offert aux autres sous-modules Secondary. */
return [
    StreamDirectory::class => autowire(StreamCatalogue::class),
];

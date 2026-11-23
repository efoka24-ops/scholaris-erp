<?php

declare(strict_types=1);

use function DI\factory;
use function DI\get;

use Scholaris\Core\Config\Config;
use Scholaris\Modules\Apps\Secondary\Services\Application\Infirmary;
use Scholaris\Modules\Apps\Secondary\Services\Application\ServiceSubscriptions;
use Scholaris\Modules\Apps\Secondary\Services\Contracts\PeExemptions;
use Scholaris\Modules\Apps\Secondary\Services\Contracts\StudentServices;
use Scholaris\Modules\Apps\Secondary\Services\Infrastructure\HealthCipher;

/**
 * Services annexes : clé de chiffrement des données de santé (jamais dans le dépôt), dispenses d'EPS et abonnements d'un élève
 * offerts en lecture aux autres sous-modules (Grades, Portals).
 */
return [
    HealthCipher::class => factory(static fn (Config $config): HealthCipher => HealthCipher::fromConfig($config)),
    PeExemptions::class => get(Infirmary::class),
    StudentServices::class => get(ServiceSubscriptions::class),
];

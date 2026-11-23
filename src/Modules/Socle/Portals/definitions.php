<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Socle\Portals\Contracts\AdultLearnerCheck;
use Scholaris\Modules\Socle\Portals\Infrastructure\PeopleAdultCheck;

/** Liaisons de Portals : majorité lue par le contrat People\BirthInfo (inconnue : consentement exigé). */
return [
    AdultLearnerCheck::class => autowire(PeopleAdultCheck::class),
    // Sections de portail des applications (S20) : clé de liste `portals.sections.<application>`.
    'portals.sections.socle' => [],
];

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Application;

use Scholaris\Modules\Socle\Organisation\Contracts\ApplicationDescriptor;
use Scholaris\Modules\Socle\Organisation\Contracts\ApplicationRegistry;

/** Vocabulaire des quatre applications d'après la maquette (RG-IHM-5). Les applications peuvent le compléter ou le remplacer. */
final class DefaultApplications
{
    private function __construct()
    {
    }

    public static function registry(): ApplicationRegistry
    {
        $registry = new ApplicationRegistry();
        $registry->register(new ApplicationDescriptor('nursery', 'Maternelle', [
            'learner' => 'enfant', 'grouping' => 'section', 'period' => 'trimestre', 'document' => 'carnet de suivi',
        ]));
        $registry->register(new ApplicationDescriptor('primary', 'Primaire', [
            'learner' => 'élève', 'grouping' => 'classe', 'period' => 'séquence', 'document' => 'bulletin APC',
        ]));
        $registry->register(new ApplicationDescriptor('secondary', 'Secondaire', [
            'learner' => 'élève', 'grouping' => 'classe', 'period' => 'trimestre', 'document' => 'bulletin',
        ]));
        $registry->register(new ApplicationDescriptor('higher', 'Supérieur', [
            'learner' => 'étudiant', 'grouping' => 'filière', 'period' => 'semestre', 'document' => 'relevé',
        ]));

        return $registry;
    }
}

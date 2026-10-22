<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Workflows\Contracts;

/**
 * Contrat ENTRANT : une application déclare ses circuits de validation (valeurs par défaut = comportement historique codé en
 * dur). Elle s'enregistre dans le conteneur sous `workflows.definitions.<module>` => [classe ou instance].
 */
interface WorkflowDefinitions
{
    public const PREFIX = 'workflows.definitions.';

    /** @return list<Circuit> */
    public function circuits(): array;
}

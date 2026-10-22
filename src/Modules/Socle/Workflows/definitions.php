<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Socle\Workflows\Application\OverrideValidator;
use Scholaris\Modules\Socle\Workflows\Application\WorkflowRegistry;
use Scholaris\Modules\Socle\Workflows\Application\WorkflowService;
use Scholaris\Modules\Socle\Workflows\Contracts\WorkflowResolver;
use Scholaris\Modules\Socle\Workflows\Infrastructure\WorkflowStore;

/**
 * Liaisons des circuits de validation configurables. Une application déclare ses circuits en enregistrant, sous une clé
 * `workflows.definitions.<module>`, la liste de ses classes WorkflowDefinitions ; la résolution se lit par WorkflowResolver.
 */
return [
    WorkflowStore::class => autowire(),
    OverrideValidator::class => autowire(),
    WorkflowRegistry::class => autowire(),
    WorkflowService::class => autowire(),
    WorkflowResolver::class => get(WorkflowService::class),
];

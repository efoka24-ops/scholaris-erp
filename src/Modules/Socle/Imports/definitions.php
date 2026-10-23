<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Socle\Imports\Application\ScopeResolver;

/**
 * Liaisons du module Imports. `imports.scope_resolvers` : résolveurs de codes de périmètre lisibles (voir Contracts\ScopeCodeResolver),
 * vide par défaut ; une application y ajoute le sien par `\DI\add([\DI\get(MonResolveur::class)])`.
 */
return [
    'imports.scope_resolvers' => [],
    ScopeResolver::class => autowire()->constructorParameter('resolvers', get('imports.scope_resolvers')),
];

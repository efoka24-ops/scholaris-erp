<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Imports\Contracts;

/**
 * Point d'extension : traduit un CODE lisible du fichier d'import (ex. le code d'une école du Supérieur) en identifiant de
 * périmètre. Le socle n'en connaît aucun : par défaut, le code d'une unité ou d'une structure est son identifiant, vérifié dans
 * l'établissement. Une application enregistre son résolveur sans modifier ce module :
 *
 *     'imports.scope_resolvers' => \DI\add([\DI\get(MonResolveur::class)]),
 *
 * Le premier résolveur qui répond l'emporte. Il ne doit jamais sortir de l'établissement donné, et ne retourne que des
 * identifiants vérifiés (le service re-vérifie l'existence par OrgUnitTree).
 */
interface ScopeCodeResolver
{
    /**
     * @param string $type `org_unit` (unité : école, département…) ou `structure` (classe, filière…)
     * @return string|null identifiant de l'unité ou de la structure, null si ce résolveur ne connaît pas le code
     */
    public function resolve(string $tenantId, string $establishmentId, string $type, string $code): ?string;
}

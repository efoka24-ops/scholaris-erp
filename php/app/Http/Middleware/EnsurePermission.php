<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controle une permission RBAC sur une route, sous la forme
 * middleware("permission:students,create").
 *
 * Le Super Admin traverse le controle : il administre la plateforme entiere et
 * ne depend pas des permissions d'un etablissement.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $resource, string $action): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        if (! $user->hasRole('SUPER_ADMIN') && ! $user->hasPermission($resource, $action)) {
            abort(403, "Permission requise : {$resource}:{$action}");
        }

        return $next($request);
    }
}

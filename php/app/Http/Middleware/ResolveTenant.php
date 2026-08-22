<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renseigne l'etablissement courant depuis l'utilisateur authentifie, avant que
 * le moindre modele ne soit interroge.
 *
 * Sans ce middleware, TenantContext reste vide et le scope global laisse passer
 * toutes les lignes : il doit donc etre applique a tout le groupe authentifie.
 */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $this->context->set($user->tenant_id);
        }

        return $next($request);
    }
}

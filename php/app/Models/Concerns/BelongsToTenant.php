<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isolation par etablissement.
 *
 * Applique un scope global sur tenant_id et renseigne la colonne a la creation.
 * Remplace le middleware Prisma de la version Node : le filtrage est ici porte
 * par le modele, donc impossible a oublier dans un controleur.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $context = app(TenantContext::class);

            if ($context->isGlobal() || $context->id() === null) {
                return;
            }

            $query->where($query->getModel()->qualifyColumn('tenant_id'), $context->id());
        });

        static::creating(function ($model) {
            if ($model->tenant_id === null) {
                $model->tenant_id = app(TenantContext::class)->id();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

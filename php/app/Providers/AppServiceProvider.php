<?php

namespace App\Providers;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Une instance par requete : le scope global BelongsToTenant lit ici
        // l'etablissement courant, renseigne par le middleware ResolveTenant.
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        // Interdit le lazy loading hors production : une relation oubliee doit
        // echouer au developpement plutot que de multiplier les requetes en prod.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Camoo sert le sous-domaine derriere un proxy HTTPS : sans cela, les
        // URL generees repartent en http et cassent les formulaires.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use Scholaris\Core\Config\Config;

/**
 * La double authentification est exigée par défaut pour les rôles sensibles (réglage `app.two_factor_required`,
 * variable d'environnement AUTH_2FA_REQUIRED). La désactiver est une décision d'exploitation : les comptes
 * sensibles ne sont alors protégés que par leur mot de passe.
 *
 * Exception non désactivable : l'administrateur de plateforme (rôle `platform_admin`) y est TOUJOURS soumis,
 * quel que soit le réglage, et sans dispense d'« appareil de confiance ».
 */
final class TwoFactorPolicy
{
    public function __construct(private readonly Config $config)
    {
    }

    /** Réglage d'exploitation pour les rôles sensibles ordinaires. */
    public function enforced(): bool
    {
        return filter_var($this->config->get('app.two_factor_required', true), FILTER_VALIDATE_BOOLEAN);
    }

    /** Vrai si la personne doit franchir la double authentification, selon ses rôles. */
    public function requiredFor(bool $hasSensitiveRole, bool $isPlatformAdmin): bool
    {
        return $isPlatformAdmin || ($this->enforced() && $hasSensitiveRole);
    }
}

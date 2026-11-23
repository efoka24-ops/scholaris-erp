<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Application;

use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentLogo;
use Scholaris\Modules\Socle\Organisation\Contracts\LogoImage;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/**
 * Logo à imprimer sur les documents d'un établissement : le sien, sinon le logo du client (paramètre `branding.client_logo_path`,
 * chemin logique sous « tenants/<client>/logo/ »), sinon rien.
 */
final class EstablishmentLogoReader implements EstablishmentLogo
{
    public const CLIENT_LOGO_SETTING = 'branding.client_logo_path';

    public function __construct(private readonly LogoService $logos, private readonly SettingsReader $settings)
    {
    }

    public function find(string $tenantId, string $establishmentId): ?LogoImage
    {
        $own = $this->logos->read($tenantId, $establishmentId);
        if ($own !== null) {
            return $own;
        }

        $path = $this->settings->value($tenantId, null, self::CLIENT_LOGO_SETTING);
        $prefix = 'tenants/' . $tenantId . '/logo/';
        if (!is_string($path) || !str_starts_with($path, $prefix) || str_contains($path, '..') || preg_match('#^[A-Za-z0-9_./-]+$#', $path) !== 1) {
            return null;
        }

        return $this->logos->load($path);
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Policies;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Organisation\Domain\SettingScope;

/**
 * Autorisations sur les paramètres : lecture (organisation.read ou settings.manage), modification (settings.manage).
 * Le niveau plateforme, commun à tous les clients, exige en plus settings.platform (jamais accordé à un client ordinaire).
 */
final class SettingsPolicy
{
    public const READ = 'organisation.read';
    public const MANAGE = 'settings.manage';
    public const PLATFORM = 'settings.platform';

    public function __construct(private readonly PermissionChecker $permissions)
    {
    }

    public function authorizeRead(Principal $principal, ?string $establishmentId): void
    {
        if (!$this->permissions->can($principal, self::READ, $establishmentId)
            && !$this->permissions->can($principal, self::MANAGE, $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de consulter les paramètres.');
        }
    }

    /** Contrôle préalable (avant validation du corps) : droit de modifier les paramètres dans cet établissement. */
    public function authorizeAnyWrite(Principal $principal, ?string $establishmentId): void
    {
        if (!$this->permissions->can($principal, self::MANAGE, $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de modifier les paramètres.');
        }
    }

    public function authorizeWrite(Principal $principal, SettingScope $scope, ?string $establishmentId): void
    {
        $this->authorizeAnyWrite($principal, $establishmentId);
        if ($scope === SettingScope::Platform && !$this->permissions->can($principal, self::PLATFORM, null)) {
            throw HttpException::forbidden('Les paramètres de la plateforme sont réservés à l\'administration de la plateforme.');
        }
        if ($scope === SettingScope::Tenant && !$this->permissions->can($principal, self::MANAGE, null)) {
            throw HttpException::forbidden('Les paramètres du client exigent le droit settings.manage sur tout le client.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Domain;

/** Permissions connues du module Identity et de ses voisins (liste ouverte : les autres modules en ajoutent). */
final class Permission
{
    public const ORGANISATION_READ = 'organisation.read';
    public const ORGANISATION_MANAGE = 'organisation.manage';
    public const SETTINGS_MANAGE = 'settings.manage';
    public const IDENTITY_MANAGE = 'identity.manage';
    public const IDENTITY_ROLES_ASSIGN = 'identity.roles.assign';
}

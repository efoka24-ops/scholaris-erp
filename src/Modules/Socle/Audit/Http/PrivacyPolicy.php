<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Http;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;

/** Autorisations de la conformité. Refus par défaut. */
final class PrivacyPolicy
{
    public const READ = 'privacy.requests.read';
    public const MANAGE = 'privacy.requests.manage';
    public const EXPORT = 'privacy.export';

    public function __construct(private readonly PermissionChecker $permissions)
    {
    }

    public function authorize(Principal $principal, string ...$anyOf): void
    {
        foreach ($anyOf as $permission) {
            if ($this->permissions->can($principal, $permission)) {
                return;
            }
        }

        throw HttpException::forbidden('Vous n\'avez pas le droit d\'effectuer cette action sur la conformité des données.');
    }
}

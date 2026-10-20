<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;

/** Qui peut faire quoi. Refus par défaut : sans permission explicite sur l'établissement actif, 403. */
final class SyncAccess
{
    /** Envoyer un lot hors ligne et lire son résultat (API-04). */
    public const SUBMIT = 'sync.batches.submit';
    /** Inscrire, lister et révoquer les appareils de l'établissement. */
    public const MANAGE_DEVICES = 'sync.devices.manage';

    public function __construct(private readonly PermissionChecker $permissions)
    {
    }

    public function require(Principal $principal, string $establishmentId, string $permission): void
    {
        if (!$this->permissions->can($principal, $permission, $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit d\'effectuer cette opération de synchronisation.');
        }
    }
}

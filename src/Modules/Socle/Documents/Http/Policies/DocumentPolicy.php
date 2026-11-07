<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Http\Policies;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;

/** Autorisations du module Documents. Refus par défaut : sans permission accordée par Identity, la réponse est 403. */
final class DocumentPolicy
{
    public const READ = 'documents.read';
    public const ISSUE = 'documents.issue';
    public const REVOKE = 'documents.revoke';
    /** Rectificatif d'un document (SS12, décision du chef d'établissement). */
    public const RECTIFY = 'documents.rectify';
    public const TEMPLATES = 'documents.templates.manage';
    public const REQUEST = 'documents.request';

    public function __construct(private readonly PermissionChecker $permissions)
    {
    }

    /** Exige au moins une des permissions sur l'établissement actif. */
    public function authorize(Principal $principal, string $establishmentId, string ...$anyOf): void
    {
        foreach ($anyOf as $permission) {
            if ($this->permissions->can($principal, $permission, $establishmentId)) {
                return;
            }
        }

        throw HttpException::forbidden('Vous n\'avez pas le droit d\'effectuer cette action sur les documents.');
    }
}

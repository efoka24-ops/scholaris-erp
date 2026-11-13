<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Support;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/**
 * Porte du droit d'usage de la Maternelle privée : essai, actif et impayé écrivent ; suspendu lit et exporte seulement (423 en
 * écriture) ; fermé ou jamais ouvert : 403 en écriture et 404 en lecture.
 */
final class WriteGate
{
    public const KEY = 'nursery';
    public const SUSPENDED_TYPE = 'https://api.scholaris.cm/errors/application-suspended';
    public const CLOSED_TYPE = 'https://api.scholaris.cm/errors/application-closed';

    public function __construct(private readonly ApplicationAccess $access)
    {
    }

    /** @throws HttpException 423 si suspendu, 403 si fermé ou jamais ouvert */
    public function assertWritable(Principal|string $who): void
    {
        $tenant = $who instanceof Principal ? $who->tenantId : $who;
        if ($this->access->allowsWrites($tenant, self::KEY)) {
            return;
        }
        if ($this->access->allowsReads($tenant, self::KEY)) {
            throw new HttpException(423, 'Application en lecture seule', 'Abonnement suspendu : lecture et export seulement.', self::SUSPENDED_TYPE);
        }

        throw new HttpException(403, 'Application non ouverte', 'L’application Maternelle privée n’est pas ouverte à ce client.', self::CLOSED_TYPE);
    }

    /** @throws HttpException 404 si l'application est fermée ou jamais ouverte */
    public function assertReadable(Principal|string $who): void
    {
        $tenant = $who instanceof Principal ? $who->tenantId : $who;
        if (!$this->access->allowsReads($tenant, self::KEY)) {
            throw HttpException::notFound('L’application Maternelle privée n’est pas ouverte à ce client.');
        }
    }
}

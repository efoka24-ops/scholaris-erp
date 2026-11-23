<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Support;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/** Porte du droit d'écriture de la Maternelle publique : lecture seule en cas de suspension, 403 si l'application n'est pas ouverte. */
final class NurseryWriteGate
{
    public const SUSPENDED_MESSAGE = 'Application Maternelle publique en lecture et export seulement.';
    public const SUSPENDED_TYPE = 'https://api.scholaris.cm/errors/application-suspended';
    public const CLOSED_TYPE = 'https://api.scholaris.cm/errors/application-closed';

    public function __construct(private readonly ApplicationAccess $access)
    {
    }

    public function allowsWrites(string $tenantId): bool
    {
        return $this->access->allowsWrites($tenantId, 'public_nursery');
    }

    public function allowsReads(string $tenantId): bool
    {
        return $this->access->allowsReads($tenantId, 'public_nursery');
    }

    /** @throws HttpException 423 si suspendu, 403 si fermé ou jamais ouvert */
    public function assertWritable(Principal|string $who): void
    {
        $tenantId = $who instanceof Principal ? $who->tenantId : $who;
        if ($this->access->allowsWrites($tenantId, 'public_nursery')) {
            return;
        }
        if ($this->access->allowsReads($tenantId, 'public_nursery')) {
            throw new HttpException(423, 'Application en lecture seule', self::SUSPENDED_MESSAGE, self::SUSPENDED_TYPE);
        }

        throw new HttpException(403, 'Application non ouverte', 'L\'application Maternelle publique n\'est pas ouverte à ce client.', self::CLOSED_TYPE);
    }

    /** @throws HttpException 404 si l'application est fermée ou jamais ouverte */
    public function assertReadable(Principal|string $who): void
    {
        $tenantId = $who instanceof Principal ? $who->tenantId : $who;
        if (!$this->access->allowsReads($tenantId, 'public_nursery')) {
            throw HttpException::notFound('L\'application Maternelle publique n\'est pas ouverte à ce client.');
        }
    }
}

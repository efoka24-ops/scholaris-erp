<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Support;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

final class WriteGate
{
    public function __construct(private readonly ApplicationAccess $access)
    {
    }

    public function assertWritable(Principal|string $who): void
    {
        $tenant = $who instanceof Principal ? $who->tenantId : $who;
        if ($this->access->allowsWrites($tenant, 'primary')) {
            return;
        }
        if ($this->access->allowsReads($tenant, 'primary')) {
            throw new HttpException(423, 'Application en lecture seule', 'Abonnement suspendu : lecture et export seulement.', 'https://api.scholaris.cm/errors/application-suspended');
        }

        throw new HttpException(403, 'Application non ouverte', 'L’application primaire n’est pas ouverte à ce client.', 'https://api.scholaris.cm/errors/application-closed');
    }

    public function assertReadable(Principal|string $who): void
    {
        $tenant = $who instanceof Principal ? $who->tenantId : $who;
        if (!$this->access->allowsReads($tenant, 'primary')) {
            throw HttpException::notFound('L’application primaire n’est pas ouverte à ce client.');
        }
    }
}

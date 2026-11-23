<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Support;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\SecondaryApplication;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/**
 * Porte du droit d'usage de l'application secondaire (EX-SEC-73) : essai, actif ou impayé dans le délai de grâce écrivent ;
 * suspendu = lecture et export seulement (423 en écriture) ; fermé ou jamais ouvert = 403 en écriture, 404 en lecture.
 */
final class WriteGate
{
    public function __construct(private readonly ApplicationAccess $access)
    {
    }

    public function assertWritable(Principal|string $who): void
    {
        $tenant = $who instanceof Principal ? $who->tenantId : $who;
        if ($this->access->allowsWrites($tenant, SecondaryApplication::KEY)) {
            return;
        }
        if ($this->access->allowsReads($tenant, SecondaryApplication::KEY)) {
            throw new HttpException(423, 'Application en lecture seule', 'Abonnement suspendu : lecture et export seulement.', 'https://api.scholaris.cm/errors/application-suspended');
        }

        throw new HttpException(403, 'Application non ouverte', 'L’application Enseignement secondaire n’est pas ouverte à ce client.', 'https://api.scholaris.cm/errors/application-closed');
    }

    public function assertReadable(Principal|string $who): void
    {
        $tenant = $who instanceof Principal ? $who->tenantId : $who;
        if (!$this->access->allowsReads($tenant, SecondaryApplication::KEY)) {
            throw HttpException::notFound('L’application Enseignement secondaire n’est pas ouverte à ce client.');
        }
    }
}

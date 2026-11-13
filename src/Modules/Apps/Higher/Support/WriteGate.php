<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Support;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\HigherApplication;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/**
 * Porte du droit d'usage de l'application (module Platform) : appelée avant TOUTE écriture Higher, requête HTTP (automatiquement
 * par HigherRoutes pour chaque route non GET) ou tâche planifiée (expirations, relances : `allowsWrites` pour sauter un client).
 *
 *  - essai, actif, impayé dans le délai de grâce : lecture et écriture ;
 *  - suspendu : lecture et export seulement, toute écriture répond 423 « abonnement suspendu : lecture et export seulement » ;
 *  - fermé ou jamais ouvert : ni lecture (404, l'application n'existe pas pour ce client) ni écriture (403).
 */
final class WriteGate
{
    public const SUSPENDED_MESSAGE = 'Abonnement suspendu : lecture et export seulement.';
    public const SUSPENDED_TYPE = 'https://api.scholaris.cm/errors/application-suspended';
    public const CLOSED_TYPE = 'https://api.scholaris.cm/errors/application-closed';

    public function __construct(private readonly ApplicationAccess $access)
    {
    }

    /** Vrai si le client peut écrire (tâches planifiées : un client suspendu est sauté sans erreur). */
    public function allowsWrites(string $tenantId): bool
    {
        return $this->access->allowsWrites($tenantId, HigherApplication::KEY);
    }

    public function allowsReads(string $tenantId): bool
    {
        return $this->access->allowsReads($tenantId, HigherApplication::KEY);
    }

    /** @throws HttpException 423 si l'application est suspendue, 403 si elle est fermée ou jamais ouverte au client */
    public function assertWritable(Principal|string $who): void
    {
        $tenantId = $who instanceof Principal ? $who->tenantId : $who;
        if ($this->access->allowsWrites($tenantId, HigherApplication::KEY)) {
            return;
        }
        if ($this->access->allowsReads($tenantId, HigherApplication::KEY)) {
            throw new HttpException(423, 'Application en lecture seule', self::SUSPENDED_MESSAGE, self::SUSPENDED_TYPE);
        }

        throw new HttpException(403, 'Application non ouverte', 'L\'application Supérieur n\'est pas ouverte à ce client.', self::CLOSED_TYPE);
    }

    /** @throws HttpException 404 si l'application est fermée ou jamais ouverte (suspendue : lecture permise) */
    public function assertReadable(Principal|string $who): void
    {
        $tenantId = $who instanceof Principal ? $who->tenantId : $who;
        if (!$this->access->allowsReads($tenantId, HigherApplication::KEY)) {
            throw HttpException::notFound('L\'application Supérieur n\'est pas ouverte à ce client.');
        }
    }
}

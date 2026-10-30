<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Support;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/**
 * Porte du droit d'usage des écoles publiques (module Platform), appelée avant TOUTE écriture : requête HTTP (automatiquement
 * par PublicRoutes pour chaque route non GET) ou tâche planifiée (`allowsWrites` pour sauter un client).
 *
 * `public_schools` n'est pas vendue seule : le droit d'usage est celui de `public_primary` OU `public_nursery` (une route commune
 * est ouverte dès qu'une des deux l'est ; une route d'une application précise exige celle-ci, voir `$application`).
 *  - essai, actif, impayé dans le délai de grâce : lecture et écriture ;
 *  - suspendu : lecture et export seulement, toute écriture répond 423 ;
 *  - fermé ou jamais ouvert : ni lecture (404) ni écriture (403).
 * Elle REMPLACE à terme PublicNursery\Support\NurseryWriteGate (même contrat de réponse).
 */
final class WriteGate
{
    public const SUSPENDED_MESSAGE = 'Abonnement suspendu : lecture et export seulement.';
    public const SUSPENDED_TYPE = 'https://api.scholaris.cm/errors/application-suspended';
    public const CLOSED_TYPE = 'https://api.scholaris.cm/errors/application-closed';

    /** Applications dont le droit d'usage ouvre les modules communs. */
    public const APPLICATIONS = ['public_primary', 'public_nursery'];

    public function __construct(private readonly ApplicationAccess $access)
    {
    }

    /**
     * Vrai si le client peut écrire (tâches planifiées : un client suspendu est sauté sans erreur).
     *
     * @param ?string $application clé d'une application publique ; null = l'une des deux
     */
    public function allowsWrites(string $tenantId, ?string $application = null): bool
    {
        foreach (self::keys($application) as $key) {
            if ($this->access->allowsWrites($tenantId, $key)) {
                return true;
            }
        }

        return false;
    }

    public function allowsReads(string $tenantId, ?string $application = null): bool
    {
        foreach (self::keys($application) as $key) {
            if ($this->access->allowsReads($tenantId, $key)) {
                return true;
            }
        }

        return false;
    }

    /** @throws HttpException 423 si l'application est suspendue, 403 si elle est fermée ou jamais ouverte au client */
    public function assertWritable(Principal|string $who, ?string $application = null): void
    {
        $tenantId = $who instanceof Principal ? $who->tenantId : $who;
        if ($this->allowsWrites($tenantId, $application)) {
            return;
        }
        if ($this->allowsReads($tenantId, $application)) {
            throw new HttpException(423, 'Application en lecture seule', self::SUSPENDED_MESSAGE, self::SUSPENDED_TYPE);
        }

        throw new HttpException(403, 'Application non ouverte', 'L\'application des écoles publiques n\'est pas ouverte à ce client.', self::CLOSED_TYPE);
    }

    /** @throws HttpException 404 si l'application est fermée ou jamais ouverte (suspendue : lecture permise) */
    public function assertReadable(Principal|string $who, ?string $application = null): void
    {
        $tenantId = $who instanceof Principal ? $who->tenantId : $who;
        if (!$this->allowsReads($tenantId, $application)) {
            throw HttpException::notFound('L\'application des écoles publiques n\'est pas ouverte à ce client.');
        }
    }

    /** @return list<string> */
    private static function keys(?string $application): array
    {
        if ($application === null) {
            return self::APPLICATIONS;
        }
        if (!in_array($application, self::APPLICATIONS, true)) {
            throw new \InvalidArgumentException('Application publique inconnue : ' . $application);
        }

        return [$application];
    }
}

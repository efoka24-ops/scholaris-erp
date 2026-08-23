<?php

declare(strict_types=1);

namespace Scholaris\Offline;

use Scholaris\Database\Connection;

/**
 * Journal des ecritures rejouees depuis le mode hors-ligne.
 *
 * Le principe est celui de l'idempotence : le client attache a chaque
 * enregistrement fait hors-ligne un jeton unique qu'il conserve jusqu'a
 * confirmation. Si l'envoi echoue a mi-chemin, il reessaie avec le meme jeton
 * et le serveur reconnait l'operation deja appliquee.
 *
 * Ce filet est indispensable en zone a reseau instable, ou une requete peut
 * parfaitement arriver au serveur sans que la reponse revienne : le client
 * croit avoir echoue, reessaie, et sans jeton l'appel serait fait deux fois.
 */
final class OperationLog
{
    /** Duree de conservation : au-dela, un rejeu n'est plus plausible. */
    private const RETENTION_DAYS = 30;

    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Un jeton deja applique ? Retourne la destination d'alors, sinon null.
     */
    public function replayOf(string $token): ?string
    {
        $row = $this->db->selectOne(
            'SELECT redirect_to FROM sync_operations WHERE token = :token',
            ['token' => $token]
        );

        if ($row === null) {
            return null;
        }

        return $row['redirect_to'] ?? '/dashboard';
    }

    public function record(string $token, ?string $tenantId, ?string $userId, string $path, ?string $redirectTo): void
    {
        $this->db->execute(
            'INSERT INTO sync_operations (token, tenant_id, user_id, path, redirect_to, applied_at)
             VALUES (:token, :tenant, :user, :path, :redirect, :applied_at)',
            [
                'token' => $token,
                'tenant' => $tenantId,
                'user' => $userId,
                'path' => $path,
                'redirect' => $redirectTo,
                'applied_at' => date('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Un jeton doit ressembler a un identifiant genere par le client. Accepter
     * n'importe quelle chaine permettrait de saturer la table depuis
     * l'exterieur.
     */
    public static function isWellFormed(string $token): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $token) === 1;
    }

    public function prune(): void
    {
        $this->db->execute(
            'DELETE FROM sync_operations WHERE applied_at < :cutoff',
            ['cutoff' => date('Y-m-d H:i:s', time() - self::RETENTION_DAYS * 86400)]
        );
    }
}

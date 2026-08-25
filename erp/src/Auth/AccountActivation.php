<?php

declare(strict_types=1);

namespace Scholaris\Auth;

use Scholaris\Database\Connection;
use Scholaris\Database\Table;

/**
 * Jetons d'activation de compte.
 *
 * Un jeton n'est jamais stocke en clair, seulement son hachage SHA-256: une
 * fuite de la table ne permettrait a personne de rejouer un lien. Chaque jeton
 * expire 72 heures apres son emission et ne sert qu'une fois.
 */
final class AccountActivation
{
    private const TTL_HOURS = 72;

    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Emet un jeton pour ce compte et renvoie sa valeur en clair : c'est la
     * seule fois qu'elle existe, elle doit partir dans le lien du courrier.
     */
    public function issue(string $userId): string
    {
        $token = bin2hex(random_bytes(32));

        $this->db->execute(
            'INSERT INTO account_activation_tokens (id, user_id, token_hash, expires_at, created_at)
             VALUES (:id, :user, :hash, :expires, :now)',
            [
                'id' => Table::uuid(),
                'user' => $userId,
                'hash' => hash('sha256', $token),
                'expires' => date('Y-m-d H:i:s', time() + self::TTL_HOURS * 3600),
                'now' => date('Y-m-d H:i:s'),
            ]
        );

        return $token;
    }

    /**
     * Retrouve le jeton s'il est valable : non expire, non deja utilise.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT * FROM account_activation_tokens WHERE token_hash = :hash AND used_at IS NULL LIMIT 1',
            ['hash' => hash('sha256', $token)]
        );

        if ($row === null) {
            return null;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        return $row;
    }

    /** Marque le jeton utilise : il ne servira pas une seconde fois. */
    public function consume(string $tokenRowId): void
    {
        $this->db->execute(
            'UPDATE account_activation_tokens SET used_at = :now WHERE id = :id',
            ['now' => date('Y-m-d H:i:s'), 'id' => $tokenRowId]
        );
    }
}

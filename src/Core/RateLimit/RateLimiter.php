<?php

declare(strict_types=1);

namespace Scholaris\Core\RateLimit;

use Scholaris\Core\Database\ConnectionFactory;

/**
 * Limiteur à fenêtre fixe stocké en base (pas de Redis sur l'hébergement de recette).
 * Une ligne par (clé, fenêtre) ; le compteur est incrémenté de façon atomique.
 */
final class RateLimiter
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly Clock $clock = new Clock(),
    ) {
    }

    /**
     * Enregistre un appel et retourne le nombre de secondes à attendre si la limite est dépassée, sinon 0.
     */
    public function hit(string $key, int $limit, int $windowSeconds): int
    {
        $now = $this->clock->now();
        $windowStart = $now - ($now % $windowSeconds);
        $bucket = hash('sha256', $key);
        $pdo = $this->connections->pdo();

        $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?')->execute([$now - 86400]);

        $update = $pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ? AND window_start = ?');
        $update->execute([$bucket, $windowStart]);
        if ($update->rowCount() === 0) {
            $pdo->prepare('INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)')
                ->execute([$bucket, $windowStart]);
        }

        $select = $pdo->prepare('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?');
        $select->execute([$bucket, $windowStart]);
        $hits = (int) $select->fetchColumn();

        return $hits > $limit ? max(1, $windowStart + $windowSeconds - $now) : 0;
    }
}

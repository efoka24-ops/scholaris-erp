<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

/**
 * Compteurs d'essais par identifiant (haché) : échecs de connexion, verrouillage, demandes et essais de code.
 * Ils existent aussi pour les identifiants inconnus, afin que les réponses soient indiscernables.
 */
final class PdoLoginThrottleRepository extends PdoRepository
{
    /** @return array{failed_count: int, locked_until: ?string, last_otp_at: ?string, otp_attempts: int, otp_window_start: ?string, otp_count: int} */
    public function state(string $key): array
    {
        $row = $this->one('SELECT * FROM login_attempts WHERE identifier_hash = ?', [$key]);

        return [
            'failed_count' => $row === null ? 0 : (int) $row['failed_count'],
            'locked_until' => $row === null ? null : self::nullableString($row['locked_until']),
            'last_otp_at' => $row === null ? null : self::nullableString($row['last_otp_at']),
            'otp_attempts' => $row === null ? 0 : (int) $row['otp_attempts'],
            'otp_window_start' => $row === null ? null : self::nullableString($row['otp_window_start']),
            'otp_count' => $row === null ? 0 : (int) $row['otp_count'],
        ];
    }

    /** @param array{failed_count: int, locked_until: ?string, last_otp_at: ?string, otp_attempts: int, otp_window_start: ?string, otp_count: int} $state */
    public function save(string $key, array $state, string $now): void
    {
        $updated = $this->run(
            'UPDATE login_attempts SET failed_count = ?, locked_until = ?, last_otp_at = ?, otp_attempts = ?, otp_window_start = ?, otp_count = ?, updated_at = ?
             WHERE identifier_hash = ?',
            [
                $state['failed_count'], $state['locked_until'], $state['last_otp_at'], $state['otp_attempts'],
                $state['otp_window_start'], $state['otp_count'], $now, $key,
            ],
        );
        if ($updated === 0) {
            $this->run(
                'INSERT INTO login_attempts (identifier_hash, failed_count, locked_until, last_otp_at, otp_attempts, otp_window_start, otp_count, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $key, $state['failed_count'], $state['locked_until'], $state['last_otp_at'], $state['otp_attempts'],
                    $state['otp_window_start'], $state['otp_count'], $now,
                ],
            );
        }
    }
}

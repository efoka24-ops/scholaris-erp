<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Application;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Audit\Infrastructure\PrivacyDb;

/** Paramètres de conformité d'un client : délai de réponse et durées de rétention (en mois). */
final class PrivacySettings
{
    /** @var array<string, array{default: int, min: int, max: int}> */
    public const DEFINITIONS = [
        'deadline_days' => ['default' => 30, 'min' => 1, 'max' => 90],
        'requests_retention_months' => ['default' => 36, 'min' => 1, 'max' => 240],
        'approvals_retention_months' => ['default' => 60, 'min' => 1, 'max' => 240],
    ];

    public function __construct(
        private readonly PrivacyDb $db,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        private readonly string $timezone = 'Africa/Douala',
    ) {
    }

    /** @return array<string, int> */
    public function all(string $tenantId): array
    {
        $values = array_map(static fn (array $d): int => $d['default'], self::DEFINITIONS);
        foreach ($this->db->rows('SELECT setting_name, setting_value FROM privacy_settings WHERE tenant_id = ?', [$tenantId]) as $row) {
            if (isset($values[(string) $row['setting_name']])) {
                $values[(string) $row['setting_name']] = (int) $row['setting_value'];
            }
        }

        return $values;
    }

    public function get(string $tenantId, string $name): int
    {
        return $this->all($tenantId)[$name];
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, int>
     */
    public function update(string $tenantId, array $changes, string $by): array
    {
        $errors = [];
        foreach ($changes as $name => $value) {
            $def = self::DEFINITIONS[$name] ?? null;
            if ($def === null) {
                $errors[(string) $name] = ['Paramètre inconnu.'];
            } elseif (!is_int($value) || $value < $def['min'] || $value > $def['max']) {
                $errors[(string) $name] = [sprintf('Entier attendu entre %d et %d.', $def['min'], $def['max'])];
            }
        }
        if ($errors !== [] || $changes === []) {
            throw HttpException::validation($errors === [] ? ['settings' => ['Aucun paramètre fourni.']] : $errors);
        }

        $before = $this->all($tenantId);
        $now = (new DateTimeImmutable('@' . $this->clock->now()))->setTimezone(new DateTimeZone($this->timezone))->format(DATE_ATOM);
        foreach ($changes as $name => $value) {
            $updated = $this->db->exec(
                'UPDATE privacy_settings SET setting_value = ?, updated_by = ?, updated_at = ? WHERE tenant_id = ? AND setting_name = ?',
                [(string) $value, $by, $now, $tenantId, $name],
            );
            if ($updated === 0) {
                $this->db->exec(
                    'INSERT INTO privacy_settings (tenant_id, setting_name, setting_value, updated_by, updated_at) VALUES (?, ?, ?, ?, ?)',
                    [$tenantId, $name, (string) $value, $by, $now],
                );
            }
        }
        $after = $this->all($tenantId);
        $this->audit->append($tenantId, $by, 'privacy.settings.updated', 'privacy_settings', $tenantId, $before, $after);

        return $after;
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Support;

use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/**
 * Paramètres `nursery.*` d'une école (SC-MAT-5) : AUCUN seuil, délai ni règle n'est écrit dans le code. Les valeurs viennent de
 * `/settings` (niveaux plateforme, client, établissement) ; les valeurs de départ proposées sont des DONNÉES posées au niveau
 * plateforme par la migration `nursery_settings_defaults` et marquées « exemple à valider ». Une clé sans valeur rend null : la
 * fonction concernée reste inactive (par exemple aucune alerte de non-récupération tant que l'heure de fin n'est pas saisie).
 */
final class NurserySettings
{
    public const PICKUP_DAY_END = 'nursery.pickup.day_end_time';
    public const PICKUP_LATE_AFTER = 'nursery.pickup.late_after_minutes';
    public const PICKUP_DIRECTOR_AFTER = 'nursery.pickup.director_after_minutes';
    public const PICKUP_OFFLINE_MAX_AGE = 'nursery.pickup.offline_max_age_days';
    public const PICKUP_OFFLINE_READ = 'nursery.pickup.offline_read_days';
    public const PICKUP_CODE_ATTEMPTS = 'nursery.pickup.code_max_attempts';
    public const PICKUP_PARENT_VALIDATION = 'nursery.pickup.parent_entries_need_validation';
    public const DEV_LEVELS = 'nursery.dev.levels';
    public const DEV_MIN_OBSERVATIONS = 'nursery.dev.min_observations_per_domain_per_month';
    public const DEV_DISCOURAGED_TERMS = 'nursery.dev.discouraged_terms';
    public const ENROL_MIN_AGE_MONTHS = 'nursery.enrol.min_age_months';
    public const PROGRESS_BLOCK_ON_DEBT = 'nursery.progress_book.block_on_debt';

    /** Clés connues et leur type (`time` HH:MM, `int`, `bool`, `list`). */
    public const KEYS = [
        self::PICKUP_DAY_END => 'time',
        self::PICKUP_LATE_AFTER => 'int',
        self::PICKUP_DIRECTOR_AFTER => 'int',
        self::PICKUP_OFFLINE_MAX_AGE => 'int',
        self::PICKUP_OFFLINE_READ => 'int',
        self::PICKUP_CODE_ATTEMPTS => 'int',
        self::PICKUP_PARENT_VALIDATION => 'bool',
        self::DEV_LEVELS => 'list',
        self::DEV_MIN_OBSERVATIONS => 'int',
        self::DEV_DISCOURAGED_TERMS => 'list',
        self::ENROL_MIN_AGE_MONTHS => 'int',
        self::PROGRESS_BLOCK_ON_DEBT => 'bool',
    ];

    public function __construct(private readonly SettingsReader $reader)
    {
    }

    public function get(string $tenantId, string $establishmentId, string $key): mixed
    {
        $type = self::KEYS[$key] ?? throw new \InvalidArgumentException('Paramètre inconnu : ' . $key);
        $value = $this->reader->value($tenantId, $establishmentId, $key);

        return match ($type) {
            'int' => is_int($value) || (is_string($value) && preg_match('/^\d{1,9}$/', $value) === 1) ? (int) $value : null,
            'bool' => is_bool($value) ? $value : null,
            'time' => is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : null,
            default => is_array($value) ? array_values(array_filter($value, 'is_string')) : null,
        };
    }

    public function int(string $tenantId, string $establishmentId, string $key): ?int
    {
        $value = $this->get($tenantId, $establishmentId, $key);

        return is_int($value) ? $value : null;
    }

    public function bool(string $tenantId, string $establishmentId, string $key): bool
    {
        return $this->get($tenantId, $establishmentId, $key) === true;
    }

    public function time(string $tenantId, string $establishmentId, string $key): ?string
    {
        $value = $this->get($tenantId, $establishmentId, $key);

        return is_string($value) ? $value : null;
    }

    /** @return list<string> */
    public function list(string $tenantId, string $establishmentId, string $key): array
    {
        $value = $this->get($tenantId, $establishmentId, $key);

        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }

    /** @return array<string, mixed> clé => valeur effective (null si non posée) */
    public function all(string $tenantId, string $establishmentId): array
    {
        $values = [];
        foreach (array_keys(self::KEYS) as $key) {
            $values[$key] = $this->get($tenantId, $establishmentId, $key);
        }

        return $values;
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb;

/**
 * Réglages de la plateforme modifiables par le super admin depuis le back-office (table `platform_settings`, clé => valeur
 * JSON). Chaque réglage a une valeur par défaut et des bornes : une valeur absente ou hors bornes rend la valeur par défaut.
 */
final class PlatformSettings
{
    /** Durée de validité, en minutes, d'un code d'accès créé depuis le back-office (5 minutes à 7 jours). */
    public const ACCESS_CODE_TTL = 'access_code_ttl_minutes';

    /** Niveau de blocage par défaut d'un impayé (mode régularisation) : 0 = lecture et export seulement, 1 = tout bloqué sauf la régularisation. */
    public const REGULARISATION_LEVEL = 'regularisation_default_level';

    /** @var array<string, array{default: int, min: int, max: int}> */
    private const DEFINITIONS = [
        self::ACCESS_CODE_TTL => ['default' => 1440, 'min' => 5, 'max' => 10080],
        self::REGULARISATION_LEVEL => ['default' => 0, 'min' => 0, 'max' => 1],
    ];

    public function __construct(
        private readonly PlatformDb $db,
        private readonly PlatformAudit $audit,
        private readonly PlatformClock $clock,
    ) {
    }

    /** @return array<string, array{value: int, default: int, min: int, max: int, updated_at: ?string}> */
    public function all(): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $row = $this->db->one('SELECT setting_value, updated_at FROM platform_settings WHERE setting_key = ?', [$key]);
            $out[$key] = ['value' => $this->value($key), 'updated_at' => $row === null ? null : (string) $row['updated_at']] + $definition;
        }

        return $out;
    }

    public function value(string $key): int
    {
        $definition = self::DEFINITIONS[$key] ?? throw new \InvalidArgumentException('Réglage inconnu : ' . $key);
        $raw = $this->db->scalar('SELECT setting_value FROM platform_settings WHERE setting_key = ?', [$key]);
        $value = is_numeric($raw) ? (int) $raw : $definition['default'];

        return $value < $definition['min'] || $value > $definition['max'] ? $definition['default'] : $value;
    }

    /** @return array<string, array{value: int, default: int, min: int, max: int, updated_at: ?string}> */
    public function set(string $key, int $value, string $actorId, ?string $ip): array
    {
        $definition = self::DEFINITIONS[$key] ?? throw HttpException::validation([$key => ['Réglage inconnu.']]);
        if ($value < $definition['min'] || $value > $definition['max']) {
            throw HttpException::validation([$key => [sprintf('Valeur attendue entre %d et %d.', $definition['min'], $definition['max'])]]);
        }
        $before = $this->value($key);
        $now = $this->clock->iso();
        if ($this->db->update('platform_settings', ['setting_value' => (string) $value, 'updated_by' => $actorId, 'updated_at' => $now], ['setting_key' => $key]) === 0) {
            $this->db->insert('platform_settings', ['setting_key' => $key, 'setting_value' => (string) $value, 'updated_by' => $actorId, 'updated_at' => $now]);
        }
        $this->audit->record(null, $actorId, 'platform.setting_changed', 'setting', $key, ['before' => $before, 'after' => $value], $ip);

        return $this->all();
    }
}

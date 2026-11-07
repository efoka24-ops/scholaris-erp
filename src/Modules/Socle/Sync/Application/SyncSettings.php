<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Application;

use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/**
 * Paramètres de la synchronisation (clés `sync.batch.*`), lus avec leur héritage plateforme, client, établissement.
 * Toutes les valeurs par défaut sont « à confirmer » : 50 éléments et 500 lignes reprennent ce qu'accepte déjà l'appel
 * hors ligne (`POST /attendance-sessions/sync` : 50 séances de 500 lignes au plus) ; les tailles sont des plafonds prudents.
 * Une valeur absente, non numérique ou non positive donne le défaut ; une valeur trop grande est ramenée au plafond absolu.
 */
final class SyncSettings
{
    public const DEFAULT_MAX_ITEMS = 50;
    public const DEFAULT_MAX_ROWS_PER_ITEM = 500;
    public const DEFAULT_MAX_COMPRESSED_BYTES = 262_144;
    public const DEFAULT_MAX_UNCOMPRESSED_BYTES = 2_097_152;
    public const DEFAULT_INLINE_MAX_ITEMS = 25;

    private const CEILING_ITEMS = 500;
    private const CEILING_ROWS = 5000;
    private const CEILING_COMPRESSED_BYTES = 4_194_304;
    private const CEILING_UNCOMPRESSED_BYTES = 16_777_216;

    public function __construct(private readonly SettingsReader $settings)
    {
    }

    /** Éléments par lot (`sync.batch.max_items`). */
    public function maxItems(string $tenantId, string $establishmentId): int
    {
        return $this->bounded($tenantId, $establishmentId, 'sync.batch.max_items', self::DEFAULT_MAX_ITEMS, self::CEILING_ITEMS);
    }

    /** Lignes (entrées des listes de premier niveau de la charge utile) par élément (`sync.batch.max_rows_per_item`). */
    public function maxRowsPerItem(string $tenantId, string $establishmentId): int
    {
        return $this->bounded($tenantId, $establishmentId, 'sync.batch.max_rows_per_item', self::DEFAULT_MAX_ROWS_PER_ITEM, self::CEILING_ROWS);
    }

    /** Taille maximale du corps compressé reçu (`sync.batch.max_compressed_bytes`). */
    public function maxCompressedBytes(string $tenantId, string $establishmentId): int
    {
        return $this->bounded($tenantId, $establishmentId, 'sync.batch.max_compressed_bytes', self::DEFAULT_MAX_COMPRESSED_BYTES, self::CEILING_COMPRESSED_BYTES);
    }

    /** Taille maximale du JSON décompressé, contre les bombes de décompression (`sync.batch.max_uncompressed_bytes`). */
    public function maxUncompressedBytes(string $tenantId, string $establishmentId): int
    {
        return $this->bounded($tenantId, $establishmentId, 'sync.batch.max_uncompressed_bytes', self::DEFAULT_MAX_UNCOMPRESSED_BYTES, self::CEILING_UNCOMPRESSED_BYTES);
    }

    /** Nombre d'éléments jusqu'auquel un lot est traité pendant la requête ; au-delà il est mis en file pour `sync:process`. */
    public function inlineMaxItems(string $tenantId, string $establishmentId): int
    {
        return $this->bounded($tenantId, $establishmentId, 'sync.batch.inline_max_items', self::DEFAULT_INLINE_MAX_ITEMS, self::CEILING_ITEMS);
    }

    private function bounded(string $tenantId, string $establishmentId, string $key, int $default, int $ceiling): int
    {
        $value = $this->settings->value($tenantId, $establishmentId, $key, $default);
        if (!is_numeric($value) || (int) $value < 1) {
            return $default;
        }

        return min((int) $value, $ceiling);
    }
}

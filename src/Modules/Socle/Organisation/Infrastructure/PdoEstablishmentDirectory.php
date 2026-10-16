<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Infrastructure;

use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentInfo;

/** Annuaire des établissements : toute lecture est bornée au client demandé (jamais d'établissement d'un autre client). */
final class PdoEstablishmentDirectory implements EstablishmentDirectory
{
    public function __construct(private readonly Repositories $repositories)
    {
    }

    public function forTenant(string $tenantId): array
    {
        $rows = array_values(array_filter($this->repositories->explicit('establishments', $tenantId)->all(), self::open(...)));
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return array_map(EstablishmentMapper::toInfo(...), $rows);
    }

    public function findMany(string $tenantId, array $ids): array
    {
        $found = [];
        foreach (array_unique($ids) as $id) {
            $info = $this->find($tenantId, $id);
            if ($info !== null) {
                $found[] = $info;
            }
        }

        return $found;
    }

    public function find(string $tenantId, string $establishmentId): ?EstablishmentInfo
    {
        $row = $this->repositories->explicit('establishments', $tenantId)->find($establishmentId);

        return $row === null || !self::open($row) ? null : EstablishmentMapper::toInfo($row);
    }

    /**
     * Un établissement fermé par la plateforme n'est plus proposé (ses données restent).
     *
     * @param array<string, mixed> $row
     */
    private static function open(array $row): bool
    {
        return ($row['closed_at'] ?? null) === null;
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Application;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Staff\Infrastructure\PdoStore;

/**
 * Taux horaire de vacation (XAF par heure d'équivalent TD) et plafond mensuel, par catégorie d'enseignant (grade) et par
 * établissement, éventuellement précisé par niveau d'enseignement (D15). Un taux sans niveau vaut pour tous les niveaux ;
 * un taux de niveau l'emporte sur lui.
 */
final class RateService
{
    public function __construct(private readonly PdoStore $store, private readonly StaffClock $clock)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(string $tenantId, string $establishmentId): array
    {
        $rows = $this->store->where('vacation_rates', $tenantId, ['establishment_id' => $establishmentId], 'category');
        usort($rows, static fn (array $a, array $b): int => [(string) $a['category'], (string) $a['level']] <=> [(string) $b['category'], (string) $b['level']]);

        return array_map(self::out(...), $rows);
    }

    /**
     * Taux d'une catégorie. Sans niveau : le taux tous niveaux. Avec niveau : le taux de ce niveau, sinon (si $fallback) le
     * taux tous niveaux.
     *
     * @return array<string, mixed>|null
     */
    public function forCategory(string $tenantId, string $establishmentId, ?string $category, ?string $level = null, bool $fallback = false): ?array
    {
        if ($category === null) {
            return null;
        }
        $level = self::level($level);
        $row = $this->store->where('vacation_rates', $tenantId, ['establishment_id' => $establishmentId, 'category' => $category, 'level' => $level], 'id', 1)[0] ?? null;
        if ($row === null && $fallback && $level !== '') {
            return $this->forCategory($tenantId, $establishmentId, $category);
        }

        return $row === null ? null : self::out($row);
    }

    /**
     * @param array<string, mixed> $data category, level, hourly_rate, monthly_cap_minutes
     * @return array<string, mixed>
     */
    public function put(string $tenantId, string $establishmentId, array $data): array
    {
        $level = isset($data['level']) ? (string) $data['level'] : null;
        $existing = $this->forCategory($tenantId, $establishmentId, (string) $data['category'], $level);
        $values = [
            'hourly_rate' => $data['hourly_rate'],
            'monthly_cap_minutes' => $data['monthly_cap_minutes'] ?? null,
            'updated_at' => $this->clock->now(),
        ];
        if ($existing !== null) {
            $this->store->update('vacation_rates', $tenantId, (string) $existing['id'], $values);

            return $this->forCategory($tenantId, $establishmentId, (string) $data['category'], $level) ?? [];
        }
        $this->store->insert('vacation_rates', [
            'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'category' => $data['category'],
            'level' => self::level($level),
        ] + $values);

        return $this->forCategory($tenantId, $establishmentId, (string) $data['category'], $level) ?? [];
    }

    /**
     * Taux applicable : celui du contrat s'il en fixe un, sinon celui de la catégorie de l'enseignant pour le niveau
     * (à défaut, tous niveaux).
     *
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $staff
     */
    public function hourlyRate(string $tenantId, string $establishmentId, array $contract, array $staff, ?string $level = null): ?int
    {
        if ($contract['hourly_rate'] !== null) {
            return (int) $contract['hourly_rate'];
        }
        $rate = $this->forCategory($tenantId, $establishmentId, $staff['category'] === null ? null : (string) $staff['category'], $level, true);

        return $rate === null ? null : (int) $rate['hourly_rate'];
    }

    private static function level(?string $level): string
    {
        return $level === null ? '' : trim($level);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function out(array $row): array
    {
        $row['level'] = isset($row['level']) && $row['level'] !== '' ? (string) $row['level'] : null;

        return $row;
    }
}

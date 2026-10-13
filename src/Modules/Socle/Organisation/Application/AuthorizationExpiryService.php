<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Application;

use DateTimeImmutable;
use Scholaris\Modules\Socle\Organisation\Infrastructure\Repositories;

/**
 * Alerte d'expiration des autorisations administratives (filières, structures) : celles qui arrivent à
 * échéance dans les prochains jours (30 par défaut), pour le tableau de bord de la direction.
 */
final class AuthorizationExpiryService
{
    public const DEFAULT_DAYS = 30;

    public function __construct(
        private readonly Repositories $repositories,
        private readonly OrgClock $clock,
    ) {
    }

    /**
     * @return list<array{id: string, structure_id: string, structure_name: ?string, reference: string, valid_to: string, days_left: int}>
     */
    public function expiring(string $tenantId, string $establishmentId, int $days = self::DEFAULT_DAYS): array
    {
        $today = new DateTimeImmutable($this->clock->today(), $this->clock->timezone());
        $limit = $today->modify('+' . $days . ' days')->format('Y-m-d');

        $structures = [];
        foreach ($this->repositories->explicit('pedagogical_structures', $tenantId, $establishmentId)->all() as $structure) {
            $structures[(string) $structure['id']] = (string) $structure['name'];
        }

        $alerts = [];
        foreach ($this->repositories->explicit('authorizations', $tenantId, $establishmentId)->all() as $row) {
            $validTo = (string) $row['valid_to'];
            if ($validTo < $today->format('Y-m-d') || $validTo > $limit) {
                continue;
            }
            $alerts[] = [
                'id' => (string) $row['id'],
                'structure_id' => (string) $row['structure_id'],
                'structure_name' => $structures[(string) $row['structure_id']] ?? null,
                'reference' => (string) $row['reference'],
                'valid_to' => $validTo,
                'days_left' => (int) $today->diff(new DateTimeImmutable($validTo, $this->clock->timezone()))->days,
            ];
        }
        usort($alerts, static fn (array $a, array $b): int => [$a['valid_to'], $a['id']] <=> [$b['valid_to'], $b['id']]);

        return $alerts;
    }
}

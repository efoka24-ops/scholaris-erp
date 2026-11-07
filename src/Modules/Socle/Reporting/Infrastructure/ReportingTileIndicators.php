<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Infrastructure;

use Scholaris\Modules\Socle\Identity\Contracts\TileIndicators;
use Scholaris\Modules\Socle\Reporting\Application\IndicatorService;
use Scholaris\Modules\Socle\Reporting\Domain\DashboardKind;
use Throwable;

/**
 * Deux indicateurs par tuile, tirés du tableau de bord qui correspond au rôle : direction (directeur, direction de
 * groupe, super administrateur) ou censeur. Un indicateur indisponible est omis, jamais inventé.
 */
final class ReportingTileIndicators implements TileIndicators
{
    private const DIRECTION_ROLES = ['directeur', 'direction_groupe', 'super_admin'];
    private const PREFERRED = [
        'direction' => ['people.enrolled', 'billing.outstanding_xaf'],
        'censeur' => ['people.enrolled', 'attendance.absent_today'],
    ];

    public function __construct(private readonly IndicatorService $indicators)
    {
    }

    public function forTile(string $tenantId, string $establishmentId, array $roleCodes): array
    {
        $kind = match (true) {
            array_intersect($roleCodes, self::DIRECTION_ROLES) !== [] => DashboardKind::Direction,
            in_array('censeur', $roleCodes, true) => DashboardKind::Censeur,
            default => null,
        };
        if ($kind === null) {
            return [];
        }

        try {
            $board = $this->indicators->build($kind, $tenantId, $establishmentId);
        } catch (Throwable) {
            return [];
        }
        $byKey = [];
        foreach ((array) ($board['indicators'] ?? []) as $indicator) {
            if (is_array($indicator) && ($indicator['available'] ?? false) === true && isset($indicator['key'])) {
                $byKey[(string) $indicator['key']] = $indicator;
            }
        }

        $tiles = [];
        foreach (self::PREFERRED[$kind->value] as $key) {
            if (isset($byKey[$key]) && (is_int($byKey[$key]['value'] ?? null) || is_float($byKey[$key]['value'] ?? null))) {
                $tiles[] = [
                    'key' => $key,
                    'label' => (string) ($byKey[$key]['label'] ?? $key),
                    'value' => $byKey[$key]['value'],
                    'unit' => (string) ($byKey[$key]['unit'] ?? 'count'),
                ];
            }
        }

        return $tiles;
    }
}

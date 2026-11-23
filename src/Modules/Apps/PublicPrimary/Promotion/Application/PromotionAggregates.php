<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Promotion\Application;

use DateTimeImmutable;
use Scholaris\Modules\Apps\PublicPrimary\Promotion\Infrastructure\PdoPromotion;
use Scholaris\Modules\Socle\Reporting\Contracts\TerritoryAggregateProvider;

/**
 * Indicateur territorial des décisions de passage (CDC, Indicateurs : taux de redoublement ; EX-PAS-04, EX-STA-06), en effectifs :
 * cases `level=<niveau>;decision=<décision>` des décisions définitives de l'année scolaire de la date de référence (rentrée en septembre).
 */
final class PromotionAggregates implements TerritoryAggregateProvider
{
    public function __construct(private readonly PdoPromotion $repository)
    {
    }

    public function indicator(): string
    {
        return 'public_primary.promotion_decisions';
    }

    public function countsFor(string $tenantId, string $establishmentId, string $referenceDate): array
    {
        $counts = [];
        foreach ($this->repository->finalCounts($tenantId, $establishmentId, self::yearOf($referenceDate)) as $key => $count) {
            [$level, $decision] = explode('|', $key, 2);
            $counts['level=' . $level . ';decision=' . $decision] = $count;
        }
        ksort($counts);

        return $counts;
    }

    /** Année scolaire d'une date : la rentrée a lieu en septembre (calendrier national, l'année change au 1er septembre). */
    public static function yearOf(string $date): string
    {
        $day = new DateTimeImmutable(substr($date, 0, 10));
        $year = (int) $day->format('Y');
        $start = (int) $day->format('n') >= 9 ? $year : $year - 1;

        return $start . '-' . ($start + 1);
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application;

use Scholaris\Modules\Apps\PublicPrimary\Contracts\Referential;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Domain\MentionRules;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Infrastructure\PdoEvaluation;
use Scholaris\Modules\Socle\Reporting\Contracts\TerritoryAggregateProvider;

/**
 * Indicateur territorial « part de A par discipline » (CDC, Indicateurs : élèves avec A ÷ élèves évalués), en effectifs seulement
 * (aucun nom) : cases `discipline=<code>;mention=<code>` sur les mentions d'unité calculées de l'école ; « non évalué » exclu.
 * Le masquage des petites cases est fait par le socle (seuil `reporting.disclosure.minimum_cell_size`).
 */
final class MentionAggregates implements TerritoryAggregateProvider
{
    public function __construct(private readonly PdoEvaluation $repository, private readonly Referential $referential)
    {
    }

    public function indicator(): string
    {
        return 'public_primary.unit_mentions';
    }

    public function countsFor(string $tenantId, string $establishmentId, string $referenceDate): array
    {
        $codes = [];
        $counts = [];
        foreach ($this->repository->mentionCounts($tenantId, $establishmentId, 'unit') as $key => $count) {
            [$frameId, $mention] = explode('|', $key, 2);
            if ($mention === MentionRules::NOT_ASSESSED) {
                continue;
            }
            $codes[$frameId] ??= $this->referential->frame($tenantId, $frameId)->disciplineCode ?? 'unknown';
            $cell = 'discipline=' . $codes[$frameId] . ';mention=' . $mention;
            $counts[$cell] = ($counts[$cell] ?? 0) + $count;
        }
        ksort($counts);

        return $counts;
    }
}

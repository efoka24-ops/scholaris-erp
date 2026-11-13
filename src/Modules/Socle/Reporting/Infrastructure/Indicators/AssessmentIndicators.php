<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Infrastructure\Indicators;

use Scholaris\Modules\Socle\Assessment\Contracts\LatestPublishedAverages;
use Scholaris\Modules\Socle\Assessment\Contracts\ResultsDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\Reporting\Contracts\Indicator;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorProvider;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorScope;

/**
 * Moyenne des derniers résultats PUBLIÉS des inscrits de l'établissement (jamais de note non publiée). Lecture groupée
 * par LatestPublishedAverages quand le fournisseur l'expose (cas d'Assessment), sinon une lecture par apprenant.
 */
final class AssessmentIndicators implements IndicatorProvider
{
    public function __construct(private readonly ResultsDirectory $results, private readonly EnrollmentDirectory $enrollments)
    {
    }

    public function module(): string
    {
        return 'assessment';
    }

    public function indicators(IndicatorScope $scope): array
    {
        $learners = array_unique($this->enrollments->personIdsInEstablishment($scope->tenantId, $scope->establishmentId));
        $averages = [];
        if ($this->results instanceof LatestPublishedAverages) {
            $latest = $this->results->latestAveragesIn($scope->tenantId, $scope->establishmentId);
            foreach ($learners as $learnerId) {
                if (isset($latest[$learnerId])) {
                    $averages[] = $latest[$learnerId];
                }
            }
        } else {
            foreach ($learners as $learnerId) {
                foreach ($this->results->publishedFor($scope->tenantId, $learnerId) as $result) {
                    if ($result->establishmentId === $scope->establishmentId && $result->average !== null) {
                        $averages[] = $result->average;
                        break; // le plus récent seulement
                    }
                }
            }
        }
        if ($averages === []) {
            return [Indicator::unavailable('assessment.published_average', 'Moyenne des résultats publiés', 'Aucun résultat publié.', 'score')];
        }

        return [Indicator::of('assessment.published_average', 'Moyenne des résultats publiés', round(array_sum($averages) / count($averages), 2), 'score', ['learners' => count($averages)])];
    }
}

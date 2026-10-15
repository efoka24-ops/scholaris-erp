<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Infrastructure;

use Scholaris\Modules\Socle\Assessment\Contracts\LatestPublishedAverages;
use Scholaris\Modules\Socle\Assessment\Contracts\PublishedResult;
use Scholaris\Modules\Socle\Assessment\Contracts\ResultsDirectory;

/** Résultats publiés d'une personne pour les portails et documents : jamais de résultat non publié. */
final class PdoResultsDirectory implements ResultsDirectory, LatestPublishedAverages
{
    public function __construct(private readonly ResultRepository $results)
    {
    }

    public function latestAveragesIn(string $tenantId, string $establishmentId): array
    {
        return $this->results->latestAveragesIn($tenantId, $establishmentId);
    }

    public function publishedFor(string $tenantId, string $personId): array
    {
        return array_map(static function (array $row): PublishedResult {
            $details = json_decode((string) ($row['details'] ?? '[]'), true);

            return new PublishedResult(
                (string) $row['id'],
                (string) $row['person_id'],
                (string) $row['establishment_id'],
                (string) $row['period_id'],
                (string) $row['period_label'],
                $row['average'] === null ? null : (float) $row['average'],
                $row['rank_in_class'] === null ? null : (int) $row['rank_in_class'],
                $row['class_size'] === null ? null : (int) $row['class_size'],
                $row['decision'] === null ? null : (string) $row['decision'],
                $row['credits_earned'] === null ? null : (int) $row['credits_earned'],
                $row['credits_total'] === null ? null : (int) $row['credits_total'],
                $row['mention'] === null ? null : (string) $row['mention'],
                is_array($details) ? array_values(array_filter($details, 'is_array')) : [],
                (string) ($row['exam_session'] ?? 'normal'),
            );
        }, $this->results->publishedFor($tenantId, $personId));
    }
}

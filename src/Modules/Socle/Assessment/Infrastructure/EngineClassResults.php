<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Infrastructure;

use Scholaris\Modules\Socle\Assessment\Application\ResultService;
use Scholaris\Modules\Socle\Assessment\Contracts\ClassResults;

/**
 * Contrat ClassResults : délègue à ResultService::compute (mêmes règles, même stratégie, même agrégat de période parente que
 * GET /results) puis ne rend que les chiffres utiles à un bulletin. Le détail par matière est une liste (calcul direct) ou une table
 * « matière => {average} » (agrégat de périodes) : les deux formes sont lues.
 */
final class EngineClassResults implements ClassResults
{
    public function __construct(private readonly ResultService $results)
    {
    }

    public function compute(string $tenantId, string $establishmentId, string $structureId, string $periodId, ?string $periodLabel = null): array
    {
        $computed = $this->results->compute($tenantId, $establishmentId, $structureId, $periodId, $periodLabel);
        $out = [];
        foreach ($computed['rows'] as $row) {
            $details = json_decode((string) ($row['details'] ?? '[]'), true);
            $subjects = [];
            foreach (is_array($details) ? $details : [] as $key => $detail) {
                if (!is_array($detail)) {
                    continue;
                }
                $subjectId = isset($detail['subject_id']) ? (string) $detail['subject_id'] : (string) $key;
                $average = $detail['average'] ?? null;
                $subjects[] = [
                    'subject_id' => $subjectId,
                    'code' => isset($detail['code']) ? (string) $detail['code'] : null,
                    'name' => isset($detail['name']) ? (string) $detail['name'] : null,
                    'average' => is_int($average) || is_float($average) || is_numeric($average) ? (float) $average : null,
                ];
            }
            $out[] = [
                'person_id' => (string) $row['person_id'],
                'average' => $row['average'] === null ? null : (float) $row['average'],
                'rank' => $row['rank_in_class'] === null ? null : (int) $row['rank_in_class'],
                'class_size' => (int) ($row['class_size'] ?? 0),
                'mention' => $row['mention'] === null ? null : (string) $row['mention'],
                'rule_version' => isset($row['rule_version']) ? (int) $row['rule_version'] : null,
                'subjects' => $subjects,
            ];
        }

        return $out;
    }
}

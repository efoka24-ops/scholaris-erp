<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Infrastructure;

use Scholaris\Modules\Socle\Assessment\Contracts\CouncilDirectory;

/** Contrat CouncilDirectory : lectures de ResultRepository, JSON décodés, aucune écriture. */
final class PdoCouncilDirectory implements CouncilDirectory
{
    public function __construct(private readonly ResultRepository $repo)
    {
    }

    public function find(string $tenantId, string $establishmentId, string $councilId): ?array
    {
        $council = $this->repo->council($tenantId, $establishmentId, $councilId);
        if ($council === null) {
            return null;
        }
        $lines = [];
        foreach ($this->repo->councilLines($tenantId, $councilId) as $line) {
            $lines[] = [
                'person_id' => (string) $line['person_id'],
                'average' => $line['average'] === null ? null : (float) $line['average'],
                'rank' => $line['rank_in_class'] === null ? null : (int) $line['rank_in_class'],
                'unjustified_absences' => (int) $line['unjustified_absences'],
                'conduct' => self::text($line['conduct'] ?? null),
                'proposal' => self::text($line['proposal'] ?? null),
                'decision' => self::text($line['decision'] ?? null),
                'reason' => self::text($line['reason'] ?? null),
                'appraisal' => self::text($line['appraisal'] ?? null),
                'extras' => self::map($line['line_extras'] ?? null),
            ];
        }

        return [
            'id' => (string) $council['id'], 'structure_id' => (string) $council['structure_id'], 'period_id' => (string) $council['period_id'],
            'period_label' => (string) $council['period_label'], 'status' => (string) $council['status'], 'held_on' => self::text($council['held_on'] ?? null),
            'rule_version' => (int) $council['rule_version'], 'lines' => $lines,
        ];
    }

    public function storedResults(string $tenantId, string $establishmentId, string $structureId, string $periodId): array
    {
        $out = [];
        foreach ($this->repo->resultsOf($tenantId, $establishmentId, $structureId, $periodId) as $row) {
            $details = [];
            // Calcul direct : liste de matières ; agrégat de périodes : table « matière => détail ».
            $decoded = is_string($row['details'] ?? null) ? json_decode((string) $row['details'], true) : null;
            foreach (is_array($decoded) ? $decoded : [] as $key => $detail) {
                if (is_array($detail)) {
                    $details[] = $detail + ['subject_id' => (string) $key];
                }
            }
            $out[(string) $row['person_id']] = [
                'person_id' => (string) $row['person_id'],
                'average' => $row['average'] === null ? null : (float) $row['average'],
                'rank' => $row['rank_in_class'] === null ? null : (int) $row['rank_in_class'],
                'class_size' => (int) ($row['class_size'] ?? 0),
                'mention' => self::text($row['mention'] ?? null),
                'proposal' => self::text($row['proposal'] ?? null),
                'decision' => self::text($row['decision'] ?? null),
                'details' => $details,
                'extras' => self::map($row['computed_extras'] ?? null),
            ];
        }

        return $out;
    }

    private static function text(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    /** @return array<string, mixed> clés textuelles d'un objet JSON */
    private static function map(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $out = [];
        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}

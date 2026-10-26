<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application;

use Scholaris\Modules\Apps\PublicPrimary\Contracts\AnnualMentions;
use Scholaris\Modules\Apps\PublicPrimary\Contracts\FrameInfo;
use Scholaris\Modules\Apps\PublicPrimary\Contracts\Referential;
use Scholaris\Modules\Apps\PublicPrimary\Contracts\UnitInfo;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Domain\MentionRules;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Infrastructure\PdoEvaluation;
use Scholaris\Modules\Apps\PublicPrimary\Support\Rules;

/**
 * Mentions trimestrielles et annuelles (EX-EVA-04) déduites des mentions d'unité selon les paramètres :
 *  - `trimester_mention` = `last_unit` : mention de la dernière unité évaluée du trimestre ;
 *  - `annual_mention` = `final_integration_else_last_unit` : mention de l'unité d'intégration de fin d'année, à défaut la dernière unité
 *    évaluée (`last_unit` : la dernière unité évaluée seulement).
 * Une mention modifiée par le directeur (portée trimestre ou année, avec motif) prime sur le calcul (RG-EVA-3).
 */
final class MentionReader implements AnnualMentions
{
    public function __construct(
        private readonly PdoEvaluation $repository,
        private readonly Referential $referential,
        private readonly Rules $rules,
    ) {
    }

    public function annual(string $tenantId, string $establishmentId, string $personId, string $yearLabel, string $levelCode, string $subsystem): array
    {
        $units = $this->referential->unitsOf($tenantId, $yearLabel, $levelCode);
        $rule = $this->rules->string($tenantId, $establishmentId, 'public_primary.evaluation.annual_mention');
        $lines = [];
        foreach ($this->framesFor($tenantId, $subsystem, $levelCode, $units) as $frame) {
            $mention = $this->override($tenantId, $personId, $frame->id, 'year', $yearLabel)
                ?? $this->pick($tenantId, $personId, $frame->id, $units, $rule === 'final_integration_else_last_unit');
            $lines[] = ['frame_id' => $frame->id, 'discipline_code' => $frame->disciplineCode, 'label_fr' => $frame->labelFr, 'label_en' => $frame->labelEn, 'is_key' => $frame->isKeyDiscipline, 'mention' => $mention];
        }

        return $lines;
    }

    /**
     * @return list<array{frame_id: string, discipline_code: string, label_fr: string, label_en: string, is_key: bool, mention: string, units: list<array{unit_no: int, mention: string}>, perfection: bool}>
     */
    public function trimester(string $tenantId, string $establishmentId, string $personId, string $yearLabel, string $levelCode, string $subsystem, string $periodId): array
    {
        $units = array_values(array_filter($this->referential->unitsOf($tenantId, $yearLabel, $levelCode), static fn (UnitInfo $u): bool => $u->periodId === $periodId));
        $byUnit = $this->unitMentions($tenantId, $personId, $units);
        $lines = [];
        foreach ($this->framesFor($tenantId, $subsystem, $levelCode, $units) as $frame) {
            $unitLines = [];
            foreach ($units as $unit) {
                $unitLines[] = ['unit_no' => $unit->unitNo, 'mention' => $byUnit[$unit->id][$frame->id]['mention'] ?? MentionRules::NOT_ASSESSED];
            }
            $mention = $this->override($tenantId, $personId, $frame->id, 'trimester', $periodId) ?? $this->pick($tenantId, $personId, $frame->id, $units, false);
            $last = null;
            foreach (array_reverse($units) as $unit) {
                if (isset($byUnit[$unit->id][$frame->id]) && $byUnit[$unit->id][$frame->id]['mention'] !== MentionRules::NOT_ASSESSED) {
                    $last = $byUnit[$unit->id][$frame->id];
                    break;
                }
            }
            $lines[] = [
                'frame_id' => $frame->id, 'discipline_code' => $frame->disciplineCode, 'label_fr' => $frame->labelFr, 'label_en' => $frame->labelEn,
                'is_key' => $frame->isKeyDiscipline, 'mention' => $mention, 'units' => $unitLines, 'perfection' => $last !== null && $last['perfection'],
            ];
        }

        return $lines;
    }

    /**
     * @param list<UnitInfo> $units
     * @return list<FrameInfo>
     */
    private function framesFor(string $tenantId, string $subsystem, string $levelCode, array $units): array
    {
        $date = $units === [] ? '9999-12-31' : $units[count($units) - 1]->endsOn;

        return $this->referential->framesFor($tenantId, $subsystem, $levelCode, $date);
    }

    /** @param list<UnitInfo> $units */
    private function pick(string $tenantId, string $personId, string $frameId, array $units, bool $finalFirst): string
    {
        $byUnit = $this->unitMentions($tenantId, $personId, $units);
        if ($finalFirst) {
            foreach ($units as $unit) {
                $mention = $byUnit[$unit->id][$frameId]['mention'] ?? null;
                if ($unit->isFinalIntegration && $mention !== null && $mention !== MentionRules::NOT_ASSESSED) {
                    return $mention;
                }
            }
        }
        foreach (array_reverse($units) as $unit) {
            $mention = $byUnit[$unit->id][$frameId]['mention'] ?? null;
            if ($mention !== null && $mention !== MentionRules::NOT_ASSESSED) {
                return $mention;
            }
        }

        return MentionRules::NOT_ASSESSED;
    }

    /**
     * @param list<UnitInfo> $units
     * @return array<string, array<string, array{mention: string, perfection: bool}>>
     */
    private function unitMentions(string $tenantId, string $personId, array $units): array
    {
        $map = [];
        foreach ($this->repository->unitMentionsOf($tenantId, $personId, array_map(static fn (UnitInfo $u): string => $u->id, $units)) as $row) {
            $map[(string) $row['scope_ref']][(string) $row['frame_id']] = ['mention' => (string) $row['mention'], 'perfection' => (int) $row['perfection_mastered'] === 1];
        }

        return $map;
    }

    private function override(string $tenantId, string $personId, string $frameId, string $kind, string $ref): ?string
    {
        $row = $this->repository->mention($tenantId, $personId, $frameId, $kind, $ref);

        return $row !== null && $row['overridden_reason'] !== null ? (string) $row['mention'] : null;
    }
}

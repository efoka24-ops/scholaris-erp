<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application;

use Scholaris\Modules\Apps\PublicPrimary\Contracts\CriterionInfo;
use Scholaris\Modules\Apps\PublicPrimary\Contracts\FrameInfo;
use Scholaris\Modules\Apps\PublicPrimary\Contracts\UnitInfo;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassInfo;

/** Contexte d'une saisie : unité, classe, sous-système de l'école, disciplines du référentiel en vigueur et élèves de la classe. */
final class UnitContext
{
    /**
     * @param list<FrameInfo> $frames
     * @param list<string> $pupilIds
     */
    public function __construct(
        public readonly UnitInfo $unit,
        public readonly ClassInfo $class,
        public readonly string $subsystem,
        public readonly array $frames,
        public readonly array $pupilIds,
    ) {
    }

    /** @return array<string, array{frame: FrameInfo, criterion: CriterionInfo}> critère => discipline */
    public function criteria(): array
    {
        $map = [];
        foreach ($this->frames as $frame) {
            foreach ($frame->criteria as $criterion) {
                $map[$criterion->id] = ['frame' => $frame, 'criterion' => $criterion];
            }
        }

        return $map;
    }

    public function frame(string $frameId): ?FrameInfo
    {
        foreach ($this->frames as $frame) {
            if ($frame->id === $frameId) {
                return $frame;
            }
        }

        return null;
    }
}

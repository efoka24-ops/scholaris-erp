<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Contracts;

/** Discipline du référentiel national (EX-EVA-01) pour un sous-système et un niveau, dans une version datée, avec ses critères. */
final class FrameInfo
{
    /** @param list<CriterionInfo> $criteria triés par ordre d'affichage */
    public function __construct(
        public readonly string $id,
        public readonly string $subsystem,
        public readonly string $levelCode,
        public readonly string $disciplineCode,
        public readonly string $labelFr,
        public readonly string $labelEn,
        public readonly int $version,
        public readonly string $validFrom,
        /** discipline clé de la politique de passage (français/English, mathématiques, seconde langue officielle) */
        public readonly bool $isKeyDiscipline,
        public readonly array $criteria,
    ) {
    }

    public function label(string $locale): string
    {
        return $locale === 'en' ? $this->labelEn : $this->labelFr;
    }

    /** @return list<CriterionInfo> */
    public function minimalCriteria(): array
    {
        return array_values(array_filter($this->criteria, static fn (CriterionInfo $c): bool => $c->kind === CriterionInfo::MINIMAL));
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Curriculum\Contracts;

/** UE (ou matière BTS/HND) d'une version de maquette, avec ses EC (contrat pédagogique, inscription aux UE à choix). */
final class CurriculumUnitInfo
{
    /** @param list<array{id: string, code: string, title: string, credits: int, coefficient: float, catalog_element_id: ?string}> $elements */
    public function __construct(
        public readonly string $id,
        public readonly int $semesterOrdinal,
        public readonly string $code,
        public readonly string $title,
        /** fundamental, transversal, optional, internship ou project */
        public readonly string $unitType,
        public readonly int $credits,
        public readonly float $coefficient,
        public readonly ?string $choiceGroup,
        public readonly ?int $choiceCount,
        public readonly ?string $nationalExamPaper,
        /** matière du référentiel d'évaluation (Assessment, D9) une fois publiée */
        public readonly ?string $catalogSubjectId,
        public readonly array $elements,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Transition\Application;

use Scholaris\Modules\Apps\Nursery\Development\Application\ProgressBookService;
use Scholaris\Modules\Apps\Nursery\Sections\Application\SectionService;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureCheck;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureFinding;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/**
 * Contrôle préalable à la clôture d'année d'une maternelle privée (`organisation.closure.checks.nursery`, EX-MAT-37) : chaque enfant
 * inscrit a une décision de passage prise, et aucun carnet de l'année n'est resté en brouillon ou soumis. Sans objet (jamais bloquant)
 * pour un établissement qui n'est pas une maternelle privée ouverte.
 */
final class ClosureReadiness implements ClosureCheck
{
    public const KEY = 'nursery.transition';
    public const LABEL = 'Maternelle : décisions de passage et carnets';

    public function __construct(
        private readonly Store $store,
        private readonly SectionService $sections,
        private readonly EstablishmentDirectory $establishments,
        private readonly ApplicationAccess $applications,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function evaluate(string $tenantId, string $establishmentId, string $academicYearId): ClosureFinding
    {
        $establishment = $this->establishments->find($tenantId, $establishmentId);
        if ($establishment === null || $establishment->type !== 'nursery' || !$this->applications->allowsReads($tenantId, 'nursery')) {
            return ClosureFinding::of(self::KEY, self::LABEL, 0, 'Sans objet pour cet établissement.');
        }

        return ClosureFinding::of(self::KEY, self::LABEL, ...$this->count($tenantId, $establishmentId, $academicYearId));
    }

    /** @return array{0: int, 1: string} nombre d'éléments bloquants et détail lisible */
    public function count(string $tenantId, string $establishmentId, string $academicYearId): array
    {
        $decided = [];
        foreach ($this->store->select(TransitionService::TABLE, $tenantId, $establishmentId, ['academic_year_id' => $academicYearId], 'id', 10000) as $row) {
            if (in_array((string) $row['status'], ['decided', 'applied'], true)) {
                $decided[(string) $row['learner_id']] = true;
            }
        }
        $undecided = 0;
        foreach ($this->sections->inPerimeter(new Perimeter($tenantId, $establishmentId, true)) as $section) {
            foreach ($this->sections->childIds($tenantId, (string) $section['id']) as $childId) {
                if (!isset($decided[$childId])) {
                    ++$undecided;
                }
            }
        }
        $books = 0;
        foreach ($this->store->select(ProgressBookService::TABLE, $tenantId, $establishmentId, ['academic_year_id' => $academicYearId], 'id', 20000) as $book) {
            if (in_array((string) $book['status'], ['draft', 'submitted'], true)) {
                ++$books;
            }
        }

        return [$undecided + $books, sprintf('%d enfant(s) sans décision de passage, %d carnet(s) non validé(s).', $undecided, $books)];
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Discipline\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/** Élèves d'une classe ou de l'établissement (inscriptions actives, contrats People et Organisation), triés par nom. */
final class ClassRoster
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly OrgUnitTree $tree,
    ) {
    }

    /** La classe appartient à l'établissement, sinon 404. */
    public function assertClass(string $tenantId, string $establishmentId, string $classId): string
    {
        $structure = $this->tree->structure($tenantId, $establishmentId, $classId);
        if ($structure === null || $structure->establishmentId !== $establishmentId) {
            throw HttpException::notFound('Classe introuvable dans cet établissement.');
        }

        return $structure->name;
    }

    public function className(string $tenantId, string $establishmentId, ?string $classId): string
    {
        return $classId === null ? '' : ($this->tree->structure($tenantId, $establishmentId, $classId)->name ?? '');
    }

    /** @return list<array{person_id: string, first_name: string, last_name: string, class_id: string}> */
    public function ofClass(string $tenantId, string $establishmentId, string $classId): array
    {
        $this->assertClass($tenantId, $establishmentId, $classId);
        $ids = array_values(array_unique($this->enrollments->personIdsInStructure($tenantId, $classId)));

        return $this->describe($tenantId, array_fill_keys($ids, $classId));
    }

    /** @return list<array{person_id: string, first_name: string, last_name: string, class_id: string}> */
    public function ofEstablishment(string $tenantId, string $establishmentId): array
    {
        $classes = [];
        foreach (array_unique($this->enrollments->personIdsInEstablishment($tenantId, $establishmentId)) as $personId) {
            $classId = $this->classOf($tenantId, $establishmentId, $personId);
            if ($classId !== null) {
                $classes[$personId] = $classId;
            }
        }

        return $this->describe($tenantId, $classes);
    }

    /** Classe actuelle de l'élève dans l'établissement (inscription active ou sous condition), sinon null. */
    public function classOf(string $tenantId, string $establishmentId, string $personId): ?string
    {
        $classId = null;
        foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && $enrollment->structureId !== null
                && in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true)) {
                $classId = $enrollment->structureId;
            }
        }

        return $classId;
    }

    /** L'élève est inscrit dans cette classe de l'établissement, sinon 404. */
    public function assertInClass(string $tenantId, string $establishmentId, string $personId, string $classId): void
    {
        $this->assertClass($tenantId, $establishmentId, $classId);
        if (!in_array($personId, $this->enrollments->personIdsInStructure($tenantId, $classId), true)) {
            throw HttpException::notFound('Élève introuvable dans cette classe.');
        }
    }

    /**
     * @param array<string, string> $classes personne => classe
     * @return list<array{person_id: string, first_name: string, last_name: string, class_id: string}>
     */
    private function describe(string $tenantId, array $classes): array
    {
        if ($classes === []) {
            return [];
        }
        $rows = [];
        foreach ($this->people->findMany($tenantId, array_map('strval', array_keys($classes))) as $person) {
            $rows[] = ['person_id' => $person->id, 'first_name' => $person->firstName, 'last_name' => $person->lastName, 'class_id' => $classes[$person->id]];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['last_name'], $a['first_name']] <=> [$b['last_name'], $b['first_name']]);

        return $rows;
    }
}

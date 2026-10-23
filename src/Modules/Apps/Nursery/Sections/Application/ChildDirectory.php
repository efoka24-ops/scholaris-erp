<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Sections\Application;

use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Paging;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Socle\People\Contracts\BirthInfo;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;

/**
 * Enfants inscrits en section (EX-MAT-05, 06), vus à travers le périmètre de sections de l'appelant : hors périmètre ou hors
 * établissement, un enfant est introuvable (404). Aucune lecture directe de la table des personnes : annuaires du socle seulement.
 */
final class ChildDirectory
{
    public function __construct(
        private readonly SectionService $sections,
        private readonly PersonDirectory $people,
        private readonly BirthInfo $births,
        private readonly EnrollmentDirectory $enrollments,
        private readonly NurseryClock $clock,
    ) {
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public function page(Perimeter $perimeter, Paging $paging, ?string $sectionId = null): array
    {
        $labels = [];
        foreach ($this->sections->inPerimeter($perimeter) as $section) {
            if ($sectionId === null || $section['id'] === $sectionId) {
                $labels[(string) $section['id']] = (string) $section['label'];
            }
        }
        $bySection = [];
        foreach (array_keys($labels) as $id) {
            foreach ($this->sections->childIds($perimeter->tenantId, $id) as $childId) {
                $bySection[$childId] = $id;
            }
        }
        $children = $this->people->findMany($perimeter->tenantId, array_keys($bySection));
        usort($children, static fn (PersonSummary $a, PersonSummary $b): int => [mb_strtolower($a->lastName), mb_strtolower($a->firstName), $a->id] <=> [mb_strtolower($b->lastName), mb_strtolower($b->firstName), $b->id]);
        $rows = [];
        foreach ($children as $child) {
            $section = $bySection[$child->id];
            $rows[] = $this->resource($perimeter->tenantId, $child, $section, $labels[$section]);
        }

        return $paging->slice($rows);
    }

    /** @return array<string, mixed> 404 hors établissement ou hors périmètre de sections */
    public function get(Perimeter $perimeter, string $childId): array
    {
        $enrollment = $this->currentEnrollment($perimeter->tenantId, $perimeter->establishmentId, $childId);
        if ($enrollment === null || !$perimeter->coversSection($enrollment->structureId)) {
            throw HttpException::notFound('Enfant introuvable.');
        }
        $person = $this->people->find($perimeter->tenantId, $childId) ?? throw HttpException::notFound('Enfant introuvable.');
        $section = $this->sections->findRaw($perimeter->tenantId, $perimeter->establishmentId, (string) $enrollment->structureId);
        $resource = $this->resource($perimeter->tenantId, $person, (string) $enrollment->structureId, (string) $section['label']);
        $resource['enrollment_id'] = $enrollment->id;
        $resource['academic_year_id'] = $enrollment->academicYearId;
        $resource['guardians'] = array_map(
            static fn (PersonSummary $g): array => ['id' => $g->id, 'full_name' => $g->fullName(), 'phone' => $g->phone],
            $this->people->guarantorsOf($perimeter->tenantId, $childId),
        );

        return $resource;
    }

    /** L'inscription vivante de l'enfant dans cet établissement, ou null. */
    public function currentEnrollment(string $tenantId, string $establishmentId, string $childId): ?EnrollmentInfo
    {
        foreach ($this->enrollments->forPerson($tenantId, $childId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && $enrollment->structureId !== null
                && in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true)) {
                return $enrollment;
            }
        }

        return null;
    }

    /** Section de l'enfant dans cet établissement (null s'il n'y est pas inscrit). */
    public function sectionOf(string $tenantId, string $establishmentId, string $childId): ?string
    {
        return $this->currentEnrollment($tenantId, $establishmentId, $childId)?->structureId;
    }

    /** 404 si l'enfant n'est pas inscrit dans cet établissement ou si sa section est hors périmètre ; rend sa section. */
    public function assertInPerimeter(Perimeter $perimeter, string $childId): string
    {
        $section = $this->sectionOf($perimeter->tenantId, $perimeter->establishmentId, $childId);
        if ($section === null || !$perimeter->coversSection($section)) {
            throw HttpException::notFound('Enfant introuvable.');
        }

        return $section;
    }

    public function ageMonths(string $tenantId, string $childId): ?int
    {
        $birth = $this->births->birthDate($tenantId, $childId);
        if ($birth === null) {
            return null;
        }
        $diff = (new DateTimeImmutable($birth))->diff(new DateTimeImmutable($this->clock->today()));

        return $diff->invert === 1 ? 0 : $diff->y * 12 + $diff->m;
    }

    /** @return array<string, mixed> */
    private function resource(string $tenantId, PersonSummary $child, string $sectionId, string $sectionLabel): array
    {
        return [
            'id' => $child->id,
            'first_name' => $child->firstName,
            'last_name' => $child->lastName,
            'full_name' => $child->fullName(),
            'gender' => $child->gender,
            'birth_date' => $this->births->birthDate($tenantId, $child->id),
            'age_months' => $this->ageMonths($tenantId, $child->id),
            'section_id' => $sectionId,
            'section_label' => $sectionLabel,
        ];
    }
}

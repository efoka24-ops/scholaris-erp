<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\ClassLife\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Primary\Academic\Contracts\CompetencyDirectory;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\PrimaryClock;
use Scholaris\Modules\Apps\Primary\Support\ScaleProvider;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Portail parent du primaire (EX-PRI-39) : cahier de liaison publié et compétences PUBLIQUES (feuille verrouillée puis publiée) de
 * l'enfant d'un parent. Le parent n'accède qu'à ses enfants (garant déclaré) ; un enfant d'une autre famille, d'un autre
 * établissement ou d'un autre client répond 404. Les accusés de lecture sont uniques par parent et par entrée.
 */
final class FamilyService
{
    public const PERMISSION = ['self.read'];
    private const LIVE = [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL];

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly UserPersonLink $links,
        private readonly PersonDirectory $people,
        private readonly EnrollmentDirectory $enrollments,
        private readonly OrgUnitTree $tree,
        private readonly CompetencyDirectory $competencies,
        private readonly ScaleProvider $scales,
        private readonly PrimaryClock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> les enfants du parent inscrits dans cet établissement */
    public function children(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, self::PERMISSION);
        $guardian = $this->guardianId($principal);
        $children = [];
        foreach ($this->people->learnersOf($principal->tenantId, $guardian) as $child) {
            $enrollment = $this->liveEnrollment($principal->tenantId, $establishmentId, $child->id);
            if ($enrollment === null) {
                continue;
            }
            $class = $enrollment->structureId === null ? null : $this->tree->structure($principal->tenantId, $establishmentId, $enrollment->structureId);
            $children[] = ['person_id' => $child->id, 'name' => $child->fullName(), 'class' => $class === null ? null : ['id' => $class->id, 'name' => $class->name]];
        }

        return $children;
    }

    /** @return list<array<string, mixed>> */
    public function journal(Principal $principal, string $establishmentId, string $childId): array
    {
        [$guardian, $enrollment] = $this->resolve($principal, $establishmentId, $childId);

        return $this->journalOf($principal->tenantId, $establishmentId, (string) $enrollment->structureId, $guardian, 50);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function journalOf(string $tenantId, string $establishmentId, string $classId, string $guardianId, int $limit): array
    {
        $receipts = [];
        foreach ($this->store->select('pri_journal_receipts', $tenantId, $establishmentId, ['guardian_person_id' => $guardianId], 'entry_id', 20000) as $r) {
            $receipts[(string) $r['entry_id']] = (string) $r['seen_at'];
        }
        $rows = $this->store->select(JournalService::TABLE, $tenantId, $establishmentId, ['class_structure_id' => $classId, 'visible_to_parents' => 1], 'entry_date DESC, id', 500);
        $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['published_at'] !== null));

        return array_map(
            static fn (array $r): array => JournalService::resource($r) + ['seen_at' => $receipts[(string) $r['id']] ?? null],
            array_slice($rows, 0, $limit),
        );
    }

    /** @return array<string, mixed> */
    public function seen(Principal $principal, string $establishmentId, string $entryId): array
    {
        $this->access->require($principal, $establishmentId, self::PERMISSION);
        $t = $principal->tenantId;
        $entry = $this->store->find(JournalService::TABLE, $t, $establishmentId, $entryId);
        if ($entry === null || $entry['published_at'] === null || (int) $entry['visible_to_parents'] !== 1) {
            throw HttpException::notFound('Entrée introuvable.');
        }
        $guardian = $this->guardianId($principal);
        $inClass = false;
        foreach ($this->people->learnersOf($t, $guardian) as $child) {
            $enrollment = $this->liveEnrollment($t, $establishmentId, $child->id);
            if ($enrollment !== null && $enrollment->structureId === (string) $entry['class_structure_id']) {
                $inClass = true;
            }
        }
        if (!$inClass) {
            throw HttpException::notFound('Entrée introuvable.');
        }
        $existing = $this->store->select('pri_journal_receipts', $t, $establishmentId, ['entry_id' => $entryId, 'guardian_person_id' => $guardian], 'id', 1)[0] ?? null;
        if ($existing === null) {
            $now = $this->clock->now();
            $this->store->insert('pri_journal_receipts', $t, $establishmentId, [
                'id' => (string) new Ulid(), 'entry_id' => $entryId, 'guardian_person_id' => $guardian, 'seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $existing = ['seen_at' => $now];
        }

        return ['entry_id' => $entryId, 'seen_at' => (string) $existing['seen_at']];
    }

    /**
     * Compétences publiées de l'enfant : seulement les feuilles verrouillées ET publiées par la direction.
     *
     * @return array<string, mixed>
     */
    public function competencies(Principal $principal, string $establishmentId, string $childId, ?string $periodId): array
    {
        [, $enrollment] = $this->resolve($principal, $establishmentId, $childId);

        return $this->competenciesOf($principal->tenantId, $establishmentId, $childId, (string) $enrollment->structureId, $periodId);
    }

    /** @return array<string, mixed> */
    public function competenciesOf(string $tenantId, string $establishmentId, string $childId, string $classId, ?string $periodId): array
    {
        $where = ['person_id' => $childId, 'class_structure_id' => $classId, 'status' => 'published'];
        if ($periodId !== null) {
            $where['period_id'] = $periodId;
        }
        $rows = $this->store->select('pri_competency_marks', $tenantId, $establishmentId, $where, 'period_id, competency_id', 5000);
        $infos = $this->competencies->findMany($tenantId, $establishmentId, array_values(array_unique(array_map(static fn (array $r): string => (string) $r['competency_id'], $rows))));
        $scale = [];
        foreach ($this->scales->qualitative($tenantId, $establishmentId) as $level) {
            $scale[$level['code']] = $level;
        }
        $periods = [];
        foreach ($rows as $row) {
            $info = $infos[(string) $row['competency_id']] ?? null;
            $periods[(string) $row['period_id']][] = [
                'competency_id' => (string) $row['competency_id'], 'code' => $info === null ? '' : $info->code,
                'label_fr' => $info === null ? '' : $info->labelFr, 'label_en' => $info?->labelEn,
                'scale_code' => $row['scale_code'] === null ? null : (string) $row['scale_code'],
                'scale_label_fr' => $row['scale_code'] === null ? null : ($scale[(string) $row['scale_code']]['label_fr'] ?? (string) $row['scale_code']),
                'scale_label_en' => $row['scale_code'] === null ? null : ($scale[(string) $row['scale_code']]['label_en'] ?? (string) $row['scale_code']),
            ];
        }

        return ['child_id' => $childId, 'periods' => array_map(static fn (string $id, array $items): array => ['period_id' => $id, 'items' => $items], array_keys($periods), array_values($periods))];
    }

    /** @return array{0: string, 1: EnrollmentInfo} */
    private function resolve(Principal $principal, string $establishmentId, string $childId): array
    {
        $this->access->require($principal, $establishmentId, self::PERMISSION);
        $guardian = $this->guardianId($principal);
        $mine = false;
        foreach ($this->people->learnersOf($principal->tenantId, $guardian) as $child) {
            $mine = $mine || $child->id === $childId;
        }
        $enrollment = $mine ? $this->liveEnrollment($principal->tenantId, $establishmentId, $childId) : null;
        if ($enrollment === null || $enrollment->structureId === null) {
            throw HttpException::notFound('Enfant introuvable.');
        }

        return [$guardian, $enrollment];
    }

    private function guardianId(Principal $principal): string
    {
        return $this->links->personIdForUser($principal->tenantId, $principal->userId) ?? throw HttpException::notFound('Compte parent introuvable.');
    }

    private function liveEnrollment(string $tenantId, string $establishmentId, string $childId): ?EnrollmentInfo
    {
        foreach ($this->enrollments->forPerson($tenantId, $childId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && in_array($enrollment->status, self::LIVE, true)) {
                return $enrollment;
            }
        }

        return null;
    }
}

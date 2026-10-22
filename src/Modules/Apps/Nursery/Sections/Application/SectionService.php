<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Sections\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureDraft;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureInfo;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\StructureEnrollments;

/**
 * Sections de l'école (EX-MAT-04) : une section est une classe du socle créée par StructureWriter, inscrite au registre `nu_sections`
 * (libellé de l'école, ordre, sous-système). L'identifiant public est celui de la classe. Les libellés sont saisis par l'école
 * (petite, moyenne, grande ou propres à l'école) : aucun n'est imposé.
 */
final class SectionService
{
    public const TABLE = 'nu_sections';
    public const SUBSYSTEMS = ['fr', 'en', 'both'];

    public function __construct(
        private readonly Store $store,
        private readonly OrgUnitTree $tree,
        private readonly StructureWriter $writer,
        private readonly StructureEnrollments $enrolled,
        private readonly Access $access,
        private readonly NurseryClock $clock,
    ) {
    }

    /**
     * @return list<array<string, mixed>> sections du périmètre, par ordre d'affichage
     */
    public function inPerimeter(Perimeter $perimeter): array
    {
        $rows = $this->store->select(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, [], 'sort_order, label, id', 500);
        $out = [];
        foreach ($rows as $row) {
            if ($perimeter->coversSection((string) $row['id'])) {
                $out[] = $this->resource($row);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> 404 si la section n'existe pas dans cet établissement ou hors périmètre */
    public function get(Perimeter $perimeter, string $id): array
    {
        $row = $this->store->find(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $id);
        if ($row === null) {
            throw HttpException::notFound('Section introuvable.');
        }
        $this->access->assertReadable($perimeter, $id);

        return $this->resource($row);
    }

    /** @return array<string, mixed> */
    public function findRaw(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id, 'Section introuvable.');
    }

    /** @param array<string, mixed> $data label, capacity?, sort_order?, subsystem?, structure_id? (classe existante à inscrire au registre)
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $data): array
    {
        $tenantId = $principal->tenantId;
        $label = (string) $data['label'];
        $capacity = isset($data['capacity']) ? (int) $data['capacity'] : null;
        $existing = isset($data['structure_id']) ? (string) $data['structure_id'] : null;
        if ($existing !== null) {
            $info = $this->tree->structure($tenantId, $establishmentId, $existing);
            if ($info === null || $info->kind !== StructureDraft::SCHOOL_CLASS) {
                throw HttpException::validation(['structure_id' => ['Classe introuvable dans cet établissement.']]);
            }
            if ($this->store->find(self::TABLE, $tenantId, $establishmentId, $existing) !== null) {
                throw HttpException::conflict('Cette classe est déjà une section.');
            }
            $structureId = $existing;
        } else {
            $structureId = $this->writer->createStructure($tenantId, $establishmentId, new StructureDraft(StructureDraft::SCHOOL_CLASS, $label, null, null, $capacity), $principal->userId);
        }
        $now = $this->clock->now();
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
            'id' => $structureId, 'label' => $label, 'sort_order' => (int) ($data['sort_order'] ?? 0),
            'subsystem' => (string) ($data['subsystem'] ?? 'fr'), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'nursery.section.created', 'section', $structureId, null, ['label' => $label, 'capacity' => $capacity]);

        return $this->resource($this->findRaw($tenantId, $establishmentId, $structureId));
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function update(Principal $principal, string $establishmentId, string $id, array $changes): array
    {
        $tenantId = $principal->tenantId;
        $before = $this->findRaw($tenantId, $establishmentId, $id);
        $structure = [];
        if (isset($changes['label'])) {
            $structure['name'] = (string) $changes['label'];
        }
        if (array_key_exists('capacity', $changes)) {
            $structure['capacity'] = $changes['capacity'] === null ? null : (int) $changes['capacity'];
        }
        if ($structure !== []) {
            $this->writer->updateStructure($tenantId, $establishmentId, $id, $structure, $principal->userId);
        }
        $values = ['updated_at' => $this->clock->now()];
        foreach (['label', 'sort_order', 'subsystem'] as $field) {
            if (array_key_exists($field, $changes)) {
                $values[$field] = $changes[$field];
            }
        }
        $this->store->update(self::TABLE, $tenantId, $establishmentId, $values, ['id' => $id]);
        $this->access->audit($principal, 'nursery.section.updated', 'section', $id, ['label' => $before['label']], $changes);

        return $this->resource($this->findRaw($tenantId, $establishmentId, $id));
    }

    /** @return list<string> enfants inscrits (actifs ou sous condition) dans la section */
    public function childIds(string $tenantId, string $sectionId): array
    {
        $ids = [];
        foreach ($this->enrolled->inStructure($tenantId, $sectionId) as $enrollment) {
            if (in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true)) {
                $ids[] = $enrollment->personId;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function resource(array $row): array
    {
        $info = $this->tree->structure((string) $row['tenant_id'], (string) $row['establishment_id'], (string) $row['id']);

        return [
            'id' => (string) $row['id'],
            'label' => (string) $row['label'],
            'capacity' => $info instanceof StructureInfo ? $info->capacity : null,
            'enrolled' => count($this->childIds((string) $row['tenant_id'], (string) $row['id'])),
            'sort_order' => (int) $row['sort_order'],
            'subsystem' => (string) $row['subsystem'],
        ];
    }

    /** Une section de cet établissement (registre), pour les services qui reçoivent un identifiant de section. */
    public function exists(string $tenantId, string $establishmentId, string $id): bool
    {
        return $this->store->find(self::TABLE, $tenantId, $establishmentId, $id) !== null;
    }
}

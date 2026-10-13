<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Application;

use PDOException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureDraft;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter;
use Scholaris\Modules\Socle\Organisation\Domain\EstablishmentType;
use Scholaris\Modules\Socle\Organisation\Domain\StructureKind;
use Scholaris\Modules\Socle\Organisation\Http\Resources\OrgUnitResource;
use Scholaris\Modules\Socle\Organisation\Http\Resources\StructureResource;
use Scholaris\Modules\Socle\Organisation\Infrastructure\Repositories;

/**
 * Écriture des unités internes et des structures pour les applications (D1). Les contrôles de références sont partagés avec les
 * contrôleurs HTTP (structureErrors, orgUnitErrors) : une même règle, deux portes d'entrée.
 */
final class StructureWriterService implements StructureWriter
{
    private const MAX_DEPTH = 100;

    public function __construct(
        private readonly Repositories $repositories,
        private readonly AuditLog $audit,
        private readonly OrgClock $clock,
    ) {
    }

    public function createOrgUnit(string $tenantId, string $establishmentId, string $type, string $name, ?string $parentId = null, ?string $actorId = null): string
    {
        $this->establishment($tenantId, $establishmentId);
        $errors = $this->textErrors(['type' => [$type, 40], 'name' => [$name, 160]])
            + $this->orgUnitErrors($tenantId, $establishmentId, $parentId, null);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $units = $this->repositories->explicit('org_units', $tenantId, $establishmentId);
        $this->guarded(static fn () => $units->insert(['id' => $id, 'parent_id' => $parentId, 'type' => trim($type), 'name' => trim($name), 'created_at' => $now, 'updated_at' => $now]));
        $this->audit->append($tenantId, $actorId, 'org_unit.created', 'org_unit', $id, null, (new OrgUnitResource())->toArray($units->findOrFail($id)));

        return $id;
    }

    public function updateOrgUnit(string $tenantId, string $establishmentId, string $orgUnitId, array $changes, ?string $actorId = null): void
    {
        $units = $this->repositories->explicit('org_units', $tenantId, $establishmentId);
        $existing = $units->find($orgUnitId) ?? throw HttpException::notFound('Unité introuvable dans cet établissement.');
        $columns = array_intersect_key($changes, array_flip(['type', 'name', 'parent_id']));
        $errors = [];
        foreach (['type' => 40, 'name' => 160] as $field => $max) {
            if (array_key_exists($field, $columns)) {
                $errors += $this->textErrors([$field => [(string) $columns[$field], $max]]);
                $columns[$field] = trim((string) $columns[$field]);
            }
        }
        if (array_key_exists('parent_id', $columns)) {
            $errors += $this->orgUnitErrors($tenantId, $establishmentId, $columns['parent_id'] === null ? null : (string) $columns['parent_id'], $orgUnitId);
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if ($columns === []) {
            return;
        }
        $this->guarded(fn () => $units->update($orgUnitId, $columns + ['updated_at' => $this->clock->now()]));
        $resource = new OrgUnitResource();
        $this->audit->append($tenantId, $actorId, 'org_unit.updated', 'org_unit', $orgUnitId, $resource->toArray($existing), $resource->toArray($units->findOrFail($orgUnitId)));
    }

    public function createStructure(string $tenantId, string $establishmentId, StructureDraft $draft, ?string $actorId = null): string
    {
        $type = $this->establishment($tenantId, $establishmentId);
        $errors = $this->textErrors(['name' => [$draft->name, 160]]);
        $kind = StructureKind::tryFrom($draft->kind);
        if ($kind === null) {
            $errors['kind'] = ['Nature inconnue (level, class, group ou track).'];
        }
        if ($draft->capacity !== null && ($draft->capacity < 1 || $draft->capacity > 10000)) {
            $errors['capacity'] = ['La capacité doit être comprise entre 1 et 10000.'];
        }
        if ($kind !== null) {
            $errors += $this->structureErrors($tenantId, $establishmentId, $type, $kind, $draft->orgUnitId, $draft->parentId, null);
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $id = (string) new Ulid();
        $now = $this->clock->now();
        $structures = $this->repositories->explicit('pedagogical_structures', $tenantId, $establishmentId);
        $this->guarded(static fn () => $structures->insert([
            'id' => $id, 'org_unit_id' => $draft->orgUnitId, 'parent_id' => $draft->parentId, 'kind' => $draft->kind,
            'name' => trim($draft->name), 'capacity' => $draft->capacity, 'created_at' => $now, 'updated_at' => $now,
        ]));
        $this->audit->append($tenantId, $actorId, 'structure.created', 'structure', $id, null, (new StructureResource())->toArray($structures->findOrFail($id)));

        return $id;
    }

    public function updateStructure(string $tenantId, string $establishmentId, string $structureId, array $changes, ?string $actorId = null): void
    {
        $type = $this->establishment($tenantId, $establishmentId);
        $structures = $this->repositories->explicit('pedagogical_structures', $tenantId, $establishmentId);
        $existing = $structures->find($structureId) ?? throw HttpException::notFound('Structure introuvable dans cet établissement.');
        if (array_key_exists('kind', $changes) && $changes['kind'] !== $existing['kind']) {
            throw HttpException::validation(['kind' => ['La nature d\'une structure ne change pas après la création.']]);
        }
        $columns = array_intersect_key($changes, array_flip(['name', 'org_unit_id', 'parent_id', 'capacity']));
        $errors = [];
        if (array_key_exists('name', $columns)) {
            $errors += $this->textErrors(['name' => [(string) $columns['name'], 160]]);
            $columns['name'] = trim((string) $columns['name']);
        }
        if (array_key_exists('capacity', $columns) && $columns['capacity'] !== null && ((int) $columns['capacity'] < 1 || (int) $columns['capacity'] > 10000)) {
            $errors['capacity'] = ['La capacité doit être comprise entre 1 et 10000.'];
        }
        $errors += $this->structureErrors(
            $tenantId,
            $establishmentId,
            $type,
            StructureKind::from((string) $existing['kind']),
            array_key_exists('org_unit_id', $columns) ? self::nullable($columns['org_unit_id']) : null,
            array_key_exists('parent_id', $columns) ? self::nullable($columns['parent_id']) : null,
            $structureId,
        );
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if ($columns === []) {
            return;
        }
        $this->guarded(fn () => $structures->update($structureId, $columns + ['updated_at' => $this->clock->now()]));
        $resource = new StructureResource();
        $this->audit->append($tenantId, $actorId, 'structure.updated', 'structure', $structureId, $resource->toArray($existing), $resource->toArray($structures->findOrFail($structureId)));
    }

    /**
     * Contrôles d'une structure : nature permise pour le type d'établissement, unité et structure parente de l'établissement,
     * pas de cycle. $orgUnitId et $parentId nuls = non fournis (rien à vérifier).
     *
     * @return array<string, list<string>>
     */
    public function structureErrors(string $tenantId, string $establishmentId, EstablishmentType $type, StructureKind $kind, ?string $orgUnitId, ?string $parentId, ?string $selfId): array
    {
        $errors = [];
        if (!$kind->allowedFor($type)) {
            $errors['kind'] = ['La filière n\'existe que dans un établissement d\'enseignement supérieur.'];
        }
        if ($orgUnitId !== null && $this->repositories->explicit('org_units', $tenantId, $establishmentId)->find($orgUnitId) === null) {
            $errors['org_unit_id'] = ['Unité introuvable dans cet établissement.'];
        }
        if ($parentId !== null) {
            $error = $this->parentError('pedagogical_structures', $tenantId, $establishmentId, $parentId, $selfId, 'Structure parente introuvable dans cet établissement.', 'Une structure ne peut pas dépendre d\'elle-même ni de l\'une de ses sous-structures.');
            if ($error !== null) {
                $errors['parent_id'] = [$error];
            }
        }

        return $errors;
    }

    /** @return array<string, list<string>> */
    public function orgUnitErrors(string $tenantId, string $establishmentId, ?string $parentId, ?string $selfId): array
    {
        if ($parentId === null) {
            return [];
        }
        $error = $this->parentError('org_units', $tenantId, $establishmentId, $parentId, $selfId, 'Unité parente introuvable dans cet établissement.', 'Une unité ne peut pas dépendre d\'elle-même ni de l\'une de ses sous-unités.');

        return $error === null ? [] : ['parent_id' => [$error]];
    }

    private function parentError(string $table, string $tenantId, string $establishmentId, string $parentId, ?string $selfId, string $missing, string $cycle): ?string
    {
        $parents = [];
        foreach ($this->repositories->explicit($table, $tenantId, $establishmentId)->all() as $row) {
            $parents[(string) $row['id']] = self::nullable($row['parent_id'] ?? null);
        }
        if (!array_key_exists($parentId, $parents)) {
            return $missing;
        }
        if ($selfId !== null) {
            for ($cursor = $parentId, $depth = 0; $cursor !== null && $depth < self::MAX_DEPTH; $depth++) {
                if ($cursor === $selfId) {
                    return $cycle;
                }
                $cursor = $parents[$cursor] ?? null;
            }
        }

        return null;
    }

    private function establishment(string $tenantId, string $establishmentId): EstablishmentType
    {
        $row = $this->repositories->explicit('establishments', $tenantId)->find($establishmentId)
            ?? throw HttpException::notFound('Établissement introuvable.');

        return EstablishmentType::from((string) $row['type']);
    }

    /**
     * @param array<string, array{0: string, 1: int}> $fields champ => [valeur, longueur maximale]
     * @return array<string, list<string>>
     */
    private function textErrors(array $fields): array
    {
        $errors = [];
        foreach ($fields as $field => [$value, $max]) {
            $length = mb_strlen(trim($value));
            if ($length < 1 || $length > $max) {
                $errors[$field] = [sprintf('Ce champ doit contenir entre 1 et %d caractères.', $max)];
            }
        }

        return $errors;
    }

    /** @param callable(): mixed $write */
    private function guarded(callable $write): void
    {
        try {
            $write();
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE') || in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw HttpException::conflict('Un enregistrement identique existe déjà dans cet établissement.');
            }
            throw $e;
        }
    }

    private static function nullable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}

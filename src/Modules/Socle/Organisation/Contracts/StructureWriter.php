<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/**
 * Contrat d'ÉCRITURE « application » (D1) : création et modification des unités internes (types libres, dont `school` et
 * `department`) et des structures pédagogiques (`track`, `level`, `group`, `class`) rattachées à une unité et, au besoin, à une
 * structure parente (filière > niveau > groupe). Mêmes règles que les routes /org-units et /structures : client et établissement
 * imposés, références vérifiées dans l'établissement (sinon 422), cycle refusé, filière réservée au supérieur ; chaque écriture
 * est journalisée dans l'audit. Les erreurs sont des HttpException (422 validation, 404 identifiant inconnu).
 */
interface StructureWriter
{
    /** @return string identifiant (ULID) de l'unité créée */
    public function createOrgUnit(string $tenantId, string $establishmentId, string $type, string $name, ?string $parentId = null, ?string $actorId = null): string;

    /** @param array{type?: string, name?: string, parent_id?: ?string} $changes */
    public function updateOrgUnit(string $tenantId, string $establishmentId, string $orgUnitId, array $changes, ?string $actorId = null): void;

    /** @return string identifiant (ULID) de la structure créée */
    public function createStructure(string $tenantId, string $establishmentId, StructureDraft $draft, ?string $actorId = null): string;

    /**
     * La nature (`kind`) ne change pas après la création : une valeur différente est refusée (422).
     *
     * @param array{kind?: string, name?: string, org_unit_id?: ?string, parent_id?: ?string, capacity?: ?int} $changes
     */
    public function updateStructure(string $tenantId, string $establishmentId, string $structureId, array $changes, ?string $actorId = null): void;
}

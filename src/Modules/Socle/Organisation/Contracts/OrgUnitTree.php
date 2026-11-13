<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/**
 * Lecture de l'arbre d'un établissement (S15) : unités internes (école > département) et structures qui leur sont rattachées
 * (filière > niveau > groupe). Sert à développer un périmètre `org_unit` ou `structure` en liste d'identifiants. Toujours borné au
 * client et à l'établissement : un identifiant d'ailleurs donne une liste vide (ou null).
 */
interface OrgUnitTree
{
    /** @return list<string> l'unité elle-même en tête puis toutes ses sous-unités, à toute profondeur ; [] si l'unité est inconnue */
    public function descendantsOf(string $tenantId, string $establishmentId, string $orgUnitId): array;

    /**
     * @return list<string> structures rattachées à l'unité ou à l'une de ses sous-unités, avec leurs structures descendantes
     *                      (niveaux et groupes d'une filière) ; [] si l'unité est inconnue
     */
    public function structuresOf(string $tenantId, string $establishmentId, string $orgUnitId): array;

    /** @return list<string> la structure elle-même en tête puis ses descendantes (parent_id) ; [] si inconnue */
    public function structureDescendantsOf(string $tenantId, string $establishmentId, string $structureId): array;

    /** @return list<string> unités parentes de l'unité, de la plus proche à la racine (l'unité exclue) */
    public function ancestorsOf(string $tenantId, string $establishmentId, string $orgUnitId): array;

    public function structure(string $tenantId, string $establishmentId, string $structureId): ?StructureInfo;

    /** Filière de la structure : elle-même ou sa plus proche ascendante de nature `track` ; null s'il n'y en a pas. */
    public function trackOf(string $tenantId, string $establishmentId, string $structureId): ?string;

    /** Niveau de la structure : elle-même ou sa plus proche ascendante de nature `level` ; null s'il n'y en a pas. */
    public function levelOf(string $tenantId, string $establishmentId, string $structureId): ?string;
}

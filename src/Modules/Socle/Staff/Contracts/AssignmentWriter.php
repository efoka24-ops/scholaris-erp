<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Contracts;

/**
 * Écriture des affectations d'enseignement par une application (D17), avec l'élément constitutif et le groupe en références
 * externes (D18). Mêmes contrôles que l'API (HttpException 404, 409, 422) ; l'appelant contrôle les droits et journalise.
 */
interface AssignmentWriter
{
    /** @return string identifiant de l'affectation */
    public function assign(string $tenantId, string $establishmentId, AssignmentDraft $draft, string $actorId): string;

    /**
     * Modification partielle. Clés acceptées : session_kind, planned_minutes, element_ref, group_ref, level, academic_year_id,
     * structure_id, subject_label, starts_on, ends_on, status (active|ended).
     *
     * @param array<string, mixed> $changes
     */
    public function change(string $tenantId, string $establishmentId, string $assignmentId, array $changes): void;

    /** Termine l'affectation (status = ended) ; les heures déjà rattachées restent. */
    public function end(string $tenantId, string $establishmentId, string $assignmentId): void;

    public function find(string $tenantId, string $establishmentId, string $assignmentId): ?AssignmentInfo;

    /** @return list<AssignmentInfo> affectations actives d'un élément constitutif, éventuellement limitées à un groupe */
    public function forElement(string $tenantId, string $establishmentId, string $elementRef, ?string $groupRef = null): array;
}

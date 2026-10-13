<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Contracts;

/**
 * Écriture des créneaux par une application (D11), sans passer par HTTP. Mêmes contrôles que l'API : cohérence des heures et
 * des dates (HttpException 422), conflits d'enseignant, de salle, de groupe et de capacité (TimetableConflict). L'appelant
 * contrôle les droits et journalise.
 */
interface TimetableWriter
{
    /** @return string identifiant du créneau créé */
    public function create(string $tenantId, string $establishmentId, SlotDraft $draft, string $actorId): string;

    /**
     * Modification partielle ; clés acceptées : celles de SlotDraft::toArray().
     *
     * @param array<string, mixed> $changes
     */
    public function update(string $tenantId, string $establishmentId, string $slotId, array $changes): void;

    /** Supprime le créneau et ses modifications ponctuelles. */
    public function delete(string $tenantId, string $establishmentId, string $slotId): void;

    /**
     * Vérification à blanc.
     *
     * @return list<array{kind: string, message: string, slot_id: string|null, structure_ids: list<string>}> conflits (vide = posable)
     */
    public function check(string $tenantId, string $establishmentId, SlotDraft $draft, ?string $ignoreSlotId = null): array;
}

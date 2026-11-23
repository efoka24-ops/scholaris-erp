<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Contracts;

/**
 * Mutation d'un membre du personnel d'un établissement A vers un établissement B du même client (PP19) : les affectations
 * actives de A sont closes à la date d'effet, la fiche est rattachée à B, la mutation est conservée dans l'historique.
 * L'appelant contrôle les droits et journalise.
 *
 * Erreurs (HttpException) : 404 fiche ou établissement introuvable dans le client, 409 membre parti, 422 mutation vers
 * l'établissement de rattachement ou date invalide.
 */
interface StaffMover
{
    public function move(string $tenantId, string $staffId, string $toEstablishmentId, string $effectiveOn, ?string $reason, string $actorId): StaffMoveInfo;

    /** @return list<StaffMoveInfo> mutations du membre, de la plus ancienne à la plus récente */
    public function history(string $tenantId, string $staffId): array;
}

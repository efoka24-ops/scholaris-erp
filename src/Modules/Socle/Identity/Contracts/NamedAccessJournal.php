<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

use InvalidArgumentException;

/**
 * PP3 (T607) : journal des consultations nominatives. Toute lecture d'une donnée nominative par un rôle territorial ou de
 * supervision est journalisée avec sa finalité ; le directeur de l'établissement consulté voit ces lignes.
 */
interface NamedAccessJournal
{
    /**
     * À appeler après autorisation de la lecture. Seuls des identifiants minimaux sont journalisés (aucun contenu métier).
     *
     * @throws InvalidArgumentException si la finalité est vide ou trop longue (500 caractères au plus)
     */
    public function record(
        string $tenantId,
        string $establishmentId,
        string $actorId,
        string $recordType,
        string $recordId,
        string $purpose,
        ?string $ip = null,
        ?string $device = null,
    ): void;
}

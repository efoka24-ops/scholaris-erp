<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use RuntimeException;

/**
 * Conflit de version sur une ou plusieurs notes (saisie hors ligne ou concurrente) : jamais résolu en silence.
 * Porte les deux versions, celle du serveur et celle du client, pour que l'utilisateur tranche.
 */
final class VersionConflict extends RuntimeException
{
    /** @param list<array{server: array<string, mixed>, client: array<string, mixed>}> $conflicts */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct('Une note a été modifiée entre-temps. Comparez les deux versions et choisissez celle à conserver.', 409);
    }
}

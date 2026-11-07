<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

/** Indicateurs affichés sur une tuile du lanceur « Mes établissements ». Fourni par Reporting ; absent, la tuile n'en porte pas. */
interface TileIndicators
{
    /**
     * Au plus deux indicateurs réellement disponibles pour l'établissement, adaptés aux rôles exercés.
     *
     * @param list<string> $roleCodes
     * @return list<array{key: string, label: string, value: int|float, unit: string}>
     */
    public function forTile(string $tenantId, string $establishmentId, array $roleCodes): array;
}

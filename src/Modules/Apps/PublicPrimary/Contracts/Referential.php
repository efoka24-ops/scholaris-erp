<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Contracts;

/**
 * Référentiel national et calendrier des unités (EVA-01, EVA-02), fourni par PublicPrimary\Referential aux autres sous-modules de la
 * Primaire publique. Lecture seule, bornée au client.
 */
interface Referential
{
    /**
     * Disciplines en vigueur à la date pour le sous-système et le niveau : pour chaque discipline, la dernière version valide.
     *
     * @return list<FrameInfo> triées par code de discipline
     */
    public function framesFor(string $tenantId, string $subsystem, string $levelCode, string $date): array;

    public function frame(string $tenantId, string $frameId): ?FrameInfo;

    public function unit(string $tenantId, string $unitId): ?UnitInfo;

    /** @return list<UnitInfo> unités de l'année et du niveau, par numéro */
    public function unitsOf(string $tenantId, string $yearLabel, string $levelCode): array;
}

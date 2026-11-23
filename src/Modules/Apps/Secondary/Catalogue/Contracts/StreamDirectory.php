<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Catalogue\Contracts;

/**
 * Séries et spécialités proposées à un établissement (contrat interne de Secondary) : lignes de la plateforme non retirées et
 * ajouts de l'établissement, renommages et masquages de l'établissement appliqués, filtrées par les cycles et enseignements de
 * son profil. Consommé par Setup (classes, coefficients) et Enrolment (choix de série) dans les lots suivants.
 */
interface StreamDirectory
{
    /** @return list<StreamInfo> séries et spécialités visibles (non masquées), triées par filière, cycle et code */
    public function forEstablishment(string $tenantId, string $establishmentId): array;

    /** Une ligne visible par l'établissement (masquée comprise), sinon null. */
    public function find(string $tenantId, string $establishmentId, string $id): ?StreamInfo;
}

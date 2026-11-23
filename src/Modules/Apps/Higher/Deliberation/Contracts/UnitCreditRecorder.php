<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Contracts;

/**
 * Écriture des UE capitalisées (`hi_unit_credits`) par la REPRISE DE DONNÉES (lot I4) : crédits acquis avant l'ouverture du logiciel,
 * qui comptent dans les crédits cumulés et les UE capitalisées de la décision de fin d'année (RG-INS-4, F6). Contrat additif,
 * réservé à Higher\Migration. Chaque ligne porte le numéro du lot de reprise (`jury_id`) et la session « reprise ».
 */
interface UnitCreditRecorder
{
    public const SESSION = 'reprise';
    public const PERIOD = 'reprise';

    /**
     * Enregistre une UE acquise. Idempotent par personne, filière, année et UE : la ligne existante est reprise telle quelle.
     *
     * @param string $yearLabel année d'acquisition (libellé libre, p. ex. 2024-2025) : jamais l'année en cours de l'inscription
     * @return bool vrai si la ligne a été créée, faux si elle existait déjà
     */
    public function record(
        string $tenantId,
        string $establishmentId,
        string $batchId,
        string $personId,
        string $programId,
        string $levelId,
        string $yearLabel,
        string $unitCode,
        ?string $unitTitle,
        int $credits,
        ?float $average,
    ): bool;

    /** Retire les lignes créées par un lot de reprise ; renvoie leur nombre. */
    public function revoke(string $tenantId, string $establishmentId, string $batchId): int;
}

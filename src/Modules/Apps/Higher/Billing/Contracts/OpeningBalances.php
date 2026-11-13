<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Contracts;

/**
 * Soldes d'ouverture repris par la REPRISE DE DONNÉES (lot I4) : ce qu'un étudiant en cours doit encore à l'ouverture du logiciel,
 * échelonné en tranches. Contrat additif, réservé à Higher\Migration. Le compte de scolarité est ouvert sur une facture libre du socle
 * (pension, libellé « Solde d'ouverture ») sans passer par la grille de frais : les paiements antérieurs ne sont PAS repris.
 */
interface OpeningBalances
{
    /**
     * Ouvre le compte de scolarité de l'année et de la filière avec le solde dû. Idempotent : un compte existant pour cette année et
     * cette filière est conservé tel quel (`created` faux).
     *
     * @param list<array{due_on: string, amount_xaf: int}> $installments tranches (date AAAA-MM-JJ, montant entier XAF > 0), au moins une
     * @return array{account_id: string, invoice_id: string, net_xaf: int, created: bool}
     * @throws \Scholaris\Core\Http\HttpException 422 tranche invalide
     */
    public function open(
        string $tenantId,
        string $establishmentId,
        string $actorId,
        string $personId,
        string $academicYearId,
        string $schoolId,
        string $programId,
        ?string $levelId,
        ?string $studyMode,
        ?string $enrolmentRef,
        array $installments,
    ): array;

    /** Le compte de scolarité de l'année et de la filière existe-t-il déjà ? */
    public function exists(string $tenantId, string $establishmentId, string $personId, string $academicYearId, string $programId): bool;
}

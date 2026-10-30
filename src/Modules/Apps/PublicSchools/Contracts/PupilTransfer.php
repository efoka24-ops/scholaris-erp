<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Contracts;

/**
 * Transfert d'un élève entre écoles (ELV, API-03), y compris maternelle vers primaire d'un même groupe scolaire ou d'un autre :
 * demande, accord de l'école d'accueil, clôture de l'inscription d'origine à la date d'accueil (RG-ELV-4, RG-ELV-5). Fourni par
 * PublicSchools\Pupils. Une seule école à la fois : aucune inscription n'est créée avant l'accord.
 */
interface PupilTransfer
{
    public const REQUESTED = 'requested';
    public const ACCEPTED = 'accepted';
    public const REFUSED = 'refused';
    public const COMPLETED = 'completed';

    /**
     * Demande de transfert vers une autre école du même client.
     *
     * @return string identifiant du transfert
     * @throws \InvalidArgumentException si l'élève n'est pas inscrit dans l'école d'origine ou si l'école d'accueil est fermée
     */
    public function request(string $tenantId, string $personId, string $fromEstablishmentId, string $toEstablishmentId, string $actorId): string;

    /**
     * Accord de l'école d'accueil : clôt l'inscription d'origine à `$arrivalOn` (AAAA-MM-JJ) et inscrit l'élève dans l'école d'accueil.
     *
     * @throws \InvalidArgumentException si le transfert n'est plus à l'état « requested »
     */
    public function accept(string $tenantId, string $transferId, string $arrivalOn, string $actorId): void;

    /** Refus motivé de l'école d'accueil. */
    public function refuse(string $tenantId, string $transferId, string $reason, string $actorId): void;

    /** État du transfert (constantes ci-dessus), ou null s'il est inconnu de ce client. */
    public function stateOf(string $tenantId, string $transferId): ?string;
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Contracts;

/**
 * Options d'émission pour les applications (S12, S16, S17) :
 * - `$schoolCode` : valeur du jeton {ECOLE} du gabarit de numéro (ex. EC dans REL-EC-2026-000315) ; la suite est alors propre à l'école ;
 * - `$orgUnitId` : école interne émettrice (signataire de l'école d'abord, puis celui de l'établissement) ;
 * - `$signerRole` : rôle du signataire, prioritaire sur celui du type ;
 * - `$enforceFinancialBlock` : applique le blocage `documents` pour impayé si le type est bloquable, même sans le paramètre
 *   `documents.block_single_issuance`.
 */
final class IssueOptions
{
    public function __construct(
        public readonly ?string $schoolCode = null,
        public readonly ?string $orgUnitId = null,
        public readonly ?string $signerRole = null,
        public readonly bool $enforceFinancialBlock = false,
    ) {
    }
}

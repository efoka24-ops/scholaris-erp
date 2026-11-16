<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

/**
 * Personnel d'un établissement d'après les rôles (RAPPORT-MAIL, 16 novembre 2026) : une personne en fait partie si elle a, à cet
 * instant, une affectation en vigueur d'un rôle INTERNE qui s'exerce dans cet établissement (affectation de l'établissement ou de
 * niveau client : administrateur du client, direction, enseignant, secrétariat, comptabilité…). Les rôles d'apprenant, de famille,
 * de représentation (bureau APEE, délégué, conseil d'école) et de contrôle externe (tutelle, inspection) n'en font jamais partie.
 * Fourni par Identity ; cloisonné par client et par établissement.
 */
interface EstablishmentPersonnel
{
    /**
     * @param list<string> $personIds
     * @return list<string> sous-ensemble des personnes reçues qui sont du personnel de cet établissement
     */
    public function personnelAmong(string $tenantId, string $establishmentId, array $personIds): array;
}

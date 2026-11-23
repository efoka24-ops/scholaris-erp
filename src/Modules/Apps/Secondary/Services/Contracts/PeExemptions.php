<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Services\Contracts;

/**
 * Dispenses d'EPS saisies par l'infirmier [infirmary] (T973, EX-SEC-66), en LECTURE et SANS motif médical : Grades s'en sert pour
 * proposer le code `exempt` sur une épreuve d'EPS, l'appel et le professeur pour savoir qu'un élève est dispensé. Rien n'est rendu
 * si le module n'a jamais été activé ; un module coupé garde ses dispenses consultables.
 */
interface PeExemptions
{
    /** Vrai si l'élève est dispensé d'EPS ce jour-là (AAAA-MM-JJ). */
    public function isExempt(string $tenantId, string $establishmentId, string $personId, string $date): bool;

    /**
     * @param list<string> $personIds
     * @return array<string, string> élèves dispensés ce jour-là => dernier jour de la dispense (AAAA-MM-JJ)
     */
    public function exemptOn(string $tenantId, string $establishmentId, array $personIds, string $date): array;
}

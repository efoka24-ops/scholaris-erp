<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts;

/**
 * Frais d'examen constatés sur cartescolaire.cm (relevé importé de l'économe, T829), contrat interne pour OfficialExams (T906,
 * TST-08 : candidat sans frais constatés → « bloqué ») et pour l'affichage du statut (RG-FIN-3 : l'établissement ne les encaisse
 * jamais).
 */
interface ExamFeeStatus
{
    /** Vrai si au moins un paiement de nature `exam_fee` rapproché de cet élève existe (pour cet examen si le code est donné). */
    public function seen(string $tenantId, string $establishmentId, string $personId, ?string $examCode = null): bool;
}

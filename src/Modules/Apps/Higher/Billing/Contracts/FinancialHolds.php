<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Contracts;

/**
 * Blocages liés aux impayés (EX-FIN-05, RG-FIN-5) pour les autres modules Higher : consultation des notes (`results`), délivrance
 * des documents (`documents`, jamais pour les types non bloquables RG-DOC-7), réinscription (`reinscription`). Paramétrés par
 * `billing.blocks` (désactivés par défaut). Aucun blocage n'empêche les cours (`courses`) ni la composition (`exams`).
 */
interface FinancialHolds
{
    public const RESULTS = 'results';
    public const DOCUMENTS = 'documents';
    public const REINSCRIPTION = 'reinscription';
    public const COURSES = 'courses';
    public const EXAMS = 'exams';

    public function isBlocked(string $tenantId, string $establishmentId, string $personId, string $scope): bool;

    /** Solde restant dû en XAF (toutes factures de l'établissement). */
    public function balanceXaf(string $tenantId, string $establishmentId, string $personId): int;
}

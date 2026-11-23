<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Application;

use Scholaris\Modules\Apps\Higher\Support\WriteGate;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureStepResult;
use Scholaris\Modules\Socle\Organisation\Contracts\TypedPromotionPlanner;

/**
 * Planificateur de passage de l'IPES (D8, T236) : à la clôture d'une année d'un établissement `higher`, remplace People\YearPromotion.
 * Crée une inscription « en attente » de l'année suivante selon la décision de passage (admis et admis avec dettes : niveau suivant,
 * dettes inscrites d'office ; redouble : même niveau, UE capitalisées ; exclu : rien). Idempotent ; client suspendu : sauté.
 */
final class HigherPromotionPlanner implements TypedPromotionPlanner
{
    public const CONTAINER_KEY = 'organisation.closure.promotion.higher';

    public function __construct(
        private readonly EnrolmentService $enrolments,
        private readonly WriteGate $gate,
    ) {
    }

    public function establishmentTypes(): array
    {
        return ['higher'];
    }

    public function promote(string $tenantId, string $establishmentId, string $fromYearId, string $toYearId): ClosureStepResult
    {
        if (!$this->gate->allowsWrites($tenantId)) {
            return new ClosureStepResult(ClosureStepResult::SKIPPED, 'Application Supérieur suspendue ou fermée : aucun passage créé.');
        }
        $result = $this->enrolments->promote($tenantId, $establishmentId, $fromYearId, $toYearId);

        return new ClosureStepResult(
            ClosureStepResult::DONE,
            sprintf('%d réinscription(s) en attente créée(s) pour l\'année suivante, %d décision(s) sans réinscription automatique.', $result['created'], $result['skipped']),
            $result,
        );
    }
}

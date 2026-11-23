<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Contracts;

/**
 * Passage de fin d'année préparé à la clôture (organisation.closure.promotion.secondary), en LECTURE : Enrolment (réinscription)
 * et les écrans de la scolarité y trouvent, pour chaque élève, la suite décidée et la classe d'accueil proposée.
 */
interface PromotionPlans
{
    public const PROMOTE = 'promote';
    public const REPEAT = 'repeat';
    public const EXCLUDED = 'excluded';

    /**
     * @return list<array{person_id: string, from_class_id: string, outcome: string, target_class_id: ?string, stream_id: ?string, basis: string, plan_status: string}>
     */
    public function forYear(string $tenantId, string $establishmentId, string $fromYearId): array;
}

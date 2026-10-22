<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Policies;

final class RoleMatrix
{
    /** @var array<string, array{code: string, permissions: list<string>}> */
    public const ROLES = [
        'P01' => ['code' => 'primary_promoteur', 'permissions' => ['primary.dashboard.read', 'reporting.read']],
        'P02' => ['code' => 'primary_directeur', 'permissions' => ['primary.settings.manage', 'primary.council.preside', 'primary.report_card.sign', 'assessment.correction.approve', 'primary.promotion.decide', 'primary.dashboard.read']],
        'P03' => ['code' => 'primary_adjoint', 'permissions' => ['primary.referential.manage', 'assessment.validate', 'scheduling.manage']],
        'P04' => ['code' => 'enseignant', 'permissions' => ['grades.enter', 'assessment.submit', 'attendance.take', 'primary.competency.enter', 'primary.journal.write', 'primary.conduct.enter']],
        'P05' => ['code' => 'enseignant', 'permissions' => ['grades.enter', 'attendance.take']],
        'P06' => ['code' => 'primary_secretaire', 'permissions' => ['people.manage', 'primary.enrol', 'documents.issue', 'primary.exam.manage']],
        'P09' => ['code' => 'primary_surveillant', 'permissions' => ['attendance.sanction.manage', 'primary.pickup.handover']],
    ];

    /**
     * Permissions ajoutées par la migration 202610211303 (lecture du référentiel, du profil d'élève, des compétences, de la conduite et du
     * cahier de liaison ; validation des feuilles de compétences). Elles complètent ROLES : mêmes rôles, mêmes codes.
     *
     * @var array<string, list<string>>
     */
    public const EXTRA = [
        'primary_directeur' => ['primary.referential.read', 'primary.learner.read', 'primary.competency.read', 'primary.competency.validate', 'primary.conduct.read', 'primary.journal.read'],
        'primary_adjoint' => ['primary.referential.read', 'primary.learner.read', 'primary.competency.read', 'primary.competency.validate', 'primary.conduct.read', 'primary.journal.read'],
        'primary_promoteur' => ['primary.referential.read', 'primary.learner.read', 'primary.competency.read', 'primary.conduct.read', 'primary.journal.read'],
        'primary_secretaire' => ['primary.referential.read', 'primary.learner.read'],
        'enseignant' => ['primary.referential.read', 'primary.learner.read', 'primary.competency.enter', 'primary.competency.read', 'primary.conduct.enter', 'primary.conduct.read', 'primary.journal.write', 'primary.journal.read'],
    ];

    /** @var array<string, array<string, array{0: string, 1: string}>> */
    public const MATRIX = [
        'parametrage' => ['P02' => ['V', 'primary.settings.manage'], 'P03' => ['E', 'primary.referential.manage']],
        'inscriptions' => ['P02' => ['V', 'primary.enrol'], 'P06' => ['E', 'primary.enrol']],
        'presence' => ['P04' => ['E', 'attendance.take'], 'P09' => ['E', 'attendance.sanction.manage']],
        'conseil' => ['P02' => ['V', 'primary.council.preside'], 'P03' => ['E', 'assessment.validate']],
    ];

    private function __construct()
    {
    }
}

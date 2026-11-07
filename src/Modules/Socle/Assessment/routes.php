<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Socle\Assessment\Http\Controllers\AssessmentsController;
use Scholaris\Modules\Socle\Assessment\Http\Controllers\CorrectionController;
use Scholaris\Modules\Socle\Assessment\Http\Controllers\CouncilController;
use Scholaris\Modules\Socle\Assessment\Http\Controllers\DeliberationController;
use Scholaris\Modules\Socle\Assessment\Http\Controllers\GradeController;
use Scholaris\Modules\Socle\Assessment\Http\Controllers\GradeSheetController;
use Scholaris\Modules\Socle\Assessment\Http\Controllers\ResultController;
use Scholaris\Modules\Socle\Assessment\Http\Controllers\RuleController;
use Scholaris\Modules\Socle\Assessment\Http\Controllers\StatisticsController;
use Scholaris\Modules\Socle\Assessment\Http\Controllers\SubjectController;

/** Routes du module Assessment (S09). Le préfixe /api/v1 est ajouté par le registre ; toutes exigent X-Establishment-Id. */
return static function (RouteRegistry $routes): void {
    $routes->add('GET', '/subjects', [SubjectController::class, 'index']);
    $routes->add('POST', '/subjects', [SubjectController::class, 'store']);
    $routes->add('GET', '/subjects/{id}', [SubjectController::class, 'show']);
    $routes->add('PATCH', '/subjects/{id}', [SubjectController::class, 'update']);
    $routes->add('POST', '/subjects/{id}/elements', [SubjectController::class, 'addElement']);

    $routes->add('GET', '/assessments', [AssessmentsController::class, 'index']);
    $routes->add('POST', '/assessments', [AssessmentsController::class, 'store']);
    $routes->add('GET', '/assessments/{id}', [AssessmentsController::class, 'show']);
    $routes->add('PATCH', '/assessments/{id}', [AssessmentsController::class, 'update']);
    $routes->add('POST', '/assessments/{id}/transition', [AssessmentsController::class, 'transition']);
    $routes->add('POST', '/assessments/{id}/reveal', [AssessmentsController::class, 'reveal']);

    $routes->add('GET', '/calculation-rules', [RuleController::class, 'index']);
    $routes->add('POST', '/calculation-rules', [RuleController::class, 'store']);
    $routes->add('GET', '/calculation-rules/{id}', [RuleController::class, 'show']);

    $routes->add('GET', '/grade-sheets', [GradeSheetController::class, 'index']);
    $routes->add('POST', '/grade-sheets/transition', [GradeSheetController::class, 'transition']);
    $routes->add('GET', '/grade-sheets/{id}', [GradeSheetController::class, 'show']);

    $routes->add('GET', '/grades', [GradeController::class, 'index']);
    $routes->add('POST', '/grades', [GradeController::class, 'store']);
    $routes->add('POST', '/grades/batch', [GradeController::class, 'batch']);
    $routes->add('POST', '/grades/sync', [GradeController::class, 'sync']);
    $routes->add('POST', '/grades/import', [GradeController::class, 'import']);
    $routes->add('GET', '/grades/import-template', [GradeController::class, 'template']);
    $routes->add('GET', '/grades/export', [GradeController::class, 'export']);
    $routes->add('GET', '/grades/{id}', [GradeController::class, 'show']);
    $routes->add('GET', '/grades/{id}/events', [GradeController::class, 'events']);

    $routes->add('GET', '/grade-corrections', [CorrectionController::class, 'index']);
    $routes->add('POST', '/grade-corrections', [CorrectionController::class, 'store']);
    $routes->add('GET', '/grade-corrections/{id}', [CorrectionController::class, 'show']);
    $routes->add('POST', '/grade-corrections/{id}/approve', [CorrectionController::class, 'approve']);
    $routes->add('POST', '/grade-corrections/{id}/reject', [CorrectionController::class, 'reject']);

    $routes->add('GET', '/sequence-statistics', [StatisticsController::class, 'index']);

    $routes->add('GET', '/class-councils', [CouncilController::class, 'index']);
    $routes->add('POST', '/class-councils', [CouncilController::class, 'store']);
    $routes->add('GET', '/class-councils/{id}', [CouncilController::class, 'show']);
    $routes->add('PATCH', '/class-councils/{id}/lines/{line_id}', [CouncilController::class, 'updateLine']);
    $routes->add('POST', '/class-councils/{id}/refresh', [CouncilController::class, 'refresh']);
    $routes->add('POST', '/class-councils/{id}/validate', [CouncilController::class, 'validate']);
    $routes->add('GET', '/class-councils/{id}/report-cards', [CouncilController::class, 'reportCards']);
    $routes->add('POST', '/class-councils/{id}/report-cards', [CouncilController::class, 'generateReportCards']);

    $routes->add('GET', '/deliberations', [DeliberationController::class, 'index']);
    $routes->add('POST', '/deliberations', [DeliberationController::class, 'store']);
    $routes->add('GET', '/deliberations/{id}', [DeliberationController::class, 'show']);
    $routes->add('POST', '/deliberations/{id}/attendance', [DeliberationController::class, 'attendance']);
    $routes->add('POST', '/deliberations/{id}/start', [DeliberationController::class, 'start']);
    $routes->add('PATCH', '/deliberations/{id}/lines/{line_id}', [DeliberationController::class, 'updateLine']);
    $routes->add('POST', '/deliberations/{id}/close', [DeliberationController::class, 'close']);
    $routes->add('POST', '/deliberations/{id}/sign', [DeliberationController::class, 'sign']);
    $routes->add('POST', '/deliberations/{id}/reopen', [DeliberationController::class, 'reopen']);

    $routes->add('GET', '/results', [ResultController::class, 'index']);
    $routes->add('POST', '/results/publish', [ResultController::class, 'publish']);
    $routes->add('GET', '/results/export', [ResultController::class, 'export']);
    $routes->add('GET', '/results/{id}', [ResultController::class, 'show']);
};

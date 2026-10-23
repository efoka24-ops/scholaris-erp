<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\factory;
use function DI\get;

use Psr\Container\ContainerInterface;
use Scholaris\Modules\Socle\Assessment\Application\AssessmentExtensions;
use Scholaris\Modules\Socle\Assessment\Application\Calculator;
use Scholaris\Modules\Socle\Assessment\Application\DeliberationGuards;
use Scholaris\Modules\Socle\Assessment\Application\Guards\BuiltInDeliberationGuard;
use Scholaris\Modules\Socle\Assessment\Application\Guards\ReopenAlertGuard;
use Scholaris\Modules\Socle\Assessment\Application\OpenDeliberationsCheck;
use Scholaris\Modules\Socle\Assessment\Application\PendingGradingTodo;
use Scholaris\Modules\Socle\Assessment\Application\RuleScopes;
use Scholaris\Modules\Socle\Assessment\Application\RuleStrategies;
use Scholaris\Modules\Socle\Assessment\Application\UnlockedGradesCheck;
use Scholaris\Modules\Socle\Assessment\Contracts\ActorDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\AdmissionDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\CurriculumCatalog;
use Scholaris\Modules\Socle\Assessment\Contracts\DeliberationGuard;
use Scholaris\Modules\Socle\Assessment\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Assessment\Contracts\ResultsDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleCalculator;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleScopeLineage;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleStrategy;
use Scholaris\Modules\Socle\Assessment\Infrastructure\IdentityRecentSecondFactor;
use Scholaris\Modules\Socle\Assessment\Infrastructure\NoAttendanceSummary;
use Scholaris\Modules\Socle\Assessment\Infrastructure\PdoActorDirectory;
use Scholaris\Modules\Socle\Assessment\Infrastructure\PdoAdmissionDirectory;
use Scholaris\Modules\Socle\Assessment\Infrastructure\PdoCurriculumCatalog;
use Scholaris\Modules\Socle\Assessment\Infrastructure\PdoResultsDirectory;
use Scholaris\Modules\Socle\Assessment\Infrastructure\ResultRepository;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummaryProvider;

/**
 * Liaisons du module Assessment : contrat public fourni, contrats sortants locaux et repli d'assiduité
 * (la liaison du module Attendance, chargée après celle-ci, remplace le repli).
 */
return [
    ResultsDirectory::class => get(PdoResultsDirectory::class),
    PdoResultsDirectory::class => autowire(),
    \Scholaris\Modules\Socle\Assessment\Contracts\LatestPublishedAverages::class => get(PdoResultsDirectory::class),
    ActorDirectory::class => autowire(PdoActorDirectory::class),
    RecentSecondFactor::class => autowire(IdentityRecentSecondFactor::class),
    AttendanceSummaryProvider::class => autowire(NoAttendanceSummary::class),

    // Passage en classe supérieure : admissions de l'année lues par People. Les applications qui arrêtent leurs décisions de fin
    // d'année ailleurs (primaire privé) ajoutent leurs sources sous `assessment.admission_sources.*` (réunion, rétrocompatible).
    PdoAdmissionDirectory::class => autowire(),
    AdmissionDirectory::class => factory(static fn (ContainerInterface $c): AdmissionDirectory => new \Scholaris\Modules\Socle\Assessment\Infrastructure\CompositeAdmissionDirectory(
        $c->get(PdoAdmissionDirectory::class),
        AssessmentExtensions::collect($c, AssessmentExtensions::ADMISSION_SOURCES, AdmissionDirectory::class),
    )),
    // Résultats chiffrés d'une classe pour les applications qui tiennent leur propre conseil (primaire privé).
    \Scholaris\Modules\Socle\Assessment\Contracts\ClassResults::class => autowire(\Scholaris\Modules\Socle\Assessment\Infrastructure\EngineClassResults::class),

    // Clôture d'année (contrats sortants de l'Organisation) : notes non verrouillées, délibérations ouvertes.
    'organisation.closure.checks.assessment' => [UnlockedGradesCheck::class, OpenDeliberationsCheck::class],

    // Portail enseignant : saisies de notes à faire (contrat sortant de Portals).
    'portals.grading_todo.assessment' => [PendingGradingTodo::class],

    // Export complet du client (tenant:export) : liste blanche de colonnes, aucun secret.
    'privacy.tenant_data_source.assessment' => \DI\autowire(\Scholaris\Modules\Socle\Assessment\Infrastructure\PrivacyExportSource::class),

    // D9 catalogue de programme ; S5-S6 moteur appelable par les applications.
    CurriculumCatalog::class => autowire(PdoCurriculumCatalog::class),
    RuleCalculator::class => autowire(Calculator::class),

    // Points d'extension enregistrables par les applications (listes de classes sous ces préfixes) :
    // assessment.rule_strategies.* (S6), assessment.rule_scopes.* (S5), assessment.deliberation_guards.* (S8).
    RuleStrategies::class => factory(static fn (ContainerInterface $c): RuleStrategies => new RuleStrategies(AssessmentExtensions::collect($c, AssessmentExtensions::STRATEGIES, RuleStrategy::class))),
    RuleScopes::class => factory(static fn (ContainerInterface $c): RuleScopes => new RuleScopes(AssessmentExtensions::collect($c, AssessmentExtensions::SCOPES, RuleScopeLineage::class))),
    DeliberationGuards::class => factory(static fn (ContainerInterface $c): DeliberationGuards => new DeliberationGuards(
        $c->get(ResultRepository::class),
        AssessmentExtensions::collect($c, AssessmentExtensions::GUARDS, DeliberationGuard::class),
    )),
    'assessment.deliberation_guards.assessment' => [BuiltInDeliberationGuard::class, ReopenAlertGuard::class],
];

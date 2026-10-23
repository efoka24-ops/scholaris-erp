<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use Psr\Container\ContainerInterface;

/**
 * Découvre les points d'extension déclarés dans le conteneur par les applications : entrées `<préfixe><module>` dont
 * la valeur est une liste de classes (ou d'objets). Plusieurs applications s'enregistrent sans se marcher dessus.
 *
 *  - assessment.rule_strategies.* : Contracts\RuleStrategy (S6) ;
 *  - assessment.rule_scopes.* : Contracts\RuleScopeLineage (S5) ;
 *  - assessment.deliberation_guards.* : Contracts\DeliberationGuard (S8).
 */
final class AssessmentExtensions
{
    public const STRATEGIES = 'assessment.rule_strategies.';
    public const SCOPES = 'assessment.rule_scopes.';
    public const GUARDS = 'assessment.deliberation_guards.';
    public const COUNCIL_LINES = 'assessment.council_line_providers.';
    public const REPORT_CARD_VARIABLES = 'assessment.report_card_variables.';
    /** Sources d'admissions de fin d'année tenues par une application (Contracts\AdmissionDirectory), réunies à celles des résultats. */
    public const ADMISSION_SOURCES = 'assessment.admission_sources.';

    /**
     * @template T of object
     * @param class-string<T> $interface
     * @return list<T>
     */
    public static function collect(ContainerInterface $container, string $prefix, string $interface): array
    {
        if (!method_exists($container, 'getKnownEntryNames')) {
            return [];
        }
        $names = (array) $container->getKnownEntryNames();
        sort($names);
        $found = [];
        foreach ($names as $name) {
            if (!is_string($name) || !str_starts_with($name, $prefix)) {
                continue;
            }
            foreach ((array) $container->get($name) as $class) {
                $provider = is_string($class) ? $container->get($class) : $class;
                if ($provider instanceof $interface) {
                    $found[$provider::class] = $provider;
                }
            }
        }

        return array_values($found);
    }
}

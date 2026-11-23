<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Portals\Application;

use DI\Container;
use Scholaris\Modules\Socle\Portals\Contracts\GradingTodo;

/**
 * Rassemble les fournisseurs de saisies de notes à faire, déclarés dans le conteneur par les modules propriétaires
 * (entrées de liste `portals.grading_todo.<module>`, tableaux de noms de classes). Aucun fournisseur répondant : non évaluable.
 */
final class GradingTodoRegistry
{
    public const PREFIX = 'portals.grading_todo.';

    public function __construct(private readonly Container $container)
    {
    }

    /** @return list<array{assessment_id: string, label: string, establishment_id: string, structure_id: ?string, missing_grades: int}>|null null = non évaluable */
    public function pendingFor(string $tenantId, string $teacherPersonId): ?array
    {
        $answered = false;
        $items = [];
        $names = $this->container->getKnownEntryNames();
        sort($names);
        foreach ($names as $name) {
            if (!str_starts_with($name, self::PREFIX)) {
                continue;
            }
            foreach ((array) $this->container->get($name) as $class) {
                $provider = is_string($class) ? $this->container->get($class) : $class;
                if (!$provider instanceof GradingTodo) {
                    continue;
                }
                $pending = $provider->pendingFor($tenantId, $teacherPersonId);
                if ($pending !== null) {
                    $answered = true;
                    array_push($items, ...$pending);
                }
            }
        }

        return $answered ? $items : null;
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Application;

use Scholaris\Modules\Socle\Identity\Contracts\TodoProvider as IdentityTodoProvider;
use Scholaris\Modules\Socle\Reporting\Contracts\TodoProvider;
use Throwable;

/** Agrège toutes les sources « à traiter » enregistrées ; GET /me/todo (route d'Identity) s'appuie sur ce service. */
final class TodoAggregator implements IdentityTodoProvider
{
    public function __construct(private readonly ProviderRegistry $registry)
    {
    }

    public function forUser(string $tenantId, string $userId): array
    {
        $items = [];
        foreach ($this->registry->resolve(ProviderRegistry::TODO, TodoProvider::class) as $source) {
            try {
                array_push($items, ...$source->forUser($tenantId, $userId));
            } catch (Throwable) {
                continue; // une source défaillante ne vide pas la liste des autres
            }
        }
        usort($items, static function (array $a, array $b): int {
            $left = $a['due_at'] ?? null;
            $right = $b['due_at'] ?? null;
            if ($left === $right) {
                return 0;
            }

            return $left === null ? 1 : ($right === null ? -1 : strcmp((string) $left, (string) $right));
        });

        return $items;
    }
}

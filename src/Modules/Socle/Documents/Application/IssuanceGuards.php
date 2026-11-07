<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Application;

use DI\Container;
use Scholaris\Modules\Socle\Documents\Contracts\IssuanceContext;
use Scholaris\Modules\Socle\Documents\Contracts\IssuanceGuard;

/** Gardes d'émission déclarées dans le conteneur sous `documents.issuance_guards.*` (S18), appelées dans l'ordre des clés. */
final class IssuanceGuards
{
    public const PREFIX = 'documents.issuance_guards.';

    /** @var list<IssuanceGuard>|null */
    private ?array $guards = null;

    public function __construct(private readonly Container $container)
    {
    }

    /** @return string|null premier motif de refus, ou null si toutes les gardes laissent passer */
    public function refusal(IssuanceContext $context): ?string
    {
        foreach ($this->all() as $guard) {
            $reason = $guard->refusal($context);
            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    /** @return list<IssuanceGuard> */
    private function all(): array
    {
        if ($this->guards !== null) {
            return $this->guards;
        }
        $found = [];
        $names = $this->container->getKnownEntryNames();
        sort($names);
        foreach ($names as $name) {
            if (!str_starts_with($name, self::PREFIX)) {
                continue;
            }
            foreach ((array) $this->container->get($name) as $entry) {
                $guard = is_string($entry) ? $this->container->get($entry) : $entry;
                if ($guard instanceof IssuanceGuard) {
                    $found[$guard::class] = $guard;
                }
            }
        }

        return $this->guards = array_values($found);
    }
}

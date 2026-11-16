<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Application;

use DI\Container;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodUsage;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodUse;

/** Découvre les fournisseurs d'usages de période (entrées de liste `organisation.period_usage.*`) et additionne leurs réponses. */
final class PeriodUsageProviders
{
    /** @var list<PeriodUsage>|null */
    private ?array $providers = null;

    public function __construct(private readonly Container $container)
    {
    }

    /** @return list<PeriodUse> usages non nuls de la période, dans l'ordre des fournisseurs */
    public function uses(string $tenantId, string $establishmentId, string $periodId): array
    {
        $uses = [];
        foreach ($this->providers() as $provider) {
            foreach ($provider->uses($tenantId, $establishmentId, $periodId) as $use) {
                if ($use->count > 0) {
                    $uses[] = $use;
                }
            }
        }

        return $uses;
    }

    /** @return list<PeriodUsage> */
    private function providers(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }
        $found = [];
        $names = $this->container->getKnownEntryNames();
        sort($names);
        foreach ($names as $name) {
            if (!str_starts_with($name, PeriodUsage::PREFIX)) {
                continue;
            }
            foreach ((array) $this->container->get($name) as $entry) {
                $provider = is_string($entry) ? $this->container->get($entry) : $entry;
                if ($provider instanceof PeriodUsage) {
                    $found[$provider::class] = $provider;
                }
            }
        }

        return $this->providers = array_values($found);
    }
}

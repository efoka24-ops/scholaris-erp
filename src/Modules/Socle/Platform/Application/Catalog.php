<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use DI\Container;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;
use Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb;

/**
 * Catalogue d'applications : lignes de platform_applications (statut, tarif, essai, grâce) complétées par les manifestes
 * déclarés dans le conteneur (`platform.applications.<clé>`), qui font foi pour la cible et les modules requis.
 */
final class Catalog
{
    public const PREFIX = 'platform.applications.';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly PlatformDb $db,
        private readonly Container $container,
        private readonly PlatformClock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> applications triées par clé */
    public function all(): array
    {
        return array_values($this->load());
    }

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        return $this->load()[$key] ?? null;
    }

    /** @return array<string, mixed> */
    public function findOrFail(string $key): array
    {
        return $this->find($key) ?? throw HttpException::notFound('Application inconnue.');
    }

    /**
     * Modifie le commercial d'une application (statut, libellé, version, tarif, essai, délai de grâce).
     *
     * @param array<string, mixed> $changes déjà validées
     * @return array<string, mixed>
     */
    public function update(string $key, array $changes): array
    {
        $app = $this->findOrFail($key);
        $now = $this->clock->iso();
        $values = array_intersect_key($changes, array_flip(['label', 'status', 'version', 'monthly_price_xaf', 'trial_days', 'grace_days']));
        if ($this->db->one('SELECT app_key FROM platform_applications WHERE app_key = ?', [$key]) === null) {
            $this->db->insert('platform_applications', [
                'app_key' => $key,
                'label' => (string) ($values['label'] ?? $app['label']),
                'target_type' => (string) $app['target'],
                'status' => (string) ($values['status'] ?? $app['status']),
                'version' => (string) ($values['version'] ?? $app['version']),
                'required_modules' => json_encode($app['required_modules'], JSON_THROW_ON_ERROR),
                'monthly_price_xaf' => (int) ($values['monthly_price_xaf'] ?? $app['monthly_price_xaf']),
                'trial_days' => (int) ($values['trial_days'] ?? $app['trial_days']),
                'grace_days' => (int) ($values['grace_days'] ?? $app['grace_days']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } elseif ($values !== []) {
            /** @var array<string, scalar|null> $values */
            $this->db->update('platform_applications', $values + ['updated_at' => $now], ['app_key' => $key]);
        }
        $this->cache = null;

        return $this->findOrFail($key);
    }

    /** @return array<string, array<string, mixed>> */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $apps = [];
        foreach ($this->db->all('SELECT * FROM platform_applications ORDER BY app_key') as $row) {
            $modules = json_decode((string) $row['required_modules'], true);
            $apps[(string) $row['app_key']] = [
                'key' => (string) $row['app_key'],
                'label' => (string) $row['label'],
                'target' => (string) $row['target_type'],
                'status' => (string) $row['status'],
                'version' => (string) $row['version'],
                'required_modules' => is_array($modules) ? array_values(array_filter($modules, 'is_string')) : [],
                'monthly_price_xaf' => (int) $row['monthly_price_xaf'],
                'trial_days' => (int) $row['trial_days'],
                'grace_days' => (int) $row['grace_days'],
            ];
        }
        foreach ($this->manifests() as $manifest) {
            $known = $apps[$manifest->key] ?? null;
            $apps[$manifest->key] = [
                'key' => $manifest->key,
                'label' => $known['label'] ?? $manifest->label,
                'target' => $manifest->target,
                'status' => $known['status'] ?? $manifest->status,
                'version' => $manifest->version,
                'required_modules' => $manifest->requiredModules,
                'monthly_price_xaf' => $known['monthly_price_xaf'] ?? $manifest->monthlyPriceXaf,
                'trial_days' => $known['trial_days'] ?? $manifest->trialDays,
                'grace_days' => $known['grace_days'] ?? $manifest->graceDays,
            ];
        }
        ksort($apps);

        return $this->cache = $apps;
    }

    /** @return list<ApplicationManifest> */
    private function manifests(): array
    {
        $found = [];
        $names = $this->container->getKnownEntryNames();
        sort($names);
        foreach ($names as $name) {
            if (!str_starts_with($name, self::PREFIX)) {
                continue;
            }
            $value = $this->container->get($name);
            foreach (is_array($value) ? $value : [$value] as $manifest) {
                if ($manifest instanceof ApplicationManifest) {
                    $found[] = $manifest;
                }
            }
        }

        return $found;
    }
}

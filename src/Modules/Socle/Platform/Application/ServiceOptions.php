<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use PDOException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb;

/**
 * Options de service (modules activables : SMS, portail parents, import…) : catalogue géré par la plateforme et souscriptions
 * par client et par application. Le montant dû d'une application est son tarif de base (ou la surcharge du client) plus le
 * prix des options souscrites (prix de l'option, ou prix propre au client). Toute requête client porte `tenant_id = ?`.
 */
final class ServiceOptions
{
    public function __construct(
        private readonly PlatformDb $db,
        private readonly PlatformAudit $audit,
        private readonly PlatformClock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_map(fn (array $row): array => $this->present($row), $this->db->all('SELECT * FROM platform_service_options ORDER BY option_key'));
    }

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        $row = $this->db->one('SELECT * FROM platform_service_options WHERE option_key = ?', [$key]);

        return $row === null ? null : $this->present($row);
    }

    /** @return array<string, mixed> */
    public function findOrFail(string $key): array
    {
        return $this->find($key) ?? throw HttpException::notFound('Option de service inconnue.');
    }

    /**
     * @param array{option_key: string, label_fr: string, label_en: string, monthly_price_xaf: int, app_keys: list<string>} $data
     * @return array<string, mixed>
     */
    public function create(array $data, string $actorId, ?string $ip): array
    {
        $now = $this->clock->iso();
        try {
            $this->db->insert('platform_service_options', [
                'option_key' => $data['option_key'],
                'label_fr' => $data['label_fr'],
                'label_en' => $data['label_en'],
                'monthly_price_xaf' => $data['monthly_price_xaf'],
                'app_keys' => json_encode($data['app_keys'], JSON_THROW_ON_ERROR),
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException) {
            throw HttpException::conflict('Une option de service porte déjà cette clé.');
        }
        $this->audit->record(null, $actorId, 'platform.service_option_created', 'service_option', $data['option_key'], $data, $ip);

        return $this->findOrFail($data['option_key']);
    }

    /**
     * @param array<string, mixed> $changes label_fr, label_en, monthly_price_xaf, app_keys, active (déjà validés)
     * @return array<string, mixed>
     */
    public function update(string $key, array $changes, string $actorId, ?string $ip): array
    {
        $before = $this->findOrFail($key);
        $values = array_intersect_key($changes, array_flip(['label_fr', 'label_en', 'monthly_price_xaf', 'app_keys', 'active']));
        if (isset($values['app_keys']) && is_array($values['app_keys'])) {
            $values['app_keys'] = json_encode(array_values($values['app_keys']), JSON_THROW_ON_ERROR);
        }
        if (isset($values['active'])) {
            $values['active'] = $values['active'] === true || $values['active'] === 1 ? 1 : 0;
        }
        if ($values !== []) {
            /** @var array<string, scalar|null> $values */
            $this->db->update('platform_service_options', $values + ['updated_at' => $this->clock->iso()], ['option_key' => $key]);
        }
        $after = $this->findOrFail($key);
        $this->audit->record(null, $actorId, 'platform.service_option_updated', 'service_option', $key, ['before' => $before, 'after' => $after], $ip);

        return $after;
    }

    public function delete(string $key, string $actorId, ?string $ip): void
    {
        $before = $this->findOrFail($key);
        if ((int) $this->db->scalar('SELECT COUNT(*) FROM tenant_service_options WHERE option_key = ?', [$key]) > 0) {
            throw HttpException::conflict('Cette option est souscrite par des clients : désactivez-la plutôt que de la supprimer.');
        }
        $this->db->pdo()->prepare('DELETE FROM platform_service_options WHERE option_key = ?')->execute([$key]);
        $this->audit->record(null, $actorId, 'platform.service_option_deleted', 'service_option', $key, ['before' => $before], $ip);
    }

    /**
     * Options souscrites par un client pour une application, avec le prix retenu.
     *
     * @return list<array{option_key: string, label_fr: string, label_en: string, price_xaf: int, default_price_xaf: int, price_overridden: bool}>
     */
    public function forApplication(string $tenantId, string $appKey): array
    {
        $rows = $this->db->all(
            'SELECT t.option_key AS option_key, t.price_xaf AS own_price, o.label_fr AS label_fr, o.label_en AS label_en, o.monthly_price_xaf AS default_price '
            . 'FROM tenant_service_options t JOIN platform_service_options o ON o.option_key = t.option_key '
            . 'WHERE t.tenant_id = ? AND t.app_key = ? ORDER BY t.option_key',
            [$tenantId, $appKey],
        );

        return array_map(static fn (array $row): array => [
            'option_key' => (string) $row['option_key'],
            'label_fr' => (string) $row['label_fr'],
            'label_en' => (string) $row['label_en'],
            'price_xaf' => $row['own_price'] === null ? (int) $row['default_price'] : (int) $row['own_price'],
            'default_price_xaf' => (int) $row['default_price'],
            'price_overridden' => $row['own_price'] !== null,
        ], $rows);
    }

    /** Somme mensuelle des options souscrites pour une application. */
    public function totalFor(string $tenantId, string $appKey): int
    {
        return array_sum(array_column($this->forApplication($tenantId, $appKey), 'price_xaf'));
    }

    /**
     * Souscrit (ou change le prix propre au client d'une option déjà souscrite). $priceXaf null : prix de l'option.
     *
     * @return list<array{option_key: string, label_fr: string, label_en: string, price_xaf: int, default_price_xaf: int, price_overridden: bool}>
     */
    public function subscribe(string $tenantId, string $appKey, string $optionKey, ?int $priceXaf, string $reason, string $actorId, ?string $ip): array
    {
        $option = $this->findOrFail($optionKey);
        $existing = $this->db->one('SELECT * FROM tenant_service_options WHERE tenant_id = ? AND app_key = ? AND option_key = ?', [$tenantId, $appKey, $optionKey]);
        if ($existing === null) {
            if ($option['active'] !== true) {
                throw HttpException::conflict('Cette option est désactivée : elle ne peut plus être souscrite.');
            }
            if ($option['app_keys'] !== [] && !in_array($appKey, $option['app_keys'], true)) {
                throw HttpException::validation(['option_key' => ['Cette option ne concerne pas cette application.']]);
            }
        }
        $now = $this->clock->iso();
        if ($existing === null) {
            $this->db->insert('tenant_service_options', [
                'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'app_key' => $appKey, 'option_key' => $optionKey,
                'price_xaf' => $priceXaf, 'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } else {
            $this->db->update('tenant_service_options', ['price_xaf' => $priceXaf, 'updated_at' => $now], ['tenant_id' => $tenantId, 'id' => (string) $existing['id']]);
        }
        $this->audit->record($tenantId, $actorId, $existing === null ? 'platform.service_option_subscribed' : 'platform.service_option_price_set', 'tenant_application', $appKey, [
            'app_key' => $appKey, 'option_key' => $optionKey, 'reason' => $reason,
            'before' => $existing === null ? null : ['price_xaf' => $existing['price_xaf'] === null ? null : (int) $existing['price_xaf']],
            'after' => ['price_xaf' => $priceXaf],
        ], $ip);

        return $this->forApplication($tenantId, $appKey);
    }

    /** @return list<array{option_key: string, label_fr: string, label_en: string, price_xaf: int, default_price_xaf: int, price_overridden: bool}> */
    public function unsubscribe(string $tenantId, string $appKey, string $optionKey, string $reason, string $actorId, ?string $ip): array
    {
        $existing = $this->db->one('SELECT * FROM tenant_service_options WHERE tenant_id = ? AND app_key = ? AND option_key = ?', [$tenantId, $appKey, $optionKey])
            ?? throw HttpException::notFound('Option non souscrite pour cette application.');
        $this->db->pdo()->prepare('DELETE FROM tenant_service_options WHERE tenant_id = ? AND id = ?')->execute([$tenantId, (string) $existing['id']]);
        $this->audit->record($tenantId, $actorId, 'platform.service_option_unsubscribed', 'tenant_application', $appKey, [
            'app_key' => $appKey, 'option_key' => $optionKey, 'reason' => $reason,
            'before' => ['price_xaf' => $existing['price_xaf'] === null ? null : (int) $existing['price_xaf']],
        ], $ip);

        return $this->forApplication($tenantId, $appKey);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $keys = json_decode((string) $row['app_keys'], true);

        return [
            'option_key' => (string) $row['option_key'],
            'label_fr' => (string) $row['label_fr'],
            'label_en' => (string) $row['label_en'],
            'monthly_price_xaf' => (int) $row['monthly_price_xaf'],
            'app_keys' => is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [],
            'active' => (int) $row['active'] === 1,
            'subscribers' => (int) $this->db->scalar('SELECT COUNT(*) FROM tenant_service_options WHERE option_key = ?', [(string) $row['option_key']]),
        ];
    }
}

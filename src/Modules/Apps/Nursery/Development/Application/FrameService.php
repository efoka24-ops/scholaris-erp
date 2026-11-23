<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Development\Application;

use PDO;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Paging;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Apps\Nursery\Support\Store;

/**
 * Référentiel d'observation (EX-MAT-16, MQ-08) : la plateforme livre un référentiel « exemple » à valider (lignes `platform`, jamais
 * modifiées) ; l'école le COPIE, l'adapte (domaines, items, libellés) puis l'ACTIVE. Un seul référentiel actif par école ; un
 * référentiel `official` ne s'active qu'avec une référence de validation. Aucune écriture sur une ligne `platform`.
 */
final class FrameService
{
    public const FRAMES = 'nu_dev_frames';
    public const DOMAINS = 'nu_dev_domains';
    public const ITEMS = 'nu_dev_items';
    private const PLATFORM = 'platform';

    public function __construct(private readonly Store $store, private readonly NurseryClock $clock, private readonly Access $access)
    {
    }

    /** @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}} */
    public function page(Perimeter $perimeter, Paging $paging): array
    {
        $rows = array_merge(
            $this->platformFrames(),
            $this->store->select(self::FRAMES, $perimeter->tenantId, $perimeter->establishmentId, [], 'frame_code, version, id', 200),
        );

        return $paging->slice(array_map(fn (array $row): array => $this->summary($row), $rows));
    }

    /** @return array<string, mixed> le référentiel avec ses domaines et items */
    public function get(Perimeter $perimeter, string $id): array
    {
        $frame = $this->find($perimeter->tenantId, $perimeter->establishmentId, $id);
        $owner = $this->owner($frame);
        $domains = $this->store->select(self::DOMAINS, $owner[0], $owner[1], ['frame_id' => $id], 'sort_order, id', 200);
        $items = $this->store->select(self::ITEMS, $owner[0], $owner[1], ['frame_id' => $id], 'sort_order, id', 1000);
        $resource = $this->summary($frame);
        $resource['domains'] = array_map(static function (array $domain) use ($items): array {
            return [
                'id' => (string) $domain['id'], 'code' => (string) $domain['code'], 'label_fr' => (string) $domain['label_fr'], 'label_en' => (string) $domain['label_en'], 'sort_order' => (int) $domain['sort_order'],
                'items' => array_values(array_map(static fn (array $item): array => [
                    'id' => (string) $item['id'], 'code' => (string) $item['code'], 'label_fr' => (string) $item['label_fr'], 'label_en' => (string) $item['label_en'],
                    'sort_order' => (int) $item['sort_order'], 'active' => (int) $item['active'] === 1,
                ], array_filter($items, static fn (array $item): bool => (string) $item['domain_id'] === (string) $domain['id']))),
            ];
        }, $domains);

        return $resource;
    }

    /** @return array<string, mixed> */
    public function copy(Principal $principal, Perimeter $perimeter, string $sourceId): array
    {
        $source = $this->find($perimeter->tenantId, $perimeter->establishmentId, $sourceId);
        [$sourceTenant, $sourceEstablishment] = $this->owner($source);
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        $latest = $this->store->select(self::FRAMES, $tenantId, $establishmentId, ['frame_code' => (string) $source['frame_code']], 'version DESC, id', 1)[0] ?? null;
        $newId = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->transaction(function () use ($source, $sourceTenant, $sourceEstablishment, $tenantId, $establishmentId, $latest, $newId, $now): void {
            $this->store->insert(self::FRAMES, $tenantId, $establishmentId, [
                'id' => $newId, 'scope' => 'tenant', 'origin' => 'custom', 'frame_code' => (string) $source['frame_code'], 'version' => $latest !== null ? (int) $latest['version'] + 1 : 1,
                'label_fr' => (string) $source['label_fr'], 'label_en' => (string) $source['label_en'], 'status' => 'draft', 'validation_ref' => null,
                'copied_from' => (string) $source['id'], 'created_at' => $now, 'activated_at' => null,
            ]);
            $domainIds = [];
            foreach ($this->store->select(self::DOMAINS, $sourceTenant, $sourceEstablishment, ['frame_id' => (string) $source['id']], 'sort_order, id', 200) as $domain) {
                $domainIds[(string) $domain['id']] = (string) new Ulid();
                $this->store->insert(self::DOMAINS, $tenantId, $establishmentId, [
                    'id' => $domainIds[(string) $domain['id']], 'frame_id' => $newId, 'code' => (string) $domain['code'], 'label_fr' => (string) $domain['label_fr'],
                    'label_en' => (string) $domain['label_en'], 'sort_order' => (int) $domain['sort_order'],
                ]);
            }
            foreach ($this->store->select(self::ITEMS, $sourceTenant, $sourceEstablishment, ['frame_id' => (string) $source['id']], 'sort_order, id', 1000) as $item) {
                $this->store->insert(self::ITEMS, $tenantId, $establishmentId, [
                    'id' => (string) new Ulid(), 'frame_id' => $newId, 'domain_id' => $domainIds[(string) $item['domain_id']], 'code' => (string) $item['code'],
                    'label_fr' => (string) $item['label_fr'], 'label_en' => (string) $item['label_en'], 'sort_order' => (int) $item['sort_order'], 'active' => (int) $item['active'],
                ]);
            }
        });
        $this->access->audit($principal, 'nursery.frame.copied', 'dev_frame', $newId, null, ['source' => $sourceId]);

        return $this->get($perimeter, $newId);
    }

    /**
     * @param array<string, mixed> $data code, label_fr, label_en, sort_order?
     * @return array<string, mixed>
     */
    public function addDomain(Principal $principal, Perimeter $perimeter, string $frameId, array $data): array
    {
        $this->editable($perimeter, $frameId);
        $id = (string) new Ulid();
        $this->store->insert(self::DOMAINS, $perimeter->tenantId, $perimeter->establishmentId, [
            'id' => $id, 'frame_id' => $frameId, 'code' => (string) $data['code'], 'label_fr' => (string) $data['label_fr'], 'label_en' => (string) $data['label_en'], 'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);
        $this->access->audit($principal, 'nursery.frame.domain_added', 'dev_frame', $frameId);

        return $this->get($perimeter, $frameId);
    }

    /**
     * @param array<string, mixed> $data domain_id, code, label_fr, label_en, sort_order?
     * @return array<string, mixed>
     */
    public function addItem(Principal $principal, Perimeter $perimeter, string $frameId, array $data): array
    {
        $this->editable($perimeter, $frameId);
        $this->store->findOrFail(self::DOMAINS, $perimeter->tenantId, $perimeter->establishmentId, (string) $data['domain_id'], 'Domaine introuvable dans ce référentiel.');
        $this->store->insert(self::ITEMS, $perimeter->tenantId, $perimeter->establishmentId, [
            'id' => (string) new Ulid(), 'frame_id' => $frameId, 'domain_id' => (string) $data['domain_id'], 'code' => (string) $data['code'],
            'label_fr' => (string) $data['label_fr'], 'label_en' => (string) $data['label_en'], 'sort_order' => (int) ($data['sort_order'] ?? 0), 'active' => 1,
        ]);
        $this->access->audit($principal, 'nursery.frame.item_added', 'dev_frame', $frameId);

        return $this->get($perimeter, $frameId);
    }

    /**
     * @param array<string, mixed> $changes label_fr?, label_en?, sort_order?, active?
     * @return array<string, mixed>
     */
    public function updateItem(Principal $principal, Perimeter $perimeter, string $frameId, string $itemId, array $changes): array
    {
        $this->editable($perimeter, $frameId);
        $item = $this->store->findOrFail(self::ITEMS, $perimeter->tenantId, $perimeter->establishmentId, $itemId, 'Item introuvable.');
        if ((string) $item['frame_id'] !== $frameId) {
            throw HttpException::notFound('Item introuvable.');
        }
        $values = [];
        foreach (['label_fr', 'label_en', 'sort_order'] as $field) {
            if (array_key_exists($field, $changes)) {
                $values[$field] = $changes[$field];
            }
        }
        if (array_key_exists('active', $changes)) {
            $values['active'] = $changes['active'] === true ? 1 : 0;
        }
        $this->store->update(self::ITEMS, $perimeter->tenantId, $perimeter->establishmentId, $values, ['id' => $itemId]);
        $this->access->audit($principal, 'nursery.frame.item_updated', 'dev_frame', $frameId, null, $changes);

        return $this->get($perimeter, $frameId);
    }

    /** @return array<string, mixed> */
    public function activate(Principal $principal, Perimeter $perimeter, string $frameId, ?string $validationRef): array
    {
        $frame = $this->store->find(self::FRAMES, $perimeter->tenantId, $perimeter->establishmentId, $frameId) ?? throw HttpException::notFound('Référentiel introuvable.');
        if ((string) $frame['status'] !== 'draft') {
            throw HttpException::conflict('Seul un brouillon peut être activé.');
        }
        if ($this->store->count(self::DOMAINS, $perimeter->tenantId, $perimeter->establishmentId, ['frame_id' => $frameId]) === 0 || $this->store->count(self::ITEMS, $perimeter->tenantId, $perimeter->establishmentId, ['frame_id' => $frameId, 'active' => 1]) === 0) {
            throw HttpException::validation(['frame' => ['Un référentiel actif a au moins un domaine et un item actif.']]);
        }
        if ((string) $frame['origin'] === 'official' && ($validationRef === null || $validationRef === '') && $frame['validation_ref'] === null) {
            throw HttpException::validation(['validation_ref' => ['Un référentiel officiel exige sa référence de validation.']]);
        }
        $this->store->transaction(function () use ($perimeter, $frameId, $validationRef): void {
            $this->store->update(self::FRAMES, $perimeter->tenantId, $perimeter->establishmentId, ['status' => 'archived'], ['scope' => 'tenant', 'status' => 'active']);
            $this->store->update(self::FRAMES, $perimeter->tenantId, $perimeter->establishmentId, ['status' => 'active', 'activated_at' => $this->clock->now(), 'validation_ref' => $validationRef], ['id' => $frameId]);
        });
        $this->access->audit($principal, 'nursery.frame.activated', 'dev_frame', $frameId);

        return $this->get($perimeter, $frameId);
    }

    /** @return array<string, mixed>|null le référentiel actif de l'école */
    public function active(string $tenantId, string $establishmentId): ?array
    {
        return $this->store->select(self::FRAMES, $tenantId, $establishmentId, ['status' => 'active'], 'version DESC, id', 1)[0] ?? null;
    }

    /**
     * Item du référentiel actif de l'école (observation), avec son domaine.
     *
     * @return array{item: array<string, mixed>, domain: array<string, mixed>, frame: array<string, mixed>}|null
     */
    public function activeItem(string $tenantId, string $establishmentId, string $itemId): ?array
    {
        $frame = $this->active($tenantId, $establishmentId);
        $item = $this->store->find(self::ITEMS, $tenantId, $establishmentId, $itemId);
        if ($frame === null || $item === null || (string) $item['frame_id'] !== (string) $frame['id'] || (int) $item['active'] !== 1) {
            return null;
        }
        $domain = $this->store->find(self::DOMAINS, $tenantId, $establishmentId, (string) $item['domain_id']);

        return $domain === null ? null : ['item' => $item, 'domain' => $domain, 'frame' => $frame];
    }

    /** @return list<array<string, mixed>> domaines du référentiel actif */
    public function activeDomains(string $tenantId, string $establishmentId): array
    {
        $frame = $this->active($tenantId, $establishmentId);

        return $frame === null ? [] : $this->store->select(self::DOMAINS, $tenantId, $establishmentId, ['frame_id' => (string) $frame['id']], 'sort_order, id', 200);
    }

    /** @return array<string, mixed> */
    private function find(string $tenantId, string $establishmentId, string $id): array
    {
        $own = $this->store->find(self::FRAMES, $tenantId, $establishmentId, $id);
        if ($own !== null) {
            return $own;
        }
        foreach ($this->platformFrames() as $frame) {
            if ((string) $frame['id'] === $id) {
                return $frame;
            }
        }

        throw HttpException::notFound('Référentiel introuvable.');
    }

    /** Un référentiel de l'école, en brouillon : seule configuration modifiable. */
    private function editable(Perimeter $perimeter, string $frameId): void
    {
        $frame = $this->store->find(self::FRAMES, $perimeter->tenantId, $perimeter->establishmentId, $frameId);
        if ($frame === null) {
            throw HttpException::notFound('Référentiel introuvable (les référentiels de la plateforme ne se modifient pas : copiez-le).');
        }
        if ((string) $frame['status'] !== 'draft') {
            throw HttpException::conflict('Un référentiel actif ou archivé ne se modifie plus : copiez-le pour en créer une nouvelle version.');
        }
    }

    /** @param array<string, mixed> $frame
     * @return array{0: string, 1: string}
     */
    private function owner(array $frame): array
    {
        return [(string) $frame['tenant_id'], (string) $frame['establishment_id']];
    }

    /** @return list<array<string, mixed>> */
    private function platformFrames(): array
    {
        $statement = $this->store->pdo()->prepare('SELECT * FROM nu_dev_frames WHERE tenant_id = ? AND establishment_id = ? AND scope = ? ORDER BY frame_code, version, id');
        $statement->execute([self::PLATFORM, self::PLATFORM, self::PLATFORM]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function summary(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'scope' => (string) $row['scope'], 'origin' => (string) $row['origin'], 'frame_code' => (string) $row['frame_code'], 'version' => (int) $row['version'],
            'label_fr' => (string) $row['label_fr'], 'label_en' => (string) $row['label_en'], 'status' => (string) $row['status'],
            'validation_ref' => $row['validation_ref'] !== null ? (string) $row['validation_ref'] : null,
            'to_validate' => (string) $row['origin'] === 'example',
        ];
    }
}

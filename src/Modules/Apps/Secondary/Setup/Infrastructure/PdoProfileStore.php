<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Infrastructure;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfile;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Stockage du profil d'établissement (`se_establishment_profiles`) et de son journal (`se_profile_changes`), client et
 * établissement toujours explicites (Store). Rien n'est jamais supprimé : un changement réécrit la ligne et ajoute une ligne de
 * journal avec son motif (D10, EX-SEC-04).
 */
final class PdoProfileStore
{
    public const TABLE = 'se_establishment_profiles';
    public const CHANGES = 'se_profile_changes';

    public function __construct(private readonly Store $store, private readonly SecondaryClock $clock)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $establishmentId): ?array
    {
        return $this->store->select(self::TABLE, $tenantId, $establishmentId, [], 'id', 1)[0] ?? null;
    }

    /**
     * Copie un profil (valeurs de départ du type, déjà ajustées par l'appelant) et journalise l'ouverture (`opened`). Un
     * établissement déjà ouvert répond 409 (index unique).
     */
    public function open(EstablishmentProfile $profile, string $ownership, string $reason, string $by): void
    {
        $now = $this->clock->now();
        $values = [
            'subtype' => $profile->typeCode,
            'profile_version' => $profile->profileVersion,
            'ownership' => $ownership,
            'sections' => self::encode($profile->sections),
            'cycles' => self::encode($profile->cycles),
            'teaching_kinds' => self::encode($profile->teachingKinds),
            'modules' => self::encode($profile->modules),
            'read_only_modules' => self::encode($profile->readOnlyModules),
            'head_title' => $profile->headTitle,
        ];
        $this->store->transaction(function () use ($profile, $values, $now, $reason, $by): void {
            $this->store->insert(self::TABLE, $profile->tenantId, $profile->establishmentId, ['id' => (string) new Ulid()] + $values + ['created_at' => $now, 'updated_at' => $now]);
            $this->log($profile->tenantId, $profile->establishmentId, 'opened', null, $values, $reason, $by);
        });
    }

    /**
     * Remplace la liste des modules actifs et des modules en lecture seule, avec une ligne de journal `modules`.
     *
     * @param list<string> $modules
     * @param list<string> $readOnly
     * @param array<string, mixed> $previous
     */
    public function saveModules(string $tenantId, string $establishmentId, array $modules, array $readOnly, array $previous, string $reason, string $by): void
    {
        $values = ['modules' => self::encode($modules), 'read_only_modules' => self::encode($readOnly)];
        $this->store->transaction(function () use ($tenantId, $establishmentId, $values, $previous, $reason, $by): void {
            $this->store->update(self::TABLE, $tenantId, $establishmentId, $values + ['updated_at' => $this->clock->now()], ['tenant_id' => $tenantId]);
            $this->log($tenantId, $establishmentId, 'modules', $previous, $values, $reason, $by);
        });
    }

    /** @return list<array<string, mixed>> journal de l'établissement, du plus ancien au plus récent */
    public function changes(string $tenantId, string $establishmentId): array
    {
        return $this->store->select(self::CHANGES, $tenantId, $establishmentId, [], 'created_at, id', 500);
    }

    /**
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed> $new
     */
    private function log(string $tenantId, string $establishmentId, string $kind, ?array $previous, array $new, string $reason, string $by): void
    {
        $this->store->insert(self::CHANGES, $tenantId, $establishmentId, [
            'id' => (string) new Ulid(),
            'change_kind' => $kind,
            'previous_value' => $previous === null ? null : json_encode($previous, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'new_value' => json_encode($new, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'reason' => $reason,
            'changed_by' => $by,
            'created_at' => $this->clock->now(),
        ]);
    }

    /** @param list<string> $values */
    private static function encode(array $values): string
    {
        return json_encode($values, JSON_THROW_ON_ERROR);
    }
}

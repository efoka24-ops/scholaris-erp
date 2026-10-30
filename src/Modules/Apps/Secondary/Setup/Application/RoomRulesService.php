<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\SchoolRules;
use Scholaris\Modules\Apps\Secondary\Setup\Domain\RuleKeys;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Profils de salles (EX-SEC-12 : salle, laboratoire, atelier avec capacité et équipements ; « atelier » seulement si le module
 * `workshops` est actif) et règlement intérieur versionné (EX-SEC-11, T822 : barème d'absences, âges d'admission, découpage de
 * l'année, distinctions, passage, recours, test d'entrée). La salle elle-même reste celle du socle (`/rooms`, identifiant opaque).
 */
final class RoomRulesService
{
    public const ROOM_KINDS = ['classroom', 'lab', 'workshop'];

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly EstablishmentProfiles $profiles,
        private readonly SchoolRules $rules,
        private readonly SecondaryClock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function rooms(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, StructureService::READ);

        return array_map(self::room(...), $this->store->select('se_room_profiles', $principal->tenantId, $establishmentId, [], 'label', 500));
    }

    /**
     * @param array<string, mixed> $body room_id, label, room_kind, capacity?, specialty_stream_id?, equipment?, safety_notes?
     * @return array<string, mixed>
     */
    public function saveRoom(Principal $principal, string $establishmentId, array $body): array
    {
        $this->writable($principal, $establishmentId);
        $tenantId = $principal->tenantId;
        $in = new Input($body);
        $roomId = (string) $in->reference('room_id');
        $label = (string) $in->string('label', 120);
        $kind = (string) $in->enum('room_kind', self::ROOM_KINDS);
        $capacity = $in->int('capacity', 1, 2000, false, true);
        $stream = $in->reference('specialty_stream_id', false, true);
        $equipment = $in->string('equipment', 2000, false, true);
        $safety = $in->string('safety_notes', 2000, false, true);
        $in->done();
        if ($kind === 'workshop' && !$this->profiles->forEstablishment($tenantId, $establishmentId)->isActive(Modules::WORKSHOPS)) {
            throw HttpException::validation(['room_kind' => ['Un atelier exige le module « ateliers » actif dans le profil de l\'établissement.']]);
        }
        if ($stream !== null && $this->store->find('se_streams', $tenantId, $establishmentId, $stream) === null) {
            throw HttpException::validation(['specialty_stream_id' => ['Spécialité non ouverte dans l\'établissement.']]);
        }
        $now = $this->clock->now();
        $values = ['label' => $label, 'room_kind' => $kind, 'capacity' => $capacity, 'specialty_stream_id' => $stream, 'equipment' => $equipment, 'safety_notes' => $safety, 'updated_at' => $now];
        $existing = $this->store->select('se_room_profiles', $tenantId, $establishmentId, ['room_id' => $roomId], 'id', 1)[0] ?? null;
        if ($existing === null) {
            $this->store->insert('se_room_profiles', $tenantId, $establishmentId, ['id' => (string) new Ulid(), 'room_id' => $roomId, 'created_at' => $now] + $values);
        } else {
            $this->store->update('se_room_profiles', $tenantId, $establishmentId, $values, ['id' => (string) $existing['id']]);
        }
        $this->access->audit($principal, 'secondary.room.saved', 'se_room_profile', $roomId, $existing, $values);
        $row = $this->store->select('se_room_profiles', $tenantId, $establishmentId, ['room_id' => $roomId], 'id', 1)[0] ?? throw HttpException::notFound();

        return self::room($row);
    }

    /** @return list<array<string, mixed>> règles de la liste fermée : valeur en vigueur, version, source (établissement, paramètre ou vide) */
    public function rules(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, StructureService::READ);
        $tenantId = $principal->tenantId;
        $result = [];
        foreach (array_keys(RuleKeys::ALL) as $key) {
            $row = $this->store->select('se_school_rules', $tenantId, $establishmentId, ['rule_key' => $key, 'superseded_at' => null], 'version_no DESC', 1)[0] ?? null;
            $value = $this->rules->value($tenantId, $establishmentId, $key);
            $result[] = [
                'key' => $key, 'shape' => RuleKeys::ALL[$key], 'value' => $value,
                'source' => $row !== null ? 'establishment' : ($value === null ? 'unset' : 'setting'),
                'confirmation' => $row !== null ? 'establishment' : 'to_confirm',
                'version_no' => $row === null ? null : (int) $row['version_no'], 'valid_from' => $row['valid_from'] ?? null,
                'change_reason' => $row['change_reason'] ?? null, 'changed_by' => $row['changed_by'] ?? null, 'changed_at' => $row['created_at'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $body value, valid_from, reason
     * @return array<string, mixed>
     */
    public function setRule(Principal $principal, string $establishmentId, string $key, array $body): array
    {
        $this->writable($principal, $establishmentId, ['secondary.settings.manage']);
        if (!RuleKeys::exists($key)) {
            throw HttpException::notFound('Règle inconnue.');
        }
        $in = new Input($body);
        $value = $in->custom('value', static function (mixed $v) use ($key): mixed {
            if (!RuleKeys::fits($key, $v)) {
                throw new \InvalidArgumentException('Forme attendue : ' . RuleKeys::ALL[$key] . '.');
            }

            return $v;
        });
        $validFrom = (string) $in->date('valid_from');
        $reason = (string) $in->string('reason', 255);
        $in->done();
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 20000) {
            throw HttpException::validation(['value' => ['Valeur trop volumineuse.']]);
        }
        $tenantId = $principal->tenantId;
        $now = $this->clock->now();
        $current = $this->store->select('se_school_rules', $tenantId, $establishmentId, ['rule_key' => $key, 'superseded_at' => null], 'version_no DESC', 1)[0] ?? null;
        $this->store->transaction(function () use ($tenantId, $establishmentId, $current, $key, $json, $validFrom, $reason, $principal, $now): void {
            if ($current !== null) {
                $this->store->update('se_school_rules', $tenantId, $establishmentId, ['superseded_at' => $now], ['id' => (string) $current['id']]);
            }
            $this->store->insert('se_school_rules', $tenantId, $establishmentId, [
                'id' => (string) new Ulid(), 'rule_key' => $key, 'value_json' => $json, 'version_no' => $current === null ? 1 : (int) $current['version_no'] + 1,
                'valid_from' => $validFrom, 'change_reason' => $reason, 'changed_by' => $principal->userId, 'superseded_at' => null, 'created_at' => $now,
            ]);
        });
        $this->access->audit($principal, 'secondary.rule.versioned', 'se_school_rule', $key, $current, ['value' => $value, 'reason' => $reason]);
        foreach ($this->rules($principal, $establishmentId) as $rule) {
            if ($rule['key'] === $key) {
                return $rule;
            }
        }

        throw HttpException::notFound();
    }

    /** @param list<string> $permissions */
    private function writable(Principal $principal, string $establishmentId, array $permissions = StructureService::WRITE): void
    {
        $perimeter = $this->access->require($principal, $establishmentId, $permissions);
        $this->access->assertWhole($principal, $perimeter, 'se_setup');
        if (!$this->profiles->forEstablishment($principal->tenantId, $establishmentId)->stored) {
            throw HttpException::conflict('Ouvrez d\'abord l\'application pour cet établissement (profil à confirmer).');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function room(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'room_id' => (string) $row['room_id'], 'label' => (string) $row['label'], 'room_kind' => (string) $row['room_kind'],
            'capacity' => $row['capacity'] === null ? null : (int) $row['capacity'], 'specialty_stream_id' => $row['specialty_stream_id'],
            'equipment' => $row['equipment'], 'safety_notes' => $row['safety_notes'],
        ];
    }
}

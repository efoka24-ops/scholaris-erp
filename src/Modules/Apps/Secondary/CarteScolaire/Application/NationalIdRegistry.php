<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\CarteScolaire\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\ExamFeeStatus;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\NationalIds;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/** Registre des matricules (`se_cs_students`) et statut des frais d'examen constatés (`se_cs_payments`). */
final class NationalIdRegistry implements NationalIds, ExamFeeStatus
{
    public const PENDING = 'pending';
    public const RECORDED = 'recorded';
    public const MISMATCH = 'mismatch';

    public function __construct(
        private readonly Store $store,
        private readonly SecondaryClock $clock,
        private readonly CarteScolaireSettings $settings,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function row(string $tenantId, string $establishmentId, string $personId): ?array
    {
        return $this->store->select('se_cs_students', $tenantId, $establishmentId, ['person_id' => $personId], 'id', 1)[0] ?? null;
    }

    /** @return array<string, array<string, mixed>> lignes par identifiant de personne */
    public function all(string $tenantId, string $establishmentId): array
    {
        $rows = [];
        foreach ($this->store->select('se_cs_students', $tenantId, $establishmentId, [], 'id', 100000) as $row) {
            $rows[(string) $row['person_id']] = $row;
        }

        return $rows;
    }

    public function of(string $tenantId, string $establishmentId, string $personId): ?string
    {
        $value = $this->row($tenantId, $establishmentId, $personId)['national_id'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function statusOf(string $tenantId, string $establishmentId, string $personId): string
    {
        $row = $this->row($tenantId, $establishmentId, $personId);

        return $row === null ? self::PENDING : (string) $row['id_status'];
    }

    public function personIdByNationalId(string $tenantId, string $establishmentId, string $nationalId): ?string
    {
        $row = $this->store->select('se_cs_students', $tenantId, $establishmentId, ['national_id' => self::clean($nationalId)], 'id', 1)[0] ?? null;

        return $row === null ? null : (string) $row['person_id'];
    }

    public function normalize(string $tenantId, string $establishmentId, string $nationalId): ?string
    {
        $clean = self::clean($nationalId);

        return $clean !== '' && preg_match($this->settings->nationalIdRegex($tenantId, $establishmentId), $clean) === 1 ? $clean : null;
    }

    public function record(string $tenantId, string $establishmentId, string $personId, string $nationalId, string $origin, string $actorId): string
    {
        $clean = $this->normalize($tenantId, $establishmentId, $nationalId);
        if ($clean === null) {
            throw HttpException::validation(['national_id' => ['Format de matricule non reconnu (paramètre secondary.carte_scolaire.national_id_pattern : ' . $this->settings->pattern($tenantId, $establishmentId) . ').']]);
        }
        $owner = $this->personIdByNationalId($tenantId, $establishmentId, $clean);
        if ($owner !== null && $owner !== $personId) {
            throw HttpException::conflict('Ce matricule est déjà attribué à un autre élève de l\'établissement.');
        }

        return $this->store->transaction(function () use ($tenantId, $establishmentId, $personId, $clean, $origin, $actorId): string {
            $now = $this->clock->now();
            $values = ['national_id' => $clean, 'id_status' => self::RECORDED, 'origin' => $origin, 'recorded_at' => $now, 'recorded_by' => $actorId, 'updated_at' => $now];
            if ($this->row($tenantId, $establishmentId, $personId) === null) {
                $this->store->insert('se_cs_students', $tenantId, $establishmentId, ['id' => (string) new Ulid(), 'person_id' => $personId, 'created_at' => $now] + $values);
            } else {
                $this->store->update('se_cs_students', $tenantId, $establishmentId, $values, ['person_id' => $personId]);
            }
            $this->store->update('se_cs_payments', $tenantId, $establishmentId, ['matched_person_id' => $personId, 'match_status' => 'matched'], ['national_id' => $clean, 'match_status' => 'unknown']);

            return $clean;
        });
    }

    /** Écart d'identité signalé : le matricule enregistré est conservé jusqu'à la correction par la scolarité (RG-CSC-2). */
    public function markMismatch(string $tenantId, string $establishmentId, string $personId): void
    {
        $now = $this->clock->now();
        if ($this->row($tenantId, $establishmentId, $personId) === null) {
            $this->store->insert('se_cs_students', $tenantId, $establishmentId, [
                'id' => (string) new Ulid(), 'person_id' => $personId, 'national_id' => null, 'id_status' => self::MISMATCH, 'origin' => 'import',
                'created_at' => $now, 'updated_at' => $now,
            ]);

            return;
        }
        $this->store->update('se_cs_students', $tenantId, $establishmentId, ['id_status' => self::MISMATCH, 'updated_at' => $now], ['person_id' => $personId]);
    }

    public function seen(string $tenantId, string $establishmentId, string $personId, ?string $examCode = null): bool
    {
        $where = ['matched_person_id' => $personId, 'payment_kind' => 'exam_fee', 'match_status' => 'matched'];
        if ($examCode !== null) {
            $where['exam_code'] = $examCode;
        }

        return $this->store->count('se_cs_payments', $tenantId, $establishmentId, $where) > 0;
    }

    public static function clean(string $nationalId): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $nationalId));
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Staff\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Heures mensuelles du personnel non enseignant (E3-EF2, module payroll_simple) : ses heures ne viennent pas de l'appel ; l'intendant
 * saisit un total par mois (déclaré), le chef d'établissement le valide (jamais celui qui l'a saisi). Un enseignant est refusé : ses
 * heures viennent de l'appel et du cahier de textes. Une heure validée ne se ressaisit plus.
 */
final class MonthlyHoursService
{
    public const TABLE = 'se_staff_monthly_hours';
    private const MONTH = '/^\d{4}-(0[1-9]|1[0-2])$/';
    /** Plafond de saisie d'un mois (31 jours de 24 heures) : refus d'une valeur absurde, pas une règle de travail. */
    private const MAX_MINUTES = 44640;

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly StaffService $staff,
        private readonly SecondaryClock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(Principal $principal, string $establishmentId, string $month): array
    {
        $this->access->require($principal, $establishmentId, StaffAccess::PAYROLL_READ);
        if (preg_match(self::MONTH, $month) !== 1) {
            throw HttpException::validation(['month' => ['Mois au format AAAA-MM attendu.']]);
        }

        return array_map(self::resource(...), $this->store->select(self::TABLE, $principal->tenantId, $establishmentId, ['month_ref' => $month], 'staff_profile_id', 5000));
    }

    /**
     * @param array<string, mixed> $body staff_profile_id, month_ref, minutes, note?
     * @return array<string, mixed>
     */
    public function declare(Principal $principal, string $establishmentId, array $body): array
    {
        $this->access->require($principal, $establishmentId, StaffAccess::PAYROLL_PREPARE);
        $input = new Input($body);
        $profileId = (string) $input->reference('staff_profile_id');
        $month = (string) $input->custom('month_ref', static function (mixed $value): string {
            if (!is_string($value) || preg_match(self::MONTH, $value) !== 1) {
                throw new \InvalidArgumentException('Mois au format AAAA-MM attendu.');
            }

            return $value;
        });
        $minutes = (int) $input->int('minutes', 0, self::MAX_MINUTES);
        $note = $input->string('note', 255, false, true);
        $input->done();
        $profile = $this->staff->activeProfile($principal->tenantId, $establishmentId, $profileId);
        if ($profile['teaches'] === true) {
            throw HttpException::conflict('Les heures d\'un enseignant viennent de l\'appel et du cahier de textes, pas d\'une saisie mensuelle.');
        }
        $existing = $this->store->select(self::TABLE, $principal->tenantId, $establishmentId, ['staff_profile_id' => $profileId, 'month_ref' => $month], 'id', 1)[0] ?? null;
        if ($existing !== null && $existing['status'] !== 'declared') {
            throw HttpException::conflict('Les heures de ce mois sont déjà validées.');
        }
        $now = $this->clock->now();
        $values = ['minutes' => $minutes, 'note' => $note, 'status' => 'declared', 'declared_by' => $principal->userId, 'validated_by' => null, 'validated_at' => null, 'updated_at' => $now];
        if ($existing === null) {
            $id = (string) new Ulid();
            $this->store->insert(self::TABLE, $principal->tenantId, $establishmentId, $values + ['id' => $id, 'staff_profile_id' => $profileId, 'month_ref' => $month, 'created_at' => $now]);
            $row = $values + ['id' => $id, 'staff_profile_id' => $profileId, 'month_ref' => $month];
        } else {
            $id = (string) $existing['id'];
            $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, $values, ['id' => $id]);
            $row = array_replace($existing, $values);
        }
        $this->access->audit($principal, 'secondary.staff.monthly_hours_declared', self::TABLE, $id, $existing === null ? null : ['minutes' => $existing['minutes']], ['staff_profile_id' => $profileId, 'month_ref' => $month, 'minutes' => $minutes]);

        return self::resource($row);
    }

    /** @return array<string, mixed> */
    public function validate(Principal $principal, string $establishmentId, string $id): array
    {
        $this->access->require($principal, $establishmentId, StaffAccess::PAYROLL_VALIDATE);
        $row = $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id, 'Heures introuvables.');
        if ($row['status'] !== 'declared') {
            throw HttpException::conflict('Ces heures sont déjà validées.');
        }
        if ($row['declared_by'] === $principal->userId) {
            throw HttpException::forbidden('Les heures sont validées par une autre personne que celle qui les a saisies.');
        }
        $now = $this->clock->now();
        $values = ['status' => 'validated', 'validated_by' => $principal->userId, 'validated_at' => $now, 'updated_at' => $now];
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, $values, ['id' => $id]);
        $this->access->audit($principal, 'secondary.staff.monthly_hours_validated', self::TABLE, $id, ['status' => 'declared'], $values);

        return self::resource(array_replace($row, $values));
    }

    /** Minutes validées d'un membre pour un mois, null si rien n'est validé. */
    public function validatedMinutes(string $tenantId, string $establishmentId, string $profileId, string $month): ?int
    {
        $row = $this->store->select(self::TABLE, $tenantId, $establishmentId, ['staff_profile_id' => $profileId, 'month_ref' => $month, 'status' => 'validated'], 'id', 1)[0] ?? null;

        return $row === null ? null : (int) $row['minutes'];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'staff_profile_id' => (string) $row['staff_profile_id'], 'month_ref' => (string) $row['month_ref'],
            'minutes' => (int) $row['minutes'], 'note' => $row['note'] === null ? null : (string) $row['note'], 'status' => (string) $row['status'],
            'declared_by' => (string) $row['declared_by'], 'validated_by' => $row['validated_by'] === null ? null : (string) $row['validated_by'],
            'validated_at' => $row['validated_at'] === null ? null : (string) $row['validated_at'],
        ];
    }
}

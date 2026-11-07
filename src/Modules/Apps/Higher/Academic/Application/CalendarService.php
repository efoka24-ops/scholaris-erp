<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;

/**
 * Calendrier académique par école et par année (EX-ACA-06) : semestres, périodes d'examens, rattrapage, délibérations, fériés,
 * période d'inscription. Écriture : directeur académique (tout l'établissement) ou directeur d'école (son école).
 */
final class CalendarService
{
    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly AcademicClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $v): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, Permissions::CALENDAR);
        $school = $this->store->find('hi_schools', $tenantId, $establishmentId, (string) $v['school_id'])
            ?? throw HttpException::validation(['school_id' => ['École introuvable dans cet établissement.']]);
        $this->access->assertWritable($principal, $perimeter, ['school' => (string) $school['id']], 'higher_calendar_entry', (string) $school['id']);
        self::check($v);
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert('hi_calendar_entries', $tenantId, $establishmentId, [
            'id' => $id, 'school_id' => $school['id'], 'academic_year_id' => $v['academic_year_id'], 'entry_kind' => $v['entry_kind'],
            'label' => $v['label'], 'starts_on' => $v['starts_on'], 'ends_on' => $v['ends_on'], 'semester_ordinal' => $v['semester_ordinal'] ?? null,
            'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $row = $this->store->findOrFail('hi_calendar_entries', $tenantId, $establishmentId, $id);
        $this->access->audit($principal, 'higher.calendar.created', 'higher_calendar_entry', $id, null, $row);

        return $row;
    }

    /**
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     */
    public function update(Principal $principal, string $establishmentId, string $id, array $v): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->writable($principal, $establishmentId, $id);
        self::check($v + $row);
        /** @var array<string, scalar|null> $changes */
        $changes = array_filter($v, static fn (mixed $value): bool => is_scalar($value) || $value === null);
        if ($changes === []) {
            return $row;
        }
        $this->store->update('hi_calendar_entries', $tenantId, $establishmentId, $changes + ['updated_at' => $this->clock->now()], ['id' => $id]);
        $after = $this->store->findOrFail('hi_calendar_entries', $tenantId, $establishmentId, $id);
        $this->access->audit($principal, 'higher.calendar.updated', 'higher_calendar_entry', $id, $row, $after);

        return $after;
    }

    public function delete(Principal $principal, string $establishmentId, string $id): void
    {
        $row = $this->writable($principal, $establishmentId, $id);
        $this->store->delete('hi_calendar_entries', $principal->tenantId, $establishmentId, ['id' => $id]);
        $this->access->audit($principal, 'higher.calendar.deleted', 'higher_calendar_entry', $id, $row, null);
    }

    /** @return array<string, mixed> */
    private function writable(Principal $principal, string $establishmentId, string $id): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, Permissions::CALENDAR);
        $row = $this->store->findOrFail('hi_calendar_entries', $principal->tenantId, $establishmentId, $id, 'Entrée de calendrier introuvable.');
        $this->access->assertWritable($principal, $perimeter, ['school' => (string) $row['school_id']], 'higher_calendar_entry', $id);

        return $row;
    }

    /** @param array<string, mixed> $v */
    private static function check(array $v): void
    {
        $errors = [];
        if (isset($v['starts_on'], $v['ends_on']) && $v['ends_on'] < $v['starts_on']) {
            $errors['ends_on'] = ['La fin ne peut pas précéder le début.'];
        }
        if (($v['entry_kind'] ?? null) === 'semester' && ($v['semester_ordinal'] ?? null) === null) {
            $errors['semester_ordinal'] = ['Un semestre porte son numéro (1 à 12).'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
    }
}

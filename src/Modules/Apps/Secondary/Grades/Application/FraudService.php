<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Grades\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Fraude à une évaluation (RG-NOT-3) : la note vaut zéro par le code `fraud` du socle ; l'enseignant saisit ici le surveillant
 * général, qui traite la saisine (motif écrit). Aucune sanction n'est appliquée par l'application (RG-PRE-2).
 */
final class FraudService
{
    public const TABLE = 'se_grade_fraud_reports';
    public const HANDLE = ['secondary.discipline.manage', 'secondary.discipline.validate'];
    public const LIST = ['secondary.discipline.manage', 'secondary.discipline.validate', 'secondary.grades.validate', 'secondary.grades.lock'];

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly SheetService $sheets,
        private readonly SecondaryClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $data class_id, subject_id, assessment_id, person_id, period_id?, note?
     * @return array<string, mixed>
     */
    public function report(Principal $principal, string $establishmentId, array $data): array
    {
        $this->sheets->entryCheck($principal, $establishmentId, (string) $data['class_id'], (string) $data['subject_id']);
        $id = (string) new Ulid();
        $this->store->insert(self::TABLE, $principal->tenantId, $establishmentId, [
            'id' => $id, 'class_id' => $data['class_id'], 'subject_id' => $data['subject_id'], 'period_id' => $data['period_id'] ?? null,
            'assessment_id' => $data['assessment_id'], 'person_id' => $data['person_id'], 'reported_by' => $principal->userId,
            'note_text' => $data['note'] ?? null, 'status' => 'open', 'handled_by' => null, 'handled_at' => null, 'outcome_text' => null,
            'created_at' => $this->clock->now(),
        ]);
        $this->access->audit($principal, 'secondary.grades.fraud_reported', self::TABLE, $id, null, ['person_id' => $data['person_id'], 'assessment_id' => $data['assessment_id']]);

        return self::resource($this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id));
    }

    public function assertCanList(Principal $principal, string $establishmentId): void
    {
        $this->access->require($principal, $establishmentId, self::LIST);
    }

    /**
     * @param array<string, mixed> $data outcome
     * @return array<string, mixed>
     */
    public function handle(Principal $principal, string $establishmentId, string $id, array $data): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::HANDLE);
        $row = $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id, 'Saisine introuvable.');
        $this->access->assertWritableClass($principal, $perimeter, (string) $row['class_id'], self::TABLE, $id);
        if ($row['status'] !== 'open') {
            throw HttpException::conflict('Cette saisine est déjà traitée.');
        }
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, [
            'status' => 'handled', 'handled_by' => $principal->userId, 'handled_at' => $this->clock->now(), 'outcome_text' => $data['outcome'],
        ], ['id' => $id]);
        $this->access->audit($principal, 'secondary.grades.fraud_handled', self::TABLE, $id, ['status' => 'open'], ['status' => 'handled']);

        return self::resource($this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        return [
            'id' => $row['id'], 'class_id' => $row['class_id'], 'subject_id' => $row['subject_id'], 'period_id' => $row['period_id'],
            'assessment_id' => $row['assessment_id'], 'person_id' => $row['person_id'], 'reported_by' => $row['reported_by'],
            'note' => $row['note_text'], 'status' => $row['status'], 'handled_by' => $row['handled_by'], 'handled_at' => $row['handled_at'],
            'outcome' => $row['outcome_text'], 'created_at' => $row['created_at'],
        ];
    }
}

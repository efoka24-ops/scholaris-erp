<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Dashboards\Application;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Listing;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\StructureEnrollments;

/**
 * Registre des réclamations sur les résultats (source de l'objectif O1) : la réclamation d'un élève est enregistrée par le censeur ou
 * le chef d'établissement sur une classe de leur périmètre, puis close avec une issue. Rien n'est supprimé ; chaque écriture est
 * auditée. Lecture : 404 hors périmètre.
 */
final class ClaimRegister
{
    public const TABLE = 'se_result_claims';
    public const READ = ['secondary.dashboard.read', 'secondary.grades.validate', 'secondary.settings.manage'];
    public const WRITE = ['secondary.grades.validate', 'secondary.settings.manage'];
    public const OPEN = 'open';
    public const CLOSED = 'closed';
    public const CALCULATION_ERROR = 'calculation_error';
    public const OUTCOMES = [self::CALCULATION_ERROR, 'other_error', 'unfounded'];

    public function __construct(
        private readonly Store $store,
        private readonly Listing $listing,
        private readonly Access $access,
        private readonly ClassProfiles $classes,
        private readonly StructureEnrollments $enrollments,
        private readonly SecondaryClock $clock,
    ) {
    }

    /** @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}} */
    public function list(Principal $principal, string $establishmentId, ServerRequestInterface $request): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $scope = ['1 = 1', []];
        if (!$perimeter->wholeEstablishment) {
            $scope = ['class_id IN (' . implode(', ', array_fill(0, count($perimeter->classIds), '?')) . ')', $perimeter->classIds];
        }

        return $this->listing->page($request, self::TABLE, $principal->tenantId, $establishmentId, ['academic_year_id', 'class_id', 'status', 'outcome'], ['received_on', 'created_at'], 'received_on DESC', self::resource(...), [], $scope);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function record(Principal $principal, string $establishmentId, array $body): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::WRITE);
        $input = new Input($body);
        $year = (string) $input->reference('academic_year_id');
        $period = $input->reference('period_id', false, true);
        $classId = (string) $input->reference('class_id');
        $personId = (string) $input->reference('person_id');
        $subject = $input->string('subject_label', 160, false, true);
        $text = (string) $input->string('claim_text', 500);
        $received = $input->date('received_on', false) ?? $this->clock->today();
        $input->done();
        $class = $this->classes->find($principal->tenantId, $establishmentId, $classId);
        if ($class === null || $class->academicYearId !== $year) {
            throw HttpException::validation(['class_id' => ['Classe inconnue pour cette année dans l\'établissement.']]);
        }
        $this->access->assertWritableClass($principal, $perimeter, $classId, 'secondary_result_claim', $classId);
        if (!$this->enrolled($principal->tenantId, $establishmentId, $classId, $year, $personId)) {
            throw HttpException::validation(['person_id' => ['Cet élève n\'est pas inscrit dans cette classe pour l\'année.']]);
        }
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert(self::TABLE, $principal->tenantId, $establishmentId, [
            'id' => $id, 'academic_year_id' => $year, 'period_id' => $period, 'class_id' => $classId, 'person_id' => $personId,
            'subject_label' => $subject === null ? null : trim($subject), 'claim_text' => trim($text), 'received_on' => $received, 'status' => self::OPEN,
            'recorded_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'secondary.result_claim.recorded', 'secondary_result_claim', $id, null, ['class_id' => $classId, 'period_id' => $period]);

        return self::resource($this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function close(Principal $principal, string $establishmentId, string $id, array $body): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::WRITE);
        $claim = $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id, 'Réclamation introuvable.');
        $this->access->assertReadableClass($perimeter, (string) $claim['class_id']);
        $input = new Input($body);
        $outcome = (string) $input->enum('outcome', self::OUTCOMES);
        $note = (string) $input->string('outcome_note', 500);
        $input->done();
        if ($claim['status'] !== self::OPEN) {
            throw HttpException::conflict('Cette réclamation est déjà close.');
        }
        $this->access->assertWritableClass($principal, $perimeter, (string) $claim['class_id'], 'secondary_result_claim', $id);
        $now = $this->clock->now();
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, [
            'status' => self::CLOSED, 'outcome' => $outcome, 'outcome_note' => trim($note), 'closed_by' => $principal->userId, 'closed_at' => $now, 'updated_at' => $now,
        ], ['id' => $id]);
        $this->access->audit($principal, 'secondary.result_claim.closed', 'secondary_result_claim', $id, ['status' => self::OPEN], ['status' => self::CLOSED, 'outcome' => $outcome]);

        return self::resource($this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id));
    }

    /**
     * Comptes de l'année, éventuellement limités à des classes (O1).
     *
     * @param list<string>|null $classIds
     * @return array{total: int, open: int, calculation_error: int, other_error: int, unfounded: int}
     */
    public function counts(string $tenantId, string $establishmentId, string $academicYearId, ?array $classIds = null): array
    {
        $out = ['total' => 0, 'open' => 0, 'calculation_error' => 0, 'other_error' => 0, 'unfounded' => 0];
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['academic_year_id' => $academicYearId], 'id', 100000) as $row) {
            if ($classIds !== null && !in_array((string) $row['class_id'], $classIds, true)) {
                continue;
            }
            ++$out['total'];
            if ($row['status'] === self::OPEN) {
                ++$out['open'];
            } elseif (is_string($row['outcome']) && isset($out[$row['outcome']]) && $row['outcome'] !== 'total' && $row['outcome'] !== 'open') {
                ++$out[$row['outcome']];
            }
        }

        return $out;
    }

    private function enrolled(string $tenantId, string $establishmentId, string $classId, string $year, string $personId): bool
    {
        foreach ($this->enrollments->inStructure($tenantId, $classId) as $enrollment) {
            if ($enrollment->personId === $personId && $enrollment->establishmentId === $establishmentId && $enrollment->academicYearId === $year
                && in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function resource(array $row): array
    {
        $keys = ['id', 'academic_year_id', 'period_id', 'class_id', 'person_id', 'subject_label', 'claim_text', 'received_on', 'status', 'outcome', 'outcome_note', 'recorded_by', 'closed_by', 'closed_at', 'created_at'];

        return array_intersect_key($row, array_flip($keys));
    }
}

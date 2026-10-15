<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Staff\Contracts\AssignmentDraft;
use Scholaris\Modules\Socle\Staff\Contracts\AssignmentInfo;
use Scholaris\Modules\Socle\Staff\Contracts\AssignmentWriter;
use Scholaris\Modules\Socle\Staff\Domain\SessionKind;
use Scholaris\Modules\Socle\Staff\Http\Requests\Input;

/** Contrat d'écriture des affectations (D17, D18) : mêmes validations que l'API, sans HTTP. */
final class AssignmentWriterService implements AssignmentWriter
{
    public const NULLABLE = ['element_ref', 'group_ref', 'level', 'academic_year_id', 'structure_id', 'subject_id', 'subject_label', 'starts_on', 'ends_on'];

    public function __construct(private readonly AssignmentService $assignments)
    {
    }

    public function assign(string $tenantId, string $establishmentId, AssignmentDraft $draft, string $actorId): string
    {
        $data = Input::validate([
            'session_kind' => $draft->sessionKind, 'planned_minutes' => $draft->plannedMinutes, 'element_ref' => $draft->elementRef,
            'group_ref' => $draft->groupRef, 'level' => $draft->level, 'academic_year_id' => $draft->academicYearId,
            'structure_id' => $draft->structureId, 'subject_label' => $draft->subjectLabel, 'starts_on' => $draft->startsOn, 'ends_on' => $draft->endsOn,
        ], self::rules(), ['session_kind'], self::NULLABLE);

        return (string) $this->assignments->create($tenantId, $establishmentId, $draft->staffId, $data, $actorId)['id'];
    }

    public function change(string $tenantId, string $establishmentId, string $assignmentId, array $changes): void
    {
        $row = $this->row($tenantId, $establishmentId, $assignmentId);
        $data = Input::validate($changes, self::rules() + ['status' => Input::enum(['active', 'ended'])], [], self::NULLABLE, true);
        $this->assignments->update($tenantId, $establishmentId, (string) $row['staff_id'], $assignmentId, $data);
    }

    public function end(string $tenantId, string $establishmentId, string $assignmentId): void
    {
        $this->change($tenantId, $establishmentId, $assignmentId, ['status' => 'ended']);
    }

    public function find(string $tenantId, string $establishmentId, string $assignmentId): ?AssignmentInfo
    {
        $row = $this->assignments->find($tenantId, $establishmentId, $assignmentId);

        return $row === null ? null : self::info($row);
    }

    public function forElement(string $tenantId, string $establishmentId, string $elementRef, ?string $groupRef = null): array
    {
        return array_map(self::info(...), $this->assignments->forElement($tenantId, $establishmentId, $elementRef, $groupRef));
    }

    /** @return array<string, \Closure(mixed): mixed> */
    public static function rules(): array
    {
        return [
            'academic_year_id' => Input::ref(), 'structure_id' => Input::ref(), 'subject_id' => Input::ref(), 'subject_label' => Input::text(160),
            'session_kind' => Input::enum(SessionKind::values()), 'planned_minutes' => Input::int(0, 100000),
            'element_ref' => Input::text(80), 'group_ref' => Input::text(80), 'level' => Input::text(40),
            'starts_on' => Input::date(), 'ends_on' => Input::date(),
        ];
    }

    /** @return array<string, mixed> */
    private function row(string $tenantId, string $establishmentId, string $assignmentId): array
    {
        return $this->assignments->find($tenantId, $establishmentId, $assignmentId) ?? throw HttpException::notFound('Affectation introuvable.');
    }

    /** @param array<string, mixed> $row */
    private static function info(array $row): AssignmentInfo
    {
        $text = static fn (mixed $value): ?string => $value === null ? null : (string) $value;

        return new AssignmentInfo(
            (string) $row['id'],
            (string) $row['establishment_id'],
            (string) $row['staff_id'],
            (string) $row['session_kind'],
            (int) $row['planned_minutes'],
            (int) ($row['validated_minutes'] ?? 0),
            (string) $row['status'],
            $text($row['element_ref'] ?? null),
            $text($row['group_ref'] ?? null),
            $text($row['level'] ?? null),
            $text($row['academic_year_id']),
            $text($row['structure_id']),
            $text($row['subject_label']),
            $text($row['starts_on']),
            $text($row['ends_on']),
        );
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Enrolment\Application\EnrolmentAccess;
use Scholaris\Modules\Apps\Higher\Enrolment\Application\EnrolmentPermissions;
use Scholaris\Modules\Apps\Higher\Enrolment\Application\EnrolmentService;
use Scholaris\Modules\Apps\Higher\Enrolment\Application\StudentService;
use Scholaris\Modules\Apps\Higher\Enrolment\Domain\ProgressionRules;
use Scholaris\Modules\Apps\Higher\Enrolment\Infrastructure\EnrolmentStore;

/** Dossier étudiant, consentement RG-INS-7, dérogations RG-INS-5, décisions de passage RG-INS-3. */
final class StudentController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly EnrolmentAccess $access,
        private readonly EnrolmentStore $store,
        private readonly StudentService $students,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json($this->students->show(EnrolmentAccess::principal($request), EnrolmentAccess::establishmentId($request), self::person($request)));
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $photo = $body->string('photo_ref', 80, false);
        $guarantor = $body->string('guarantor_person_id', 40, false);
        $body->done();

        return $this->json($this->students->update(EnrolmentAccess::principal($request), EnrolmentAccess::establishmentId($request), self::person($request), $photo, $guarantor));
    }

    public function consent(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        if (!$body->has('share_with_guarantor')) {
            $body->error('share_with_guarantor', 'Champ obligatoire.');
        }
        $share = $body->bool('share_with_guarantor');
        $body->done();

        return $this->json($this->students->consent(EnrolmentAccess::principal($request), EnrolmentAccess::establishmentId($request), self::person($request), $share));
    }

    public function derogate(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $person = (string) $body->string('person_id', 40);
        $level = (string) $body->string('level_id', 40);
        $year = (string) $body->string('academic_year_id', 40);
        $reason = (string) $body->string('reason', 500);
        $body->done();
        $row = $this->students->derogate(EnrolmentAccess::principal($request), EnrolmentAccess::establishmentId($request), $person, $level, $year, $reason);
        unset($row['tenant_id'], $row['establishment_id']);

        return $this->json($row, 201);
    }

    public function decisions(ServerRequestInterface $request): ResponseInterface
    {
        $principal = EnrolmentAccess::principal($request);
        $establishmentId = EnrolmentAccess::establishmentId($request);
        $perimeter = $this->access->require($principal, $establishmentId, EnrolmentPermissions::READ, EnrolmentAccess::selectedSchool($request));
        $filters = array_intersect_key(array_filter((array) ($request->getQueryParams()['filter'] ?? []), 'is_string'), array_flip(['person_id', 'program_id', 'level_id', 'academic_year_id', 'decision']));
        [$sql, $params] = $this->access->sqlFilter($perimeter, ['school' => 'school_id', 'program' => 'program_id', 'level' => 'level_id']);
        $rows = $this->store->select(EnrolmentService::DECISIONS, $principal->tenantId, $establishmentId, $filters, 'recorded_at DESC, id', 500, $sql, $params);

        return $this->responder->json(['data' => array_map(static fn (array $r): array => [
            'id' => $r['id'], 'person_id' => $r['person_id'], 'program_id' => $r['program_id'], 'level_id' => $r['level_id'],
            'academic_year_id' => $r['academic_year_id'], 'decision' => $r['decision'],
            'acquired_units' => json_decode((string) $r['acquired_units_json'], true), 'missing_units' => json_decode((string) $r['missing_units_json'], true),
            'recorded_by' => $r['recorded_by'], 'recorded_at' => $r['recorded_at'],
        ], $rows)]);
    }

    public function recordDecision(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $person = (string) $body->string('person_id', 40);
        $level = (string) $body->string('level_id', 40);
        $year = (string) $body->string('academic_year_id', 40);
        $decision = (string) $body->choice('decision', ProgressionRules::DECISIONS);
        $acquired = $body->strings('acquired_units', false, 100) ?? [];
        $missing = $body->strings('missing_units', false, 100) ?? [];
        $body->done();
        $row = $this->students->recordDecision(EnrolmentAccess::principal($request), EnrolmentAccess::establishmentId($request), $person, $level, $year, $decision, $acquired, $missing);
        unset($row['tenant_id'], $row['establishment_id']);

        return $this->json($row, 201);
    }

    private static function person(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute('personId');

        return is_string($value) ? $value : '';
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): ResponseInterface
    {
        return $this->responder->json(['data' => $data], $status);
    }
}

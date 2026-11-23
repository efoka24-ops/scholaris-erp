<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Tutelage\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Tutelage\Application\DiplomaFileService;
use Scholaris\Modules\Apps\Higher\Tutelage\Application\TransmissionService;
use Scholaris\Modules\Apps\Higher\Tutelage\Application\TutelageAccess as Access;
use Scholaris\Modules\Apps\Higher\Tutelage\Application\TutelageContext;
use Scholaris\Modules\Apps\Higher\Tutelage\Application\TutelagePermissions;
use Scholaris\Modules\Apps\Higher\Tutelage\Application\TutelagePortal;
use Scholaris\Modules\Apps\Higher\Tutelage\Domain\TransmissionRules;
use Scholaris\Modules\Apps\Higher\Tutelage\Infrastructure\TutelageStore;

/** Tutelle (TUT) : convention et échéance, transmissions, dossiers de diplôme (RG-TUT-2), accès lecture R22 et son journal. */
final class TutelageController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly Access $access,
        private readonly TutelageStore $store,
        private readonly TutelageContext $context,
        private readonly TransmissionService $transmissions,
        private readonly DiplomaFileService $files,
        private readonly TutelagePortal $portal,
    ) {
    }

    public function convention(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Access::principal($request);
        $establishmentId = Access::establishmentId($request);
        $perimeter = $this->access->require($principal, $establishmentId, TutelagePermissions::INTERNAL);
        [$program, $tutelage] = $this->context->resolve($principal->tenantId, $establishmentId, TutelageResources::query($request, 'program_id'), TutelageResources::query($request, 'academic_year_id'));
        $this->access->assertReadable($perimeter, ['school_id' => $program->schoolId, 'department_id' => $program->departmentId, 'program_id' => $program->id]);

        return $this->responder->json($this->context->convention($program, $tutelage));
    }

    public function transmissions(ServerRequestInterface $request): ResponseInterface
    {
        return $this->list($request, 'transmission', TransmissionService::TABLE, ['program_id', 'academic_year_id', 'transmission_kind', 'status'], 'sent_at DESC, id', null);
    }

    public function transmit(ServerRequestInterface $request): ResponseInterface
    {
        $body = RequestBody::of($request);
        $data = [
            'kind' => (string) $body->choice('kind', TransmissionRules::KINDS), 'program_id' => (string) $body->string('program_id', 40),
            'academic_year_id' => (string) $body->string('academic_year_id', 40), 'level_id' => $body->string('level_id', 40, false),
            'period_id' => $body->string('period_id', 40, false), 'format' => $body->choice('format', ['csv', 'xlsx'], false) ?? 'csv',
            'note' => $body->string('note', 500, false),
        ];
        $body->done();

        return $this->responder->json($this->transmissions->transmit(Access::principal($request), Access::establishmentId($request), $data), 201);
    }

    public function answer(ServerRequestInterface $request): ResponseInterface
    {
        $body = RequestBody::of($request);
        $status = (string) $body->choice('status', TransmissionRules::ANSWERS);
        $note = $body->string('note', 500, false);
        $body->done();

        return $this->responder->json(TutelageResources::of('transmission', $this->transmissions->answer(Access::principal($request), Access::establishmentId($request), TutelageResources::arg($request, 'id'), $status, $note)));
    }

    public function diplomaFiles(ServerRequestInterface $request): ResponseInterface
    {
        return $this->list($request, 'diploma_file', DiplomaFileService::TABLE, ['program_id', 'academic_year_id', 'status', 'person_id'], 'transmitted_at DESC, id', null);
    }

    public function createDiplomaFile(ServerRequestInterface $request): ResponseInterface
    {
        $body = RequestBody::of($request);
        $person = (string) $body->string('person_id', 40);
        $program = (string) $body->string('program_id', 40);
        $year = (string) $body->string('academic_year_id', 40);
        $body->done();

        return $this->responder->json(TutelageResources::of('diploma_file', $this->files->transmit(Access::principal($request), Access::establishmentId($request), $person, $program, $year)), 201);
    }

    public function moveDiplomaFile(ServerRequestInterface $request): ResponseInterface
    {
        $body = RequestBody::of($request);
        $status = (string) $body->choice('status', TransmissionRules::FILE_STATUSES);
        $note = $body->string('note', 500, false);
        $body->done();

        return $this->responder->json(TutelageResources::of('diploma_file', $this->files->move(Access::principal($request), Access::establishmentId($request), TutelageResources::arg($request, 'id'), $status, $note)));
    }

    public function portalEnrolments(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->portal->enrolments(Access::principal($request), Access::establishmentId($request), TutelageResources::query($request, 'program_id'), TutelageResources::query($request, 'academic_year_id')));
    }

    public function consultations(ServerRequestInterface $request): ResponseInterface
    {
        return $this->list($request, 'consultation', TutelagePortal::TABLE, ['program_id', 'academic_year_id', 'user_id'], 'created_at DESC, id', ['program' => 'program_id']);
    }

    /**
     * @param list<string> $filters
     * @param array<string, string>|null $columns
     */
    private function list(ServerRequestInterface $request, string $type, string $table, array $filters, string $orderBy, ?array $columns): ResponseInterface
    {
        $principal = Access::principal($request);
        $establishmentId = Access::establishmentId($request);
        $perimeter = $this->access->require($principal, $establishmentId, TutelagePermissions::INTERNAL, Access::selectedSchool($request));

        return $this->responder->json(TutelageResources::listing($request, $this->store, $type, $table, $principal->tenantId, $establishmentId, $filters, $orderBy, $this->access->sqlFilter($perimeter, $columns)));
    }
}

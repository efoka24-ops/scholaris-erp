<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Apps\Higher\Enrolment\Application\EnrolmentAccess;
use Scholaris\Modules\Apps\Higher\Enrolment\Application\EnrolmentPermissions;
use Scholaris\Modules\Apps\Higher\Enrolment\Application\EnrolmentService;
use Scholaris\Modules\Apps\Higher\Enrolment\Application\LearningContractService;
use Scholaris\Modules\Apps\Higher\Enrolment\Domain\EnrolmentStatus;
use Scholaris\Modules\Apps\Higher\Enrolment\Infrastructure\EnrolmentStore;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;

/**
 * Inscriptions administratives et pédagogiques (EX-INS-01 à 06) : création (candidature admise ou réinscription), validation,
 * statuts, changements, lien avec l'inscription du socle, contrat pédagogique, carte d'étudiant (DOC-05) et fiche (DOC-04).
 */
final class EnrolmentController
{
    private const FILTERS = ['school_id', 'program_id', 'level_id', 'group_id', 'academic_year_id', 'status', 'person_id', 'enrolment_kind'];

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly EnrolmentAccess $access,
        private readonly EnrolmentStore $store,
        private readonly EnrolmentService $enrolments,
        private readonly LearningContractService $contracts,
        private readonly UserPersonLink $links,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = EnrolmentAccess::principal($request);
        $establishmentId = EnrolmentAccess::establishmentId($request);
        $perimeter = $this->access->require($principal, $establishmentId, EnrolmentPermissions::READ, EnrolmentAccess::selectedSchool($request));
        $query = ListQuery::fromRequest($request, self::FILTERS);
        $decoded = ListQuery::decodeCursor($query->cursor);
        $offset = $decoded !== null && ctype_digit($decoded) ? (int) $decoded : 0;
        [$sql, $params] = $this->access->sqlFilter($perimeter);
        $rows = $this->store->select(EnrolmentService::TABLE, $principal->tenantId, $establishmentId, $query->filters, 'academic_year_id DESC, created_at DESC, id', $query->perPage + 1, $sql, $params, $offset);
        $next = null;
        if (count($rows) > $query->perPage) {
            array_pop($rows);
            $next = ListQuery::encodeCursor((string) ($offset + $query->perPage));
        }

        return $this->responder->json([
            'data' => array_map(fn (array $row): array => EnrolmentResources::enrolment($this->enrolments->get($principal->tenantId, $establishmentId, (string) $row['id'])), $rows),
            'meta' => ['next_cursor' => $next, 'per_page' => $query->perPage],
        ]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $applicationId = $body->string('application_id', 40, false);
        $personId = $body->string('person_id', 40, false);
        $levelId = $body->string('level_id', 40, false);
        $yearId = $body->string('academic_year_id', 40, false);
        $groupId = $body->string('group_id', 40, false);
        $mode = $body->choice('study_mode', EnrolmentPermissions::MODES, false);
        if ($applicationId === null && ($personId === null || $levelId === null || $yearId === null)) {
            $body->error('application_id', 'Candidature admise (application_id) ou réinscription (person_id, level_id, academic_year_id) attendue.');
        }
        $body->done();
        $principal = EnrolmentAccess::principal($request);
        $establishmentId = EnrolmentAccess::establishmentId($request);
        $enrolment = $applicationId !== null
            ? $this->enrolments->fromApplication($principal, $establishmentId, $applicationId, $groupId, $mode)
            : $this->enrolments->reenrol($principal, $establishmentId, (string) $personId, (string) $levelId, (string) $yearId, $groupId, $mode);

        return $this->json(EnrolmentResources::enrolment($enrolment), 201);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json(EnrolmentResources::enrolment($this->readable($request)));
    }

    public function validate(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $hold = $body->bool('financial_hold');
        $reason = $body->string('reason', 500, false);
        $body->done();

        return $this->json(EnrolmentResources::enrolment($this->enrolments->validate(EnrolmentAccess::principal($request), EnrolmentAccess::establishmentId($request), self::id($request), $hold, $reason)));
    }

    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $status = (string) $body->choice('status', [EnrolmentStatus::ENROLLED, EnrolmentStatus::SUSPENDED, EnrolmentStatus::WITHDRAWN, EnrolmentStatus::EXCLUDED, EnrolmentStatus::TRANSFERRED, EnrolmentStatus::GRADUATED]);
        $reason = (string) $body->string('reason', 500);
        $body->done();

        return $this->json(EnrolmentResources::enrolment($this->enrolments->changeStatus(EnrolmentAccess::principal($request), EnrolmentAccess::establishmentId($request), self::id($request), $status, $reason)));
    }

    public function change(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $levelId = $body->string('level_id', 40, false);
        $groupId = $body->string('group_id', 40, false);
        $mode = $body->choice('study_mode', EnrolmentPermissions::MODES, false);
        $reason = (string) $body->string('reason', 500);
        $body->done();
        $result = $this->enrolments->change(EnrolmentAccess::principal($request), EnrolmentAccess::establishmentId($request), self::id($request), $levelId, $groupId, $mode, $reason);

        return $this->json(EnrolmentResources::enrolment($result) + ['fees_recalculation_required' => $result['fees_recalculation_required'] ?? false]);
    }

    public function link(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $enrollmentId = (string) $body->string('enrollment_id', 40);
        $body->done();

        return $this->json(EnrolmentResources::enrolment($this->enrolments->link(EnrolmentAccess::principal($request), EnrolmentAccess::establishmentId($request), self::id($request), $enrollmentId)));
    }

    public function contract(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json($this->contracts->show($this->readable($request)));
    }

    public function chooseUnits(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $units = $body->strings('optional_unit_ids', false, 40) ?? [];
        $groupId = $body->string('group_id', 40, false);
        $finalize = $body->bool('finalize');
        $body->done();
        $principal = EnrolmentAccess::principal($request);
        $establishmentId = EnrolmentAccess::establishmentId($request);
        if ($groupId !== null) {
            $this->enrolments->change($principal, $establishmentId, self::id($request), null, $groupId, null, 'Rattachement au groupe (inscription pédagogique).');
        }
        $enrolment = $this->enrolments->writable($principal, $establishmentId, self::id($request), [EnrolmentPermissions::ENROL]);
        $this->contracts->choose($enrolment, $units, $finalize, $principal->userId);
        $this->access->audit($principal->tenantId, $principal->userId, 'higher.enrolment.units_chosen', 'hi_enrolment', (string) $enrolment['id'], null, ['optional_unit_ids' => $units, 'finalize' => $finalize]);

        return $this->json($this->contracts->show($enrolment));
    }

    public function studentCard(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json($this->enrolments->issue(EnrolmentAccess::principal($request), EnrolmentAccess::establishmentId($request), self::id($request), 'DOC-05'), 201);
    }

    public function enrolmentForm(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json($this->enrolments->issue(EnrolmentAccess::principal($request), EnrolmentAccess::establishmentId($request), self::id($request), 'DOC-04'), 201);
    }

    public function events(ServerRequestInterface $request): ResponseInterface
    {
        $enrolment = $this->readable($request);
        $events = $this->store->select(EnrolmentService::EVENTS, (string) $enrolment['tenant_id'], (string) $enrolment['establishment_id'], ['enrolment_id' => $enrolment['id']], 'occurred_at, id', 1000);

        return $this->responder->json(['data' => array_map(static fn (array $e): array => [
            'event_type' => $e['event_type'], 'from_value' => $e['from_value'], 'to_value' => $e['to_value'], 'reason' => $e['reason'],
            'actor_id' => $e['actor_id'], 'occurred_at' => $e['occurred_at'],
        ], $events)]);
    }

    /**
     * Inscription lisible : périmètre de lecture, ou l'étudiant lui-même (R12, la sienne).
     *
     * @return array<string, mixed>
     */
    private function readable(ServerRequestInterface $request): array
    {
        $principal = EnrolmentAccess::principal($request);
        $establishmentId = EnrolmentAccess::establishmentId($request);
        $enrolment = $this->enrolments->get($principal->tenantId, $establishmentId, self::id($request));
        if ($this->links->personIdForUser($principal->tenantId, $principal->userId) === $enrolment['person_id']) {
            return $enrolment;
        }
        $perimeter = $this->access->perimeter($principal, $establishmentId, EnrolmentPermissions::READ);
        if ($perimeter->isEmpty()) {
            throw HttpException::notFound('Inscription introuvable.');
        }
        $this->access->assertReadable($perimeter, $enrolment);

        return $enrolment;
    }

    private static function id(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute('id');

        return is_string($value) ? $value : '';
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): ResponseInterface
    {
        return $this->responder->json(['data' => $data], $status);
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Internships\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Internships\Application\DefenseService;
use Scholaris\Modules\Apps\Higher\Internships\Application\DefenseSession;
use Scholaris\Modules\Apps\Higher\Internships\Application\InternshipAccess;
use Scholaris\Modules\Apps\Higher\Internships\Application\InternshipDocuments;
use Scholaris\Modules\Apps\Higher\Internships\Application\InternshipResources;
use Scholaris\Modules\Apps\Higher\Internships\Domain\JuryRules;
use Scholaris\Modules\Apps\Higher\Internships\Infrastructure\InternshipStore;

/** Soutenances (API-18, EX-STG-05/06) : planning, jury, convocation (DOC-21, NOT-13), note et PV (DOC-22) ; `mine=1` pour un membre de jury. */
final class DefenseController
{
    private const FILTERS = ['status', 'held_on', 'program_id', 'level_id', 'internship_id', 'person_id', 'school_id', 'room'];

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly InternshipAccess $access,
        private readonly InternshipStore $store,
        private readonly DefenseService $defenses,
        private readonly DefenseSession $session,
        private readonly InternshipDocuments $documents,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = InternshipAccess::principal($request);
        $establishmentId = InternshipAccess::establishmentId($request);
        if (($request->getQueryParams()['mine'] ?? null) === '1') {
            $me = $this->access->personOf($principal) ?? throw HttpException::forbidden('Aucune personne liée à ce compte.');
            $ids = array_map(static fn (array $m): string => (string) $m['defense_id'], $this->store->select(DefenseService::JURY, $principal->tenantId, $establishmentId, ['person_id' => $me]));
            $scope = $ids === [] ? ['1 = 0', []] : ['id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids];
        } else {
            $scope = $this->access->sqlFilter($this->access->require($principal, $establishmentId, InternshipAccess::READ, InternshipAccess::selectedSchool($request)));
        }
        $map = fn (array $row): array => InternshipResources::defense($row, $this->defenses->jury($principal->tenantId, $establishmentId, (string) $row['id']));

        return $this->responder->json(Listing::of($request, $this->store, DefenseService::TABLE, $principal->tenantId, $establishmentId, self::FILTERS, 'held_on DESC, start_time, id', $scope, $map));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $principal = InternshipAccess::principal($request);
        $establishmentId = InternshipAccess::establishmentId($request);
        $id = Listing::arg($request, 'id');
        $row = $this->defenses->row($principal->tenantId, $establishmentId, $id);
        $jury = $this->defenses->jury($principal->tenantId, $establishmentId, $id);
        $me = $this->access->personOf($principal);
        if ($me === null || !in_array($me, array_column($jury, 'person_id'), true)) {
            $this->access->assertReadable($this->access->require($principal, $establishmentId, InternshipAccess::READ), $row);
        }

        return $this->responder->json(['data' => InternshipResources::defense($row, $jury)]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $principal = InternshipAccess::principal($request);
        $establishmentId = InternshipAccess::establishmentId($request);
        $this->access->require($principal, $establishmentId, InternshipAccess::DEFENSE);
        $body = Body::of($request);
        $internshipId = (string) $body->string('internship_id', 40);
        $slot = self::slot($body);
        $jury = $this->jury($principal, $body, true) ?? [];
        $body->done();
        $row = $this->defenses->schedule($principal, $establishmentId, $internshipId, $slot, $jury);

        return $this->respond($principal, $establishmentId, $row, 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $principal = InternshipAccess::principal($request);
        $establishmentId = InternshipAccess::establishmentId($request);
        $this->access->require($principal, $establishmentId, InternshipAccess::DEFENSE);
        $body = Body::of($request);
        $slot = self::slot($body);
        $jury = $this->jury($principal, $body, false);
        $body->done();

        return $this->respond($principal, $establishmentId, $this->defenses->reschedule($principal, $establishmentId, Listing::arg($request, 'id'), $slot, $jury));
    }

    public function convene(ServerRequestInterface $request): ResponseInterface
    {
        $principal = InternshipAccess::principal($request);
        $establishmentId = InternshipAccess::establishmentId($request);

        return $this->respond($principal, $establishmentId, $this->session->convene($principal, $establishmentId, Listing::arg($request, 'id')));
    }

    public function grade(ServerRequestInterface $request): ResponseInterface
    {
        $principal = InternshipAccess::principal($request);
        $establishmentId = InternshipAccess::establishmentId($request);
        $id = Listing::arg($request, 'id');
        $this->defenses->row($principal->tenantId, $establishmentId, $id);
        $body = Body::of($request);
        $grade = (int) $body->grade('grade');
        $remarks = $body->string('remarks', 1000, false);
        $body->done();

        return $this->respond($principal, $establishmentId, $this->session->grade($principal, $establishmentId, $id, $grade, $remarks));
    }

    public function minutes(ServerRequestInterface $request): ResponseInterface
    {
        $principal = InternshipAccess::principal($request);
        $establishmentId = InternshipAccess::establishmentId($request);

        return $this->respond($principal, $establishmentId, $this->session->close($principal, $establishmentId, Listing::arg($request, 'id')));
    }

    public function cancel(ServerRequestInterface $request): ResponseInterface
    {
        $principal = InternshipAccess::principal($request);
        $establishmentId = InternshipAccess::establishmentId($request);
        $this->access->require($principal, $establishmentId, InternshipAccess::DEFENSE);
        $body = Body::of($request);
        $reason = (string) $body->string('reason', 500);
        $body->done();

        return $this->respond($principal, $establishmentId, $this->defenses->cancel($principal, $establishmentId, Listing::arg($request, 'id'), $reason));
    }

    /** @param array<string, mixed> $row */
    private function respond(Principal $principal, string $establishmentId, array $row, int $status = 200): ResponseInterface
    {
        return $this->responder->json(['data' => InternshipResources::defense($row, $this->defenses->jury($principal->tenantId, $establishmentId, (string) $row['id']))], $status);
    }

    /** @return array{held_on: string, start_time: string, end_time: string, room: string} */
    private static function slot(Body $body): array
    {
        return [
            'held_on' => (string) $body->date('held_on'), 'start_time' => (string) $body->string('start_time', 5),
            'end_time' => (string) $body->string('end_time', 5), 'room' => (string) $body->string('room', 80),
        ];
    }

    /** @return list<array{person_id: string, jury_role: string, display_name: ?string}>|null */
    private function jury(Principal $principal, Body $body, bool $required): ?array
    {
        $list = $body->list('jury', $required, 12);
        if ($list === null) {
            return null;
        }
        $members = [];
        foreach ($list as $item) {
            $person = is_array($item) ? ($item['person_id'] ?? null) : null;
            $role = is_array($item) ? ($item['jury_role'] ?? null) : null;
            $name = is_array($item) ? ($item['display_name'] ?? null) : null;
            if (!is_string($person) || $person === '' || strlen($person) > 40 || !in_array($role, JuryRules::ROLES, true) || ($name !== null && (!is_string($name) || mb_strlen($name) > 160))) {
                $body->error('jury', 'Chaque membre : person_id, jury_role (president, supervisor, member), display_name facultatif.');

                return [];
            }
            $members[] = ['person_id' => $person, 'jury_role' => $role, 'display_name' => is_string($name) && trim($name) !== '' ? trim($name) : $this->documents->nameOf($principal->tenantId, $person)];
        }

        return $members;
    }
}

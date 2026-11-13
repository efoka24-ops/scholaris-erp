<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Admissions\Application\AdmissionPermissions;
use Scholaris\Modules\Apps\Higher\Admissions\Application\AdmissionsAccess;
use Scholaris\Modules\Apps\Higher\Admissions\Application\CampaignService;
use Scholaris\Modules\Apps\Higher\Admissions\Application\ContestService;
use Scholaris\Modules\Apps\Higher\Admissions\Domain\Prerequisites;
use Scholaris\Modules\Apps\Higher\Admissions\Infrastructure\AdmissionsStore;

/** Campagnes d'admission (EX-ADM-01), jury, classement, statistiques, épreuves de concours et convocations. */
final class CampaignController
{
    private const FILTERS = ['school_id', 'department_id', 'program_id', 'level_id', 'academic_year_id', 'status', 'selection_mode'];

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly AdmissionsAccess $access,
        private readonly AdmissionsStore $store,
        private readonly CampaignService $campaigns,
        private readonly ContestService $contests,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = AdmissionsAccess::principal($request);
        $establishmentId = AdmissionsAccess::establishmentId($request);
        $perimeter = $this->access->require($principal, $establishmentId, AdmissionPermissions::READ, AdmissionsAccess::selectedSchool($request));
        [$query, $offset] = AdmissionsResources::listQuery($request, self::FILTERS);
        [$sql, $params] = $this->access->sqlFilter($perimeter);
        $rows = $this->store->select(CampaignService::TABLE, $principal->tenantId, $establishmentId, $query->filters, 'opens_on DESC, id', $query->perPage + 1, $sql, $params, $offset);

        $page = AdmissionsResources::page($rows, $query->perPage, $offset, AdmissionsResources::campaign(...));

        return $this->responder->json($page);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $input = [
            'level_id' => (string) $body->string('level_id', 40),
            'academic_year_id' => (string) $body->string('academic_year_id', 40),
            'title' => $body->string('title', 160, false),
            'opens_on' => (string) $body->date('opens_on'),
            'closes_on' => (string) $body->date('closes_on'),
            'seats' => (int) $body->int('seats', 1, 100000),
            'application_fee_xaf' => (int) $body->int('application_fee_xaf', 0, 10000000),
            'selection_mode' => (string) $body->choice('selection_mode', CampaignService::MODES, false, 'file'),
            'required_documents' => $this->documents($body),
            'prerequisites' => $this->prerequisites($body),
        ];
        $body->done();

        return $this->json(AdmissionsResources::campaign($this->campaigns->create(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), $input)), 201);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json(AdmissionsResources::campaign($this->campaigns->readable(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), self::id($request))));
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $changes = array_filter([
            'title' => $body->string('title', 160, false),
            'opens_on' => $body->date('opens_on', false),
            'closes_on' => $body->date('closes_on', false),
            'seats' => $body->int('seats', 1, 100000, false),
            'application_fee_xaf' => $body->int('application_fee_xaf', 0, 10000000, false),
            'selection_mode' => $body->choice('selection_mode', CampaignService::MODES, false),
        ], static fn (mixed $v): bool => $v !== null);
        $documents = $this->documents($body);
        if ($documents !== null) {
            $changes['required_documents_json'] = json_encode($documents, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        $prerequisites = $this->prerequisites($body);
        if ($prerequisites !== null) {
            $changes['prerequisites_json'] = json_encode($prerequisites->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        $body->done();

        return $this->json(AdmissionsResources::campaign($this->campaigns->update(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), self::id($request), $changes)));
    }

    public function open(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json(AdmissionsResources::campaign($this->campaigns->open(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), self::id($request))));
    }

    public function close(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json(AdmissionsResources::campaign($this->campaigns->close(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), self::id($request))));
    }

    public function cancel(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $reason = (string) $body->string('reason', 500);
        $body->done();

        return $this->json(AdmissionsResources::campaign($this->campaigns->cancel(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), self::id($request), $reason)));
    }

    public function jury(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $president = (string) $body->string('president_person_id', 40);
        $members = $body->strings('member_person_ids', true, 30) ?? [];
        $body->done();

        return $this->json(AdmissionsResources::campaign($this->campaigns->jury(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), self::id($request), $president, $members)));
    }

    public function rank(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->campaigns->rank(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), self::id($request))]);
    }

    public function statistics(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json($this->campaigns->statistics(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), self::id($request)));
    }

    public function sessions(ServerRequestInterface $request): ResponseInterface
    {
        $principal = AdmissionsAccess::principal($request);
        $establishmentId = AdmissionsAccess::establishmentId($request);
        $campaign = $this->campaigns->readable($principal, $establishmentId, self::id($request));
        $sessions = $this->store->select(ContestService::SESSIONS, $principal->tenantId, $establishmentId, ['campaign_id' => $campaign['id']], 'contest_date, id');
        foreach ($sessions as &$session) {
            $session['seats'] = $this->store->select(ContestService::SEATS, $principal->tenantId, $establishmentId, ['session_id' => $session['id']], 'seat_number', 100000);
            unset($session['tenant_id'], $session['establishment_id']);
            foreach ($session['seats'] as &$seat) {
                unset($seat['tenant_id'], $seat['establishment_id']);
            }
            unset($seat);
        }
        unset($session);

        return $this->responder->json(['data' => $sessions]);
    }

    public function createSession(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $input = [
            'contest_date' => (string) $body->date('contest_date'),
            'center_name' => (string) $body->string('center_name', 160),
            'room_name' => (string) $body->string('room_name', 80),
            'room_capacity' => (int) $body->int('room_capacity', 1, 5000),
        ];
        $body->done();
        $session = $this->contests->createSession(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), self::id($request), $input);
        unset($session['tenant_id'], $session['establishment_id']);

        return $this->json($session, 201);
    }

    public function convoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->contests->convoke(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), self::id($request))]);
    }

    /** @return list<array{code: string, label: string, blocking: bool, proves_prerequisite: bool}>|null */
    private function documents(Body $body): ?array
    {
        $list = $body->list('required_documents', false, 30);
        if ($list === null) {
            return null;
        }
        [$documents, $errors] = Prerequisites::normalizeDocuments($list);
        if ($documents === []) {
            $errors[] = 'Au moins une pièce exigée.';
        }
        foreach ($errors as $error) {
            $body->error('required_documents', $error);
        }

        return $documents;
    }

    private function prerequisites(Body $body): ?Prerequisites
    {
        $data = $body->object('prerequisites', false);
        if ($data === null) {
            return null;
        }
        $prerequisites = Prerequisites::fromArray($data);
        foreach ($prerequisites->errors() as $error) {
            $body->error('prerequisites', $error);
        }

        return $prerequisites;
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

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Internships\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Internships\Application\ConventionService;
use Scholaris\Modules\Apps\Higher\Internships\Application\FollowUpService;
use Scholaris\Modules\Apps\Higher\Internships\Application\InternshipAccess;
use Scholaris\Modules\Apps\Higher\Internships\Application\InternshipResources;
use Scholaris\Modules\Apps\Higher\Internships\Domain\ConventionStatus;
use Scholaris\Modules\Apps\Higher\Internships\Domain\DefenseReadiness;
use Scholaris\Modules\Apps\Higher\Internships\Infrastructure\InternshipStore;

/** Conventions de stage (EX-STG-02 à 04), suivi (EX-STG-03) et conditions de soutenance (RG-STG-1). */
final class InternshipController
{
    private const FILTERS = ['status', 'program_id', 'level_id', 'academic_year_id', 'company_id', 'person_id', 'supervisor_person_id', 'school_id'];

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly InternshipAccess $access,
        private readonly InternshipStore $store,
        private readonly ConventionService $conventions,
        private readonly FollowUpService $followups,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = InternshipAccess::principal($request);
        $establishmentId = InternshipAccess::establishmentId($request);
        $scope = $this->access->sqlFilter($this->access->require($principal, $establishmentId, InternshipAccess::READ, InternshipAccess::selectedSchool($request)));

        $page = Listing::of($request, $this->store, ConventionService::TABLE, $principal->tenantId, $establishmentId, self::FILTERS, 'starts_on DESC, id', $scope, InternshipResources::internship(...));

        return $this->responder->json($page);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->who($request);
        $row = $this->conventions->readable($principal, $establishmentId, Listing::arg($request, 'id'));

        return $this->respond($row + ['readiness' => $this->conventions->readiness($principal->tenantId, $row)]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->who($request);
        $this->access->require($principal, $establishmentId, InternshipAccess::MANAGE);
        $body = Body::of($request);
        $data = ['enrolment_id' => $body->string('enrolment_id', 40)] + self::fields($body, true);
        $body->done();

        return $this->respond($this->conventions->create($principal, $establishmentId, $data), 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->who($request);
        $this->access->require($principal, $establishmentId, InternshipAccess::MANAGE);
        $body = Body::of($request);
        $data = self::fields($body, false);
        $body->done();

        return $this->respond($this->conventions->update($principal, $establishmentId, Listing::arg($request, 'id'), $data));
    }

    public function convention(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->who($request);

        return $this->respond($this->conventions->issueConvention($principal, $establishmentId, Listing::arg($request, 'id')));
    }

    public function sign(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->who($request);
        $this->access->require($principal, $establishmentId, [...InternshipAccess::MANAGE, ...InternshipAccess::SIGN_SCHOOL]);
        $body = Body::of($request);
        $party = (string) $body->choice('party', ConventionStatus::PARTIES);
        $signedOn = (string) $body->date('signed_on');
        $body->done();

        return $this->respond($this->conventions->sign($principal, $establishmentId, Listing::arg($request, 'id'), $party, $signedOn));
    }

    public function validateSubject(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->who($request);

        return $this->respond($this->conventions->validateSubject($principal, $establishmentId, Listing::arg($request, 'id')));
    }

    public function complete(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->who($request);
        $this->access->require($principal, $establishmentId, InternshipAccess::MANAGE);
        $body = Body::of($request);
        $receivedOn = (string) $body->date('received_on');
        $reference = $body->string('reference', 120, false);
        $body->done();

        return $this->respond($this->conventions->complete($principal, $establishmentId, Listing::arg($request, 'id'), $receivedOn, $reference));
    }

    public function thesis(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->who($request);
        $this->access->require($principal, $establishmentId, InternshipAccess::MANAGE);
        $body = Body::of($request);
        $title = (string) $body->string('title', 255);
        $reference = (string) $body->string('file_ref', 255);
        $body->done();
        $row = $this->conventions->writable($principal, $establishmentId, Listing::arg($request, 'id'), InternshipAccess::MANAGE);

        return $this->respond($this->conventions->depositThesis($principal, $establishmentId, $row, $title, $reference));
    }

    public function cancel(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->who($request);
        $this->access->require($principal, $establishmentId, InternshipAccess::MANAGE);
        $body = Body::of($request);
        $reason = (string) $body->string('reason', 500);
        $body->done();

        return $this->respond($this->conventions->cancel($principal, $establishmentId, Listing::arg($request, 'id'), $reason));
    }

    public function readiness(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->who($request);
        $checks = $this->conventions->readiness($principal->tenantId, $this->conventions->readable($principal, $establishmentId, Listing::arg($request, 'id')));

        return $this->responder->json(['data' => ['conditions' => $checks, 'missing' => DefenseReadiness::missing($checks), 'ready' => DefenseReadiness::missing($checks) === []]]);
    }

    public function followups(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->who($request);
        $id = Listing::arg($request, 'id');
        $this->conventions->readable($principal, $establishmentId, $id);

        $page = Listing::of($request, $this->store, FollowUpService::TABLE, $principal->tenantId, $establishmentId, ['kind'], 'occurred_on, created_at', ['internship_id = ?', [$id]], InternshipResources::followup(...));

        return $this->responder->json($page);
    }

    public function addFollowup(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->who($request);
        $this->access->require($principal, $establishmentId, InternshipAccess::MANAGE);
        $body = Body::of($request);
        $kind = (string) $body->choice('kind', FollowUpService::KINDS);
        $occurredOn = (string) $body->date('occurred_on');
        $summary = (string) $body->string('summary', 4000);
        $score = $body->grade('score', false);
        $body->done();

        return $this->responder->json(['data' => InternshipResources::followup($this->followups->add($principal, $establishmentId, Listing::arg($request, 'id'), $kind, $occurredOn, $summary, $score))], 201);
    }

    /** @return array{0: \Scholaris\Core\Security\Principal, 1: string} */
    private function who(ServerRequestInterface $request): array
    {
        return [InternshipAccess::principal($request), InternshipAccess::establishmentId($request)];
    }

    /** @param array<string, mixed> $row */
    private function respond(array $row, int $status = 200): ResponseInterface
    {
        return $this->responder->json(['data' => InternshipResources::internship($row)], $status);
    }

    /** @return array<string, scalar|null> */
    private static function fields(Body $body, bool $creating): array
    {
        $data = [];
        $required = ['company_id' => 40, 'subject' => 255, 'company_tutor_name' => 160, 'supervisor_person_id' => 40];
        foreach ($required as $field => $max) {
            if ($creating || $body->has($field)) {
                $data[$field] = $body->string($field, $max, $creating);
            }
        }
        foreach (['starts_on', 'ends_on'] as $field) {
            if ($creating || $body->has($field)) {
                $data[$field] = $body->date($field, $creating);
            }
        }
        foreach (['company_tutor_email' => 160, 'company_tutor_phone' => 40] as $field => $max) {
            if ($body->has($field)) {
                $data[$field] = $body->string($field, $max, false);
            }
        }

        return $data;
    }
}

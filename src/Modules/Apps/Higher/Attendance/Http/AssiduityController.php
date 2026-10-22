<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Attendance\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Attendance\Application\AssiduityService;
use Scholaris\Modules\Apps\Higher\Attendance\Application\AttendanceAccess;
use Scholaris\Modules\Apps\Higher\Attendance\Application\AttendanceClock;
use Scholaris\Modules\Apps\Higher\Attendance\Application\AttendancePermissions;
use Scholaris\Modules\Apps\Higher\Attendance\Application\ReplacementExamService;
use Scholaris\Modules\Apps\Higher\Attendance\Domain\AssiduityRule;
use Scholaris\Modules\Apps\Higher\Attendance\Infrastructure\AttendanceStore;

/**
 * Assiduité par EC (EX-PRE-03, RG-PRE-1), alertes NOT-07, non-autorisés à composer et transmission au service des examens (EX-PRE-05),
 * levées tracées, présence des examens nationaux (70 %), épreuves de remplacement après ABJ. L'appel et les justificatifs restent aux
 * routes du socle (/attendance-sessions, /absence-justifications).
 */
final class AssiduityController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly AttendanceAccess $access,
        private readonly AttendanceStore $store,
        private readonly AssiduityService $assiduity,
        private readonly ReplacementExamService $replacements,
        private readonly AttendanceClock $clock,
    ) {
    }

    public function element(ServerRequestInterface $request): ResponseInterface
    {
        [$elementId, $groupId, $yearId] = self::target($request->getQueryParams());

        return $this->responder->json($this->assiduity->forElement(AttendanceAccess::principal($request), AttendanceAccess::establishmentId($request), $elementId, $groupId, $yearId));
    }

    public function barred(ServerRequestInterface $request): ResponseInterface
    {
        [$elementId, $groupId, $yearId] = self::target($request->getQueryParams());
        $result = $this->assiduity->forElement(AttendanceAccess::principal($request), AttendanceAccess::establishmentId($request), $elementId, $groupId, $yearId);
        $result['students'] = array_values(array_filter($result['students'], static fn (array $r): bool => in_array($r['status'], [AssiduityRule::BARRED, AssiduityRule::LIFTED], true)));

        return $this->responder->json($result);
    }

    public function student(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->assiduity->forStudent(AttendanceAccess::principal($request), AttendanceAccess::establishmentId($request), self::arg($request, 'personId'))]);
    }

    public function presence(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $from = is_string($query['from'] ?? null) ? $query['from'] : $this->clock->plusDays(-365);
        $to = is_string($query['to'] ?? null) ? $query['to'] : $this->clock->today();
        foreach (['from' => $from, 'to' => $to] as $key => $value) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                throw HttpException::validation([$key => ['Date AAAA-MM-JJ attendue.']]);
            }
        }

        return $this->responder->json($this->assiduity->presenceFor(AttendanceAccess::principal($request), AttendanceAccess::establishmentId($request), self::arg($request, 'personId'), $from, $to));
    }

    public function alerts(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $elementId = (string) $body->string('element_id', 40);
        $groupId = (string) $body->string('group_id', 40);
        $yearId = (string) $body->string('academic_year_id', 40);
        $title = (string) $body->string('element_title', 160);
        $body->done();

        return $this->responder->json($this->assiduity->sendAlerts(AttendanceAccess::principal($request), AttendanceAccess::establishmentId($request), $elementId, $groupId, $yearId, $title));
    }

    public function lists(ServerRequestInterface $request): ResponseInterface
    {
        $principal = AttendanceAccess::principal($request);
        $establishmentId = AttendanceAccess::establishmentId($request);
        $perimeter = $this->access->require($principal, $establishmentId, array_merge(AttendancePermissions::READ, AttendancePermissions::MANAGE, AttendancePermissions::EXAMS), AttendanceAccess::selectedSchool($request));

        $page = Listing::of($request, $this->store, AssiduityService::LISTS, $principal->tenantId, $establishmentId, ['element_id', 'group_id', 'academic_year_id'], 'transmitted_at DESC, id', $this->access->sqlFilter($perimeter), AttendanceResources::of(...));

        return $this->responder->json($page);
    }

    public function transmit(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $elementId = (string) $body->string('element_id', 40);
        $groupId = (string) $body->string('group_id', 40);
        $yearId = (string) $body->string('academic_year_id', 40);
        $body->done();

        return $this->responder->json(AttendanceResources::of($this->assiduity->transmit(AttendanceAccess::principal($request), AttendanceAccess::establishmentId($request), $elementId, $groupId, $yearId)), 201);
    }

    public function receive(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(AttendanceResources::of($this->assiduity->receive(AttendanceAccess::principal($request), AttendanceAccess::establishmentId($request), self::arg($request, 'id'))));
    }

    public function lift(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $personId = (string) $body->string('person_id', 40);
        $elementId = (string) $body->string('element_id', 40);
        $groupId = (string) $body->string('group_id', 40);
        $reason = (string) $body->string('reason', 500);
        $document = $body->string('document_ref', 80, false);
        $body->done();

        return $this->responder->json(AttendanceResources::of($this->assiduity->lift(AttendanceAccess::principal($request), AttendanceAccess::establishmentId($request), $personId, $elementId, $groupId, $reason, $document)), 201);
    }

    public function replacements(ServerRequestInterface $request): ResponseInterface
    {
        $principal = AttendanceAccess::principal($request);
        $establishmentId = AttendanceAccess::establishmentId($request);
        $perimeter = $this->access->require($principal, $establishmentId, array_merge(AttendancePermissions::READ, AttendancePermissions::MANAGE, AttendancePermissions::EXAMS), AttendanceAccess::selectedSchool($request));

        $page = Listing::of($request, $this->store, ReplacementExamService::TABLE, $principal->tenantId, $establishmentId, ['person_id', 'element_id', 'group_id', 'status'], 'created_at DESC, id', $this->access->sqlFilter($perimeter), AttendanceResources::of(...));

        return $this->responder->json($page);
    }

    public function requestReplacement(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $data = [
            'person_id' => (string) $body->string('person_id', 40),
            'element_id' => (string) $body->string('element_id', 40),
            'group_id' => (string) $body->string('group_id', 40),
            'academic_year_id' => (string) $body->string('academic_year_id', 40),
            'absence_date' => (string) $body->date('absence_date'),
            'justification_ref' => (string) $body->string('justification_ref', 80),
            'reason' => (string) $body->string('reason', 500),
        ];
        $body->done();

        return $this->responder->json(AttendanceResources::of($this->replacements->request(AttendanceAccess::principal($request), AttendanceAccess::establishmentId($request), $data)), 201);
    }

    public function scheduleReplacement(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $date = (string) $body->date('exam_date');
        $start = (string) $body->string('start_time', 5);
        $end = (string) $body->string('end_time', 5);
        $room = $body->string('room_id', 40, false);
        foreach (['start_time' => $start, 'end_time' => $end] as $field => $value) {
            if ($value !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
                $body->error($field, 'Heure HH:MM attendue.');
            }
        }
        $body->done();

        return $this->responder->json(AttendanceResources::of($this->replacements->schedule(AttendanceAccess::principal($request), AttendanceAccess::establishmentId($request), self::arg($request, 'id'), $date, $start, $end, $room)));
    }

    public function closeReplacement(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $status = (string) $body->choice('status', ['done', 'cancelled']);
        $body->done();

        return $this->responder->json(AttendanceResources::of($this->replacements->close(AttendanceAccess::principal($request), AttendanceAccess::establishmentId($request), self::arg($request, 'id'), $status)));
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0: string, 1: string, 2: string}
     */
    private static function target(array $query): array
    {
        $errors = [];
        $values = ['element_id' => '', 'group_id' => '', 'academic_year_id' => ''];
        foreach (array_keys($values) as $key) {
            $value = $query[$key] ?? null;
            if (!is_string($value) || $value === '') {
                $errors[$key] = ['Paramètre obligatoire.'];
                continue;
            }
            $values[$key] = $value;
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return [$values['element_id'], $values['group_id'], $values['academic_year_id']];
    }

    public static function arg(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);
        if (!is_string($value) || $value === '') {
            throw HttpException::notFound();
        }

        return $value;
    }
}

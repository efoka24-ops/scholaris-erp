<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Attendance\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Application\CallClock;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Application\TeacherPresenceService;
use Scholaris\Modules\Apps\PublicSchools\Attendance\Infrastructure\PdoTeacherPresence;
use Scholaris\Modules\Apps\PublicSchools\Support\Perimeter;
use Scholaris\Modules\Apps\PublicSchools\Support\ScopedByTerritory;
use Scholaris\Modules\Socle\Identity\Contracts\NamedAccessJournal;

/**
 * Présence des enseignants (EX-PRE-03, EX-PRE-04, RG-PRE-2) : déclaration du directeur, contrôle et échantillon de l'inspection,
 * synthèse mensuelle (DOC-22) et classes sans enseignant. Ce sont des données de personnel : l'inspection (supervision
 * territoriale) les lit avec une finalité déclarée (`purpose=control`), inscrite au journal des consultations nominatives.
 */
final class TeacherPresenceController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly ScopedByTerritory $scope,
        private readonly TeacherPresenceService $service,
        private readonly PdoTeacherPresence $presence,
        private readonly NamedAccessJournal $journal,
        private readonly CallClock $clock,
    ) {
    }

    /** PUT /staff/{id}/presence/{date} : le directeur de l'école déclare la présence d'un enseignant. */
    public function declare(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $perimeter = $this->scope->perimeter($principal, 'public_schools.teacher_presence.write', $establishmentId);
        if (!$perimeter->wholeSchool) {
            $this->scope->assertWritable($principal, $perimeter, null, 'teacher_presence', RequestInput::attribute($request, 'id'));
            throw HttpException::forbidden('La présence des enseignants est déclarée par le directeur de l’école.');
        }
        $data = $this->service->declare($principal, $establishmentId, RequestInput::attribute($request, 'id'), RequestInput::attribute($request, 'date'), RequestInput::body($request));

        return $this->responder->json(['data' => $data]);
    }

    /** PUT /staff/{id}/presence/{date}/check : contrôle de l'inspection. */
    public function check(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $personId = RequestInput::attribute($request, 'id');
        $date = RequestInput::attribute($request, 'date');
        $perimeter = $this->scope->perimeter($principal, 'public_schools.teacher_presence.validate', $establishmentId);
        if ($perimeter->isEmpty()) {
            $this->scope->assertWritable($principal, $perimeter, null, 'teacher_presence', $personId . ':' . $date);
        }
        $body = RequestInput::body($request);
        $result = is_string($body['result'] ?? null) ? $body['result'] : '';
        $this->journalConsultation($principal, $perimeter, 'teacher_presence', $personId . ':' . $date, $body['purpose'] ?? 'control');
        $data = $this->service->check($principal, $establishmentId, $personId, $date, $result);

        return $this->responder->json(['data' => $data]);
    }

    /** GET /teacher-presence?date= : déclarations du jour. */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $date = $this->date($request);
        $perimeter = $this->scope->perimeter($principal, 'public_schools.teacher_presence.read', $establishmentId);
        if ($perimeter->isEmpty()) {
            throw HttpException::notFound();
        }
        if (!$perimeter->wholeSchool && !$perimeter->isTerritorial()) {
            return $this->responder->json(['data' => [], 'meta' => ['next_cursor' => null, 'per_page' => 0]]);
        }
        $this->journalConsultation($principal, $perimeter, 'teacher_presence_day', $date, RequestInput::query($request, 'purpose'));
        $page = RequestInput::page(
            $request,
            fn (?string $after, int $limit): array => $this->presence->ofDay($principal->tenantId, $establishmentId, $date, $after, $limit),
            fn (array $row): array => $this->service->resource($row),
        );

        return $this->responder->json($page);
    }

    /** GET /teacher-presence/control-sample?date= : échantillon aléatoire pour le contrôle de l'inspection. */
    public function sample(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $date = $this->date($request);
        $perimeter = $this->scope->perimeter($principal, 'public_schools.teacher_presence.validate', $establishmentId);
        if ($perimeter->isEmpty()) {
            throw HttpException::notFound();
        }
        $this->journalConsultation($principal, $perimeter, 'teacher_presence_sample', $date, RequestInput::query($request, 'purpose') ?? 'control');

        return $this->responder->json(['data' => $this->service->sample($principal->tenantId, $establishmentId, $date)]);
    }

    /** GET /teacher-presence/summary?month= : synthèse mensuelle (DOC-22). */
    public function summary(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $month = RequestInput::query($request, 'month');
        if ($month === null) {
            throw HttpException::validation(['month' => ['Mois au format AAAA-MM requis.']]);
        }
        $perimeter = $this->scope->perimeter($principal, 'public_schools.teacher_presence.read', $establishmentId);
        if ($perimeter->isEmpty()) {
            throw HttpException::notFound();
        }
        if (!$perimeter->wholeSchool && !$perimeter->isTerritorial()) {
            throw HttpException::forbidden('La synthèse mensuelle est réservée au directeur et à l’inspection.');
        }
        $this->journalConsultation($principal, $perimeter, 'teacher_presence_month', $month, RequestInput::query($request, 'purpose'));

        return $this->responder->json(['data' => $this->service->monthlySummary($principal->tenantId, $establishmentId, $month)]);
    }

    /** GET /uncovered-classes?date= : classes sans enseignant (RG-PRE-2), sans donnée nominative. */
    public function uncovered(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $date = $this->date($request);
        $perimeter = $this->scope->perimeter($principal, 'public_schools.teacher_presence.read', $establishmentId);
        if ($perimeter->isEmpty()) {
            throw HttpException::notFound();
        }
        $page = RequestInput::page(
            $request,
            fn (?string $after, int $limit): array => $this->presence->uncoveredOn($principal->tenantId, $establishmentId, $date, $after, $limit),
            static fn (array $row): array => ['id' => (string) $row['id'], 'structure_id' => (string) $row['structure_id'], 'date' => (string) $row['day_on']],
        );
        $page['data'] = array_values(array_filter($page['data'], fn (array $row): bool => $this->scope->covers($perimeter, $row['structure_id'])));

        return $this->responder->json($page);
    }

    private function date(ServerRequestInterface $request): string
    {
        $date = RequestInput::query($request, 'date') ?? $this->clock->today();
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw HttpException::validation(['date' => ['Date au format AAAA-MM-JJ attendue.']]);
        }

        return $date;
    }

    /**
     * Données de personnel lues par la supervision : finalité déclarée obligatoire, lecture inscrite au journal des consultations
     * nominatives (Identity). Dans l'école, lecture ordinaire.
     */
    private function journalConsultation(Principal $principal, Perimeter $perimeter, string $recordType, string $recordId, mixed $purpose): void
    {
        if ($perimeter->isEmpty()) {
            throw HttpException::notFound();
        }
        if ($perimeter->isInSchool() || !$perimeter->isTerritorial()) {
            return;
        }
        if (!is_string($purpose) || !in_array($purpose, ScopedByTerritory::PURPOSES, true)) {
            throw HttpException::validation(['purpose' => ['La finalité de la consultation est obligatoire : ' . implode(', ', ScopedByTerritory::PURPOSES) . '.']]);
        }
        $this->journal->record($principal->tenantId, $perimeter->establishmentId, $principal->userId, $recordType, $recordId, $purpose);
    }
}

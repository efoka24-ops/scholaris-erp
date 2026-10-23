<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Staff\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Staff\Application\StaffClock;
use Scholaris\Modules\Apps\PublicSchools\Staff\Application\StaffService;
use Scholaris\Modules\Apps\PublicSchools\Support\Perimeter;
use Scholaris\Modules\Apps\PublicSchools\Support\ScopedByTerritory;
use Scholaris\Modules\Socle\Identity\Contracts\NamedAccessJournal;

/**
 * PER : fiche du personnel, formations, mutations et besoins (EX-PER-01 à EX-PER-04). Dans l'école, le directeur voit et écrit tout ;
 * la supervision territoriale lit statut, grade et affectations seulement (RG-PER-2), avec une finalité déclarée (`purpose`) inscrite
 * au journal des consultations nominatives. Les besoins en enseignants sont des effectifs, sans nom.
 */
final class StaffController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly ScopedByTerritory $scope,
        private readonly StaffService $service,
        private readonly NamedAccessJournal $journal,
        private readonly StaffClock $clock,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $perimeter = $this->readable($principal, $establishmentId);
        $this->consult($principal, $perimeter, 'staff_list', $establishmentId, RequestInput::query($request, 'purpose'));
        $full = $perimeter->wholeSchool;
        $page = RequestInput::page(
            $request,
            fn (?string $after, int $limit): array => $this->service->listing($principal->tenantId, $establishmentId, $after, $limit, $full),
            static fn (array $row): array => $row,
        );

        return $this->responder->json($page);
    }

    public function showProfile(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $personId = RequestInput::attribute($request, 'id');
        $perimeter = $this->readable($principal, $establishmentId);
        $this->consult($principal, $perimeter, 'staff', $personId, RequestInput::query($request, 'purpose'));

        return $this->responder->json(['data' => $this->service->profile($principal->tenantId, $establishmentId, $personId, $perimeter->wholeSchool)]);
    }

    public function saveProfile(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $this->writable($principal, $establishmentId, RequestInput::attribute($request, 'id'));

        return $this->responder->json(['data' => $this->service->saveProfile($principal, $establishmentId, RequestInput::attribute($request, 'id'), RequestInput::body($request))]);
    }

    public function trainings(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $personId = RequestInput::attribute($request, 'id');
        $perimeter = $this->readable($principal, $establishmentId);
        if (!$perimeter->wholeSchool) {
            throw HttpException::forbidden('Les formations relèvent de la carrière : elles ne sont lisibles que dans l’école (RG-PER-2).');
        }
        $page = RequestInput::page(
            $request,
            fn (?string $after, int $limit): array => $this->service->trainings($principal->tenantId, $establishmentId, $personId, $after, $limit),
            static fn (array $row): array => ['id' => (string) $row['id'], 'title' => (string) $row['title'], 'starts_on' => (string) $row['starts_on'], 'ends_on' => $row['ends_on'], 'organiser' => $row['organiser']],
        );

        return $this->responder->json($page);
    }

    public function addTraining(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $this->writable($principal, $establishmentId, RequestInput::attribute($request, 'id'));

        return $this->responder->json(['data' => $this->service->addTraining($principal, $establishmentId, RequestInput::attribute($request, 'id'), RequestInput::body($request))], 201);
    }

    public function move(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $this->writable($principal, $establishmentId, RequestInput::attribute($request, 'id'));

        return $this->responder->json(['data' => $this->service->move($principal, $establishmentId, RequestInput::attribute($request, 'id'), RequestInput::body($request))], 201);
    }

    public function moves(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $personId = RequestInput::attribute($request, 'id');
        $perimeter = $this->readable($principal, $establishmentId);
        $this->consult($principal, $perimeter, 'staff', $personId, RequestInput::query($request, 'purpose'));

        return $this->responder->json(['data' => $this->service->moves($principal->tenantId, $establishmentId, $personId)]);
    }

    public function needs(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $this->readable($principal, $establishmentId);

        return $this->responder->json(['data' => $this->service->needs($principal->tenantId, $establishmentId, $this->clock->today())]);
    }

    private function readable(Principal $principal, string $establishmentId): Perimeter
    {
        $perimeter = $this->scope->perimeter($principal, 'public_schools.staff.read', $establishmentId);
        if ($perimeter->isEmpty() || (!$perimeter->wholeSchool && !$perimeter->isTerritorial())) {
            throw HttpException::notFound();
        }

        return $perimeter;
    }

    /** Écriture : le directeur de l'école seulement (périmètre école entière). */
    private function writable(Principal $principal, string $establishmentId, string $objectId): void
    {
        $perimeter = $this->scope->perimeter($principal, 'public_schools.staff.write', $establishmentId);
        if (!$perimeter->wholeSchool) {
            $this->scope->assertWritable($principal, $perimeter, null, 'staff', $objectId);
            throw HttpException::forbidden('La gestion du personnel relève du directeur de l’école.');
        }
    }

    /** Lecture de données de personnel par la supervision : finalité obligatoire et journal des consultations nominatives. */
    private function consult(Principal $principal, Perimeter $perimeter, string $recordType, string $recordId, ?string $purpose): void
    {
        if ($perimeter->wholeSchool) {
            return;
        }
        if ($purpose === null || !in_array($purpose, ScopedByTerritory::PURPOSES, true)) {
            throw HttpException::validation(['purpose' => ['La finalité de la consultation est obligatoire : ' . implode(', ', ScopedByTerritory::PURPOSES) . '.']]);
        }
        $this->journal->record($principal->tenantId, $perimeter->establishmentId, $principal->userId, $recordType, $recordId, $purpose);
    }
}

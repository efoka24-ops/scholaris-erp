<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Timetable\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Listing;
use Scholaris\Modules\Apps\Secondary\Support\ScopedByClass;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Apps\Secondary\Timetable\Application\AssignmentService;
use Scholaris\Modules\Apps\Secondary\Timetable\Application\ChangeService;
use Scholaris\Modules\Apps\Secondary\Timetable\Application\GenerationService;
use Scholaris\Modules\Apps\Secondary\Timetable\Application\TimetableContext;
use Scholaris\Modules\Apps\Secondary\Timetable\Application\TimetableService;
use Scholaris\Modules\Apps\Secondary\Timetable\Infrastructure\PdoTeachingAssignments;

/**
 * Emploi du temps du secondaire (T860, T861) : affectations, plages, disponibilités, salle de classe, vue, pose manuelle,
 * conflits, génération assistée, remplacements et permutations (NOT-14), DOC-07. Lecture : `timetable.read` (ou l'enseignant pour
 * son propre emploi du temps) ; écriture : `timetable.manage` (censeur).
 */
final class TimetableController extends Controller
{
    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly AssignmentService $assignments,
        private readonly TimetableService $timetable,
        private readonly GenerationService $generation,
        private readonly ChangeService $changes,
        private readonly Listing $listing,
        private readonly ScopedByClass $scoped,
        private readonly SecondaryClock $clock,
    ) {
        parent::__construct($responder, $access, $store);
    }

    public function assignments(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        [$perimeter, $own] = $this->timetable->readPerimeter($principal, $establishmentId);
        $scope = $own === null ? $this->scoped->sqlFilter($perimeter) : ['teacher_person_id = ?', [$own]];
        $map = static fn (array $row): array => AssignmentService::assignment($row, null);

        return $this->page($this->listing->page($request, PdoTeachingAssignments::ASSIGNMENTS, $principal->tenantId, $establishmentId, ['academic_year_id', 'class_id', 'teacher_person_id', 'status', 'group_code'], ['subject_code', 'created_at'], 'class_id, subject_code', $map, [], $scope));
    }

    public function teachers(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->page(['data' => $this->assignments->teachers($principal, $establishmentId)]);
    }

    public function createAssignment(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->assignments->create($principal, $establishmentId, Input::body($request)), 201);
    }

    public function updateAssignment(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->assignments->update($principal, $establishmentId, self::id($request), Input::body($request)));
    }

    public function bands(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->assignments->bands($principal, $establishmentId, self::query($request, 'academic_year_id')));
    }

    public function setBands(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->assignments->setBands($principal, $establishmentId, Input::body($request)));
    }

    public function availabilities(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, TimetableContext::READ);

        return $this->json($this->assignments->availabilities($principal, $establishmentId, self::query($request, 'academic_year_id'), self::query($request, 'teacher_person_id')));
    }

    public function setAvailabilities(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->assignments->setAvailabilities($principal, $establishmentId, Input::body($request)));
    }

    public function setClassRoom(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->assignments->setClassRoom($principal, $establishmentId, Input::body($request)));
    }

    public function view(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->timetable->readPerimeter($principal, $establishmentId);
        $filters = [];
        foreach (['academic_year_id', 'class_id', 'teacher_person_id', 'room_id'] as $name) {
            $value = self::optional($request, $name);
            if ($value !== null) {
                $filters[$name] = $value;
            }
        }
        $on = self::optional($request, 'on');
        if ($on !== null) {
            $filters['on'] = self::date($on, 'on');
        }

        return $this->json($this->timetable->view($principal, $establishmentId, $filters));
    }

    public function createEntry(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->timetable->create($principal, $establishmentId, Input::body($request)), 201);
    }

    public function checkEntry(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->timetable->check($principal, $establishmentId, Input::body($request)));
    }

    public function deleteEntry(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->timetable->delete($principal, $establishmentId, self::id($request));

        return $this->json(['id' => self::id($request), 'status' => 'removed']);
    }

    public function conflicts(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $conflicts = $this->timetable->conflicts($principal, $establishmentId, self::optional($request, 'academic_year_id'));

        return $this->json(['conflicts' => $conflicts, 'count' => count($conflicts)]);
    }

    public function generate(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $result = $this->generation->generate($principal, $establishmentId, Input::body($request));

        return $this->json($result, $result['applied'] ? 201 : 200);
    }

    public function runs(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, TimetableContext::MANAGE);
        $map = static fn (array $row): array => GenerationService::run($row);

        return $this->page($this->listing->page($request, GenerationService::RUNS, $principal->tenantId, $establishmentId, ['academic_year_id', 'applied'], ['created_at'], 'created_at DESC', $map));
    }

    public function change(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->changes->change($principal, $establishmentId, Input::body($request)), 201);
    }

    public function changes(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, TimetableContext::READ);
        $map = static fn (array $row): array => ChangeService::resource($row);

        return $this->page($this->listing->page($request, ChangeService::CHANGES, $principal->tenantId, $establishmentId, ['academic_year_id', 'class_id', 'change_kind'], ['from_date', 'created_at'], 'from_date DESC', $map, [], $this->scoped->sqlFilter($perimeter)));
    }

    public function document(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->timetable->readPerimeter($principal, $establishmentId);
        $on = self::optional($request, 'on');
        $date = $on === null ? $this->clock->today() : self::date($on, 'on');

        return $this->json($this->timetable->document($principal, $establishmentId, self::query($request, 'view'), self::query($request, 'id'), $date));
    }

    private static function optional(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getQueryParams()[$name] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $value) !== 1) {
            throw HttpException::validation([$name => ['Identifiant invalide.']]);
        }

        return $value;
    }

    private static function query(ServerRequestInterface $request, string $name): string
    {
        return self::optional($request, $name) ?? throw HttpException::validation([$name => ['Paramètre obligatoire.']]);
    }

    private static function date(string $value, string $name): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw HttpException::validation([$name => ['Date au format AAAA-MM-JJ attendue.']]);
        }

        return $value;
    }
}

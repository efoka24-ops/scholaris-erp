<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Academic\Application\Access;
use Scholaris\Modules\Apps\Higher\Academic\Application\CalendarService;
use Scholaris\Modules\Apps\Higher\Academic\Application\Listing;
use Scholaris\Modules\Apps\Higher\Academic\Application\Permissions;
use Scholaris\Modules\Apps\Higher\Academic\Application\RegulationService;
use Scholaris\Modules\Apps\Higher\Academic\Domain\CalendarEntryKind;
use Scholaris\Modules\Apps\Higher\Academic\Domain\Regime;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;

/** Règlement des études (EX-ACA-05, RG-ACA-3) et calendrier académique (EX-ACA-06) d'une école. */
final class RegulationController extends Controller
{
    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        Listing $listing,
        private readonly RegulationService $regulations,
        private readonly CalendarService $calendar,
    ) {
        parent::__construct($responder, $access, $store, $listing);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, 'hi_regulations', Permissions::READ_REGISTERS, ['school' => 'school_id'], ['school_id', 'regime', 'academic_year_id', 'status'], ['academic_year_id', 'regime'], 'academic_year_id', Resources::regulation(...));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->json(Resources::regulation($this->readable($request, 'hi_regulations', self::id($request), Permissions::READ_REGISTERS, static fn (array $r): array => ['school' => (string) $r['school_id']], 'Règlement introuvable.')));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('school_id');
        $input->enum('regime', Regime::values());
        $input->reference('academic_year_id');
        $input->custom('parameters', self::parameters(...), false);

        return $this->json(Resources::regulation($this->regulations->create($principal, $establishmentId, $input->done())), 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->custom('parameters', self::parameters(...));
        $values = $input->done();

        return $this->json(Resources::regulation($this->regulations->updateDraft($principal, $establishmentId, self::id($request), (array) $values['parameters'])));
    }

    public function freeze(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json(Resources::regulation($this->regulations->freeze($principal, $establishmentId, self::id($request))));
    }

    /** POST /higher/regulations/{id}/amend : directeur académique, double authentification récente, motif (RG-ACA-3). */
    public function amend(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->custom('parameters', self::parameters(...));
        $input->string('reason', 500);
        $values = $input->done();

        return $this->json(Resources::regulation($this->regulations->amend($principal, $establishmentId, self::id($request), (array) $values['parameters'], (string) $values['reason'])));
    }

    public function revisions(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $row = $this->readable($request, 'hi_regulations', self::id($request), Permissions::READ_REGISTERS, static fn (array $r): array => ['school' => (string) $r['school_id']], 'Règlement introuvable.');
        $revisions = $this->store->select('hi_regulation_revisions', $principal->tenantId, $establishmentId, ['regulation_id' => (string) $row['id']], 'version');

        return $this->responder->json(['data' => array_map(Resources::revision(...), $revisions)]);
    }

    public function calendarEntries(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, 'hi_calendar_entries', Permissions::READ_REGISTERS, ['school' => 'school_id'], ['school_id', 'academic_year_id', 'entry_kind'], ['starts_on', 'entry_kind'], 'starts_on', Resources::calendarEntry(...));
    }

    public function createCalendarEntry(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('school_id');
        $input->reference('academic_year_id');
        $this->calendarFields($input);

        return $this->json(Resources::calendarEntry($this->calendar->create($principal, $establishmentId, $input->done())), 201);
    }

    public function updateCalendarEntry(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $this->calendarFields($input);

        return $this->json(Resources::calendarEntry($this->calendar->update($principal, $establishmentId, self::id($request), $input->done())));
    }

    public function deleteCalendarEntry(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->calendar->delete($principal, $establishmentId, self::id($request));

        return $this->json(['id' => self::id($request), 'deleted' => true]);
    }

    private function calendarFields(Input $input): void
    {
        $input->enum('entry_kind', CalendarEntryKind::values());
        $input->string('label');
        $input->date('starts_on');
        $input->date('ends_on');
        $input->int('semester_ordinal', 1, 12, false, true);
    }

    /** @return array<string, mixed> */
    private static function parameters(mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \InvalidArgumentException('Objet de paramètres attendu.');
        }

        return $value;
    }
}

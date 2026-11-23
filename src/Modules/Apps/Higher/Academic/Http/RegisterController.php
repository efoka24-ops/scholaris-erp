<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Academic\Application\AcademicClock;
use Scholaris\Modules\Apps\Higher\Academic\Application\Access;
use Scholaris\Modules\Apps\Higher\Academic\Application\AuthorizationService;
use Scholaris\Modules\Apps\Higher\Academic\Application\HigherEnrolmentGuard;
use Scholaris\Modules\Apps\Higher\Academic\Application\Listing;
use Scholaris\Modules\Apps\Higher\Academic\Application\Permissions;
use Scholaris\Modules\Apps\Higher\Academic\Application\TutelageService;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationRegistry;

/** Registres des autorisations MINESUP (EX-ACA-03, API-02) et des tutelles (EX-ACA-04) ; contrôle d'éligibilité (RG-ACA-1/2). */
final class RegisterController extends Controller
{
    private const SCOPE = ['school' => 'school_id', 'department' => 'department_id', 'program' => 'program_id'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        Listing $listing,
        private readonly AuthorizationService $authorizations,
        private readonly TutelageService $tutelages,
        private readonly AuthorizationRegistry $registry,
        private readonly HigherEnrolmentGuard $guard,
        private readonly AcademicClock $clock,
    ) {
        parent::__construct($responder, $access, $store, $listing);
    }

    public function authorizations(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $today = $this->clock->today();

        return $this->page($request, 'hi_authorizations', Permissions::READ_REGISTERS, self::SCOPE, ['program_id', 'school_id'], ['created_at'], 'created_at', fn (array $row): array => Resources::authorization(
            $row,
            $this->registry->find($principal->tenantId, $establishmentId, (string) $row['id']),
            $today,
        ));
    }

    /** GET /higher/authorizations/expiring?days=90 : autorisations du périmètre qui expirent dans les N jours (non expirées). */
    public function expiring(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $raw = $request->getQueryParams()['days'] ?? '90';
        $days = filter_var($raw, FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 365) {
            throw HttpException::validation(['days' => ['Nombre de jours entre 1 et 365 attendu.']]);
        }
        $perimeter = $this->access->require($principal, $establishmentId, Permissions::READ_REGISTERS, Access::selectedSchool($request));
        [$scopeSql, $scopeParams] = $this->access->sqlFilter($perimeter, self::SCOPE);
        $today = $this->clock->today();
        $items = [];
        foreach ($this->store->select('hi_authorizations', $principal->tenantId, $establishmentId, [], 'id', 5000, $scopeSql, $scopeParams) as $row) {
            $item = Resources::authorization($row, $this->registry->find($principal->tenantId, $establishmentId, (string) $row['id']), $today);
            if (($item['status'] ?? null) === 'valid' && $item['days_left'] <= $days) {
                $items[] = $item;
            }
        }
        usort($items, static fn (array $a, array $b): int => $a['days_left'] <=> $b['days_left']);

        return $this->responder->json(['data' => $items, 'meta' => ['days' => $days, 'today' => $today]]);
    }

    public function showAuthorization(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $row = $this->readable($request, 'hi_authorizations', self::id($request), Permissions::READ_REGISTERS, AuthorizationService::scope(...), 'Autorisation introuvable.');

        return $this->json(Resources::authorization($row, $this->registry->find($principal->tenantId, $establishmentId, (string) $row['id']), $this->clock->today()));
    }

    public function createAuthorization(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = $this->authorizationInput($request, false);
        [$row, $info] = $this->authorizations->register($principal, $establishmentId, $input->done());

        return $this->json(Resources::authorization($row, $info, $this->clock->today()), 201);
    }

    public function updateAuthorization(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = $this->authorizationInput($request, true);
        [$row, $info] = $this->authorizations->update($principal, $establishmentId, self::id($request), $input->done());

        return $this->json(Resources::authorization($row, $info, $this->clock->today()));
    }

    public function deleteAuthorization(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->authorizations->remove($principal, $establishmentId, self::id($request));

        return $this->json(['id' => self::id($request), 'deleted' => true]);
    }

    public function tutelages(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, 'hi_tutelages', Permissions::READ_REGISTERS, self::SCOPE, ['program_id', 'academic_year_id', 'school_id'], ['academic_year_id', 'university_name'], 'academic_year_id', Resources::tutelage(...));
    }

    public function showTutelage(ServerRequestInterface $request): ResponseInterface
    {
        // Lecture d'abord dans une variable : un callable de première classe dans un appel imbriqué fait planter PHP 8.1 quand
        // l'appel intérieur lève une exception (404 ici). Voir tests/Architecture/NoNestedFirstClassCallableTest.php.
        $row = $this->readable($request, 'hi_tutelages', self::id($request), Permissions::READ_REGISTERS, AuthorizationService::scope(...), 'Tutelle introuvable.');

        return $this->json(Resources::tutelage($row));
    }

    public function createTutelage(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('program_id');
        $input->reference('academic_year_id');
        $input->string('university_name');
        $this->tutelageFields($input);

        return $this->json(Resources::tutelage($this->tutelages->create($principal, $establishmentId, $input->done())), 201);
    }

    public function updateTutelage(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $input->string('university_name');
        $this->tutelageFields($input);

        return $this->json(Resources::tutelage($this->tutelages->update($principal, $establishmentId, self::id($request), $input->done())));
    }

    /** GET /higher/eligibility?structure_id=&academic_year_id=&date= : la garde d'inscription IPES autoriserait-elle ? */
    public function eligibility(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, Permissions::READ_REGISTERS);
        $query = $request->getQueryParams();
        $structure = $query['structure_id'] ?? null;
        $year = $query['academic_year_id'] ?? null;
        $date = $query['date'] ?? $this->clock->today();
        if (!is_string($structure) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $structure) !== 1) {
            throw HttpException::validation(['structure_id' => ['Identifiant de niveau ou de groupe attendu.']]);
        }
        $on = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Africa/Douala')) : false;
        if ($on === false || $on->format('Y-m-d') !== $date) {
            throw HttpException::validation(['date' => ['Date au format AAAA-MM-JJ attendue.']]);
        }
        $reason = $this->guard->refusalFor($principal->tenantId, $establishmentId, $structure, is_string($year) && $year !== '' ? $year : null, $on->setTime(12, 0));

        return $this->json(['structure_id' => $structure, 'academic_year_id' => $year, 'date' => $date, 'allowed' => $reason === null, 'reason' => $reason]);
    }

    private function authorizationInput(ServerRequestInterface $request, bool $partial): Input
    {
        $input = Input::of($request, $partial);
        if (!$partial) {
            $input->reference('program_id');
        }
        $input->string('decree_number', 80);
        $input->date('decree_date', false, true);
        $input->string('authority', 160, false, true);
        $input->date('valid_from');
        $input->date('valid_to');
        $input->references('level_ids', 20, false, true);
        $input->reference('document_id', false, true);

        return $input;
    }

    private function tutelageFields(Input $input): void
    {
        $input->string('convention_reference', 80, false, true);
        $input->reference('convention_document_id', false, true);
        $input->date('convention_starts_on', false, true);
        $input->date('convention_ends_on', false, true);
        $input->string('approved_programme_ref', 120, false, true);
        $input->custom('contacts', TutelageService::contacts(...), false);
    }
}

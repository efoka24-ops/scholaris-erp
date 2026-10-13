<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Academic\Application\Access;
use Scholaris\Modules\Apps\Higher\Academic\Application\Listing;
use Scholaris\Modules\Apps\Higher\Academic\Application\Permissions;
use Scholaris\Modules\Apps\Higher\Academic\Application\StructureService;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitSettingsReader;

/** Écoles internes et départements (EX-ACA-01) ; paramètres effectifs d'une école et surcharges (RG-ORG-5). */
final class SchoolController extends Controller
{
    /** Paramètres Higher surchargeables par école (D6), avec leur valeur par défaut. */
    public const SCHOOL_SETTINGS = [
        'higher.curriculum.credits_per_semester' => 30,
        'higher.curriculum.hours_per_credit' => 25,
        'higher.alerts.expiry_days' => [90, 30, 7],
        'higher.security.recent_2fa_seconds' => 600,
    ];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        Listing $listing,
        private readonly StructureService $structures,
        private readonly OrgUnitSettingsReader $settings,
    ) {
        parent::__construct($responder, $access, $store, $listing);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, 'hi_schools', Permissions::READ, ['school' => 'id'], ['code'], ['code', 'name'], 'code', Resources::school(...));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $row = $this->readable($request, 'hi_schools', self::id($request), Permissions::READ, static fn (array $r): array => StructureService::scopeOf('school', $r), 'École introuvable.');

        return $this->json(Resources::school($row));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->code('code');
        $input->string('name');
        $input->reference('director_person_id', false, true);

        return $this->json(Resources::school($this->structures->createSchool($principal, $establishmentId, $input->done())), 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $input->code('code');
        $input->string('name');
        $input->reference('director_person_id', false, true);

        return $this->json(Resources::school($this->structures->updateSchool($principal, $establishmentId, self::id($request), $input->done())));
    }

    /** GET /higher/schools/{id}/settings : valeur de l'établissement, valeur de l'école, surcharge visible (RG-ORG-5). */
    public function settings(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $row = $this->readable($request, 'hi_schools', self::id($request), Permissions::READ, static fn (array $r): array => StructureService::scopeOf('school', $r), 'École introuvable.');
        $items = [];
        foreach (self::SCHOOL_SETTINGS as $key => $default) {
            $establishment = $this->settings->valueForOrgUnit($principal->tenantId, $establishmentId, null, $key, $default);
            $school = $this->settings->valueForOrgUnit($principal->tenantId, $establishmentId, (string) $row['id'], $key, $default);
            $items[] = ['key' => $key, 'establishment_value' => $establishment, 'school_value' => $school, 'overridden' => $school !== $establishment];
        }

        return $this->responder->json(['data' => $items, 'meta' => ['school_id' => $row['id']]]);
    }

    public function departments(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, 'hi_departments', Permissions::READ, ['school' => 'school_id', 'department' => 'id'], ['school_id', 'code'], ['code', 'name'], 'code', Resources::department(...));
    }

    public function createDepartment(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('school_id');
        $input->code('code');
        $input->string('name');
        $input->reference('head_person_id', false, true);

        return $this->json(Resources::department($this->structures->createDepartment($principal, $establishmentId, $input->done())), 201);
    }

    public function updateDepartment(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $input->code('code');
        $input->string('name');
        $input->reference('head_person_id', false, true);

        return $this->json(Resources::department($this->structures->updateDepartment($principal, $establishmentId, self::id($request), $input->done())));
    }
}

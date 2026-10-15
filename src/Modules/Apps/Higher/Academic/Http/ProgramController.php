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
use Scholaris\Modules\Apps\Higher\Academic\Domain\Regime;
use Scholaris\Modules\Apps\Higher\Academic\Domain\StudyMode;
use Scholaris\Modules\Apps\Higher\Academic\Domain\TeachingLanguage;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;

/** Filières et spécialités (EX-ACA-01/02, API-01), niveaux et groupes. */
final class ProgramController extends Controller
{
    private const PROGRAM_SCOPE = ['school' => 'school_id', 'department' => 'department_id', 'program' => 'id'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        Listing $listing,
        private readonly StructureService $structures,
    ) {
        parent::__construct($responder, $access, $store, $listing);
    }

    /** GET /higher/programs (API-01) : filtres école, département, régime, mode, statut ; sélecteur `school_id`. */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page(
            $request,
            'hi_programs',
            Permissions::READ,
            self::PROGRAM_SCOPE,
            ['school_id', 'department_id', 'regime', 'study_mode', 'status', 'code'],
            ['code', 'name', 'regime'],
            'code',
            Resources::program(...),
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $row = $this->readable($request, 'hi_programs', self::id($request), Permissions::READ, static fn (array $r): array => StructureService::scopeOf('program', $r), 'Filière introuvable.');

        return $this->json(Resources::program($row));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('department_id');
        $input->reference('parent_program_id', false, true);
        $input->code('code');
        $input->string('name');
        $input->enum('regime', Regime::values());
        $input->enum('teaching_language', TeachingLanguage::values(), false);
        $input->enum('study_mode', StudyMode::values(), false);
        $input->int('duration_years', 1, 8);

        return $this->json(Resources::program($this->structures->createProgram($principal, $establishmentId, $input->done())), 201);
    }

    /** PATCH : le régime est accepté en entrée pour être refusé explicitement s'il change (RG-ORG-7, 409). */
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $input->code('code');
        $input->string('name');
        $input->enum('regime', Regime::values());
        $input->enum('teaching_language', TeachingLanguage::values());
        $input->enum('study_mode', StudyMode::values());
        $input->int('duration_years', 1, 8);
        $input->enum('status', ['active', 'closed']);

        return $this->json(Resources::program($this->structures->updateProgram($principal, $establishmentId, self::id($request), $input->done())));
    }

    public function levels(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page(
            $request,
            'hi_levels',
            Permissions::READ,
            ['school' => 'school_id', 'department' => 'department_id', 'program' => 'program_id', 'level' => 'id'],
            ['program_id', 'school_id'],
            ['ordinal', 'code'],
            'ordinal',
            Resources::level(...),
        );
    }

    public function createLevel(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('program_id');
        $input->int('ordinal', 1, 8);
        $input->code('code');
        $input->string('name');

        return $this->json(Resources::level($this->structures->createLevel($principal, $establishmentId, $input->done())), 201);
    }

    public function updateLevel(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $input->code('code');
        $input->string('name');

        return $this->json(Resources::level($this->structures->updateLevel($principal, $establishmentId, self::id($request), $input->done())));
    }

    public function groups(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page(
            $request,
            'hi_groups',
            Permissions::READ,
            ['school' => 'school_id', 'department' => 'department_id', 'program' => 'program_id', 'level' => 'level_id', 'group' => 'id'],
            ['level_id', 'program_id', 'school_id'],
            ['code', 'name'],
            'code',
            Resources::group(...),
        );
    }

    public function createGroup(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('level_id');
        $input->code('code', 20);
        $input->string('name');
        $input->int('capacity', 1, 10000, false, true);

        return $this->json(Resources::group($this->structures->createGroup($principal, $establishmentId, $input->done())), 201);
    }

    public function updateGroup(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $input->code('code', 20);
        $input->string('name');
        $input->int('capacity', 1, 10000, false, true);

        return $this->json(Resources::group($this->structures->updateGroup($principal, $establishmentId, self::id($request), $input->done())));
    }
}

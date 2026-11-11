<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\Setup\Application\CurriculumService;
use Scholaris\Modules\Apps\Secondary\Setup\Application\RoomRulesService;
use Scholaris\Modules\Apps\Secondary\Setup\Application\StructureService;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Listing;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Paramétrage de l'établissement (T821, T822) : niveaux, séries et spécialités ouvertes, classes, matières, coefficients et
 * volumes versionnés, gel et copie d'une année, profils de salles, règlement intérieur. Toutes les écritures portent sur tout
 * l'établissement et exigent un profil ouvert.
 */
final class SetupController extends Controller
{
    /** Salles : lecteurs du paramétrage et de l'emploi du temps (noms des salles, aucune donnée personnelle). */
    private const ROOMS_READ = [...StructureService::READ, 'secondary.timetable.read', 'secondary.timetable.manage', 'secondary.timetable.validate', 'secondary.lesson.log'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly StructureService $structure,
        private readonly CurriculumService $curriculum,
        private readonly RoomRulesService $roomRules,
        private readonly Listing $listing,
    ) {
        parent::__construct($responder, $access, $store);
    }

    public function levels(ServerRequestInterface $request): ResponseInterface
    {
        $map = static fn (array $row): array => StructureService::level($row);

        return $this->listPage($request, 'se_levels', ['code', 'cycle_code', 'section_code', 'teaching_kind', 'active'], ['code', 'sort_order'], 'section_code, teaching_kind, sort_order', $map);
    }

    /**
     * Liste paginée (curseur) d'une table de paramétrage, après contrôle de la permission de lecture.
     *
     * @param list<string> $filters
     * @param list<string> $sorts
     * @param \Closure(array<string, mixed>): array<string, mixed> $map
     * @param array<string, scalar|null> $fixed
     * @param list<string> $rights droits de lecture admis (paramétrage par défaut)
     */
    private function listPage(ServerRequestInterface $request, string $table, array $filters, array $sorts, string $defaultSort, \Closure $map, array $fixed = [], array $rights = StructureService::READ): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, $rights);

        return $this->page($this->listing->page($request, $table, $principal->tenantId, $establishmentId, $filters, $sorts, $defaultSort, $map, $fixed));
    }

    public function createLevel(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->structure->createLevel($principal, $establishmentId, Input::body($request)), 201);
    }

    public function loadExampleLevels(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->responder->json(['data' => $this->structure->loadExampleLevels($principal, $establishmentId)], 201);
    }

    public function updateLevel(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->structure->updateLevel($principal, $establishmentId, self::id($request), Input::body($request)));
    }

    public function streams(ServerRequestInterface $request): ResponseInterface
    {
        $map = static fn (array $row): array => StructureService::stream($row);

        return $this->listPage($request, 'se_streams', ['code', 'cycle_code', 'section_code', 'teaching_kind', 'active'], ['code'], 'cycle_code, code', $map);
    }

    public function openStream(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->structure->openStream($principal, $establishmentId, Input::body($request)), 201);
    }

    public function updateStream(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->structure->updateStream($principal, $establishmentId, self::id($request), Input::body($request)));
    }

    public function classes(ServerRequestInterface $request): ResponseInterface
    {
        $map = static fn (array $row): array => StructureService::schoolClass($row);
        $year = self::query($request, 'academic_year_id');

        return $this->listPage($request, 'se_class_profiles', ['level_code', 'cycle_code', 'section_code', 'stream_id', 'level_id'], ['label', 'level_code'], 'academic_year_id, level_code, label', $map, $year === null ? [] : ['academic_year_id' => $year], StructureService::CLASS_READ);
    }

    public function createClass(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->structure->createClass($principal, $establishmentId, Input::body($request)), 201);
    }

    public function updateClass(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->structure->updateClass($principal, $establishmentId, self::id($request), Input::body($request)));
    }

    public function subjects(ServerRequestInterface $request): ResponseInterface
    {
        $map = static fn (array $row): array => CurriculumService::subject($row);

        return $this->listPage($request, 'se_subjects', ['code', 'active'], ['code', 'label'], 'code', $map);
    }

    public function createSubject(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->curriculum->createSubject($principal, $establishmentId, Input::body($request)), 201);
    }

    public function updateSubject(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->curriculum->updateSubject($principal, $establishmentId, self::id($request), Input::body($request)));
    }

    public function coefficients(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $year = self::query($request, 'academic_year_id');
        if ($year === null) {
            throw HttpException::validation(['academic_year_id' => ['Année scolaire obligatoire.']]);
        }
        $labels = $this->curriculum->subjectLabels($principal->tenantId, $establishmentId);
        $map = static fn (array $row): array => CurriculumService::coefficient($row, $labels);
        $fixed = ['academic_year_id' => $year];
        if (self::query($request, 'history') !== '1') {
            $fixed['superseded_at'] = null;
        }

        return $this->listPage($request, 'se_subject_coefficients', ['level_code', 'stream_id', 'se_subject_id', 'subject_role'], ['level_code', 'version_no'], 'level_code, stream_id, se_subject_id, version_no', $map, $fixed);
    }

    public function freezes(ServerRequestInterface $request): ResponseInterface
    {
        $map = static fn (array $row): array => [
            'id' => (string) $row['id'], 'academic_year_id' => (string) $row['academic_year_id'], 'reason' => (string) $row['reason'],
            'frozen_by' => (string) $row['frozen_by'], 'frozen_at' => (string) $row['created_at'],
        ];

        return $this->listPage($request, 'se_coefficient_freezes', ['academic_year_id'], [], 'created_at', $map);
    }

    public function setCoefficient(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->curriculum->setCoefficient($principal, $establishmentId, Input::body($request)), 201);
    }

    public function freezeCoefficients(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->curriculum->freezeYear($principal, $establishmentId, Input::body($request)), 201);
    }

    public function copyCoefficients(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->curriculum->copyYear($principal, $establishmentId, Input::body($request)), 201);
    }

    public function rooms(ServerRequestInterface $request): ResponseInterface
    {
        $map = static fn (array $row): array => RoomRulesService::room($row);

        // Les salles se lisent aussi depuis l'emploi du temps (FIX-SEC) : choisies et affichées par leur nom, jamais par leur identifiant.
        return $this->listPage($request, 'se_room_profiles', ['room_kind', 'room_id'], ['label'], 'label', $map, [], self::ROOMS_READ);
    }

    public function saveRoom(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->roomRules->saveRoom($principal, $establishmentId, Input::body($request)), 201);
    }

    public function rules(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        $rules = [];
        foreach ($this->roomRules->rules($principal, $establishmentId) as $rule) {
            $rules[(string) $rule['key']] = $rule;
        }

        return $this->json($rules);
    }

    public function setRule(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->roomRules->setRule($principal, $establishmentId, self::id($request, 'key'), Input::body($request)), 201);
    }

    private static function query(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getQueryParams()[$name] ?? null;

        return is_string($value) && $value !== '' && strlen($value) <= 40 ? $value : null;
    }
}

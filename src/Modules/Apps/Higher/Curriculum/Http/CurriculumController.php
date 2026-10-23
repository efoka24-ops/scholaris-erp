<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Curriculum\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\ExportsList;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Academic\Application\Access;
use Scholaris\Modules\Apps\Higher\Academic\Application\Listing;
use Scholaris\Modules\Apps\Higher\Academic\Http\Controller;
use Scholaris\Modules\Apps\Higher\Academic\Http\Input;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Curriculum\Application\CurriculumService;
use Scholaris\Modules\Apps\Higher\Curriculum\Domain\UnitType;

/** Maquettes (MAQ, API-07) : versions, UE, EC, contrôle, publication, nouvelle version, copie, export CSV/XLSX. */
final class CurriculumController extends Controller
{
    use ExportsList;

    private const SCOPE = ['school' => 'school_id', 'department' => 'department_id', 'program' => 'program_id', 'level' => 'level_id'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        Listing $listing,
        private readonly CurriculumService $curricula,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
        parent::__construct($responder, $access, $store, $listing);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page(
            $request,
            'hi_curricula',
            CurriculumService::READ,
            self::SCOPE,
            ['program_id', 'level_id', 'academic_year_id', 'status', 'regime', 'school_id'],
            ['academic_year_id', 'version', 'created_at'],
            'academic_year_id',
            CurriculumService::curriculum(...),
        );
    }

    /** GET /higher/curricula/{id} : maquette complète (semestres > UE > EC) et contrôle de cohérence. */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $row = $this->row($request);

        return $this->json($this->curricula->tree($principal->tenantId, $establishmentId, $row) + [
            'check' => $this->curricula->check($principal->tenantId, $establishmentId, $row),
        ]);
    }

    public function check(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $check = $this->curricula->check($principal->tenantId, $establishmentId, $this->row($request));

        return $this->json($check + ['publishable' => $check['errors'] === []]);
    }

    public function export(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $row = $this->row($request);
        $name = 'maquette_' . $row['id'] . '_v' . (int) $row['version'] . '.csv';

        return $this->csvResponse($this->responses, $this->streams, $name, CurriculumService::exportHeaders(), $this->curricula->exportRows($principal->tenantId, $establishmentId, $row), $request);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('program_id');
        $input->reference('level_id');
        $input->reference('academic_year_id');
        $input->string('title', 160, false);
        $input->string('approved_programme_ref', 120, false, true);
        $input->custom('semester_ordinals', self::ordinals(...), false);

        return $this->json(CurriculumService::curriculum($this->curricula->create($principal, $establishmentId, $input->done())), 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $input->string('title');
        $input->string('approved_programme_ref', 120, false, true);

        return $this->json(CurriculumService::curriculum($this->curricula->update($principal, $establishmentId, self::id($request), $input->done())));
    }

    public function publish(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json(CurriculumService::curriculum($this->curricula->publish($principal, $establishmentId, self::id($request))));
    }

    public function unpublish(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json(CurriculumService::curriculum($this->curricula->unpublish($principal, $establishmentId, self::id($request))));
    }

    public function newVersion(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json(CurriculumService::curriculum($this->curricula->newVersion($principal, $establishmentId, self::id($request))), 201);
    }

    public function copy(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('academic_year_id');
        $values = $input->done();

        return $this->json(CurriculumService::curriculum($this->curricula->copy($principal, $establishmentId, self::id($request), (string) $values['academic_year_id'])), 201);
    }

    public function addUnit(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->int('semester_ordinal', 1, 12);
        $this->unitFields($input);

        return $this->json(CurriculumService::unit($this->curricula->addUnit($principal, $establishmentId, self::id($request), $input->done())), 201);
    }

    public function updateUnit(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $input->int('semester_ordinal', 1, 12);
        $this->unitFields($input);

        return $this->json(CurriculumService::unit($this->curricula->updateUnit($principal, $establishmentId, self::id($request), $input->done())));
    }

    public function removeUnit(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->curricula->removeUnit($principal, $establishmentId, self::id($request));

        return $this->json(['id' => self::id($request), 'deleted' => true]);
    }

    public function addElement(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $this->elementFields($input, true);

        return $this->json(CurriculumService::element($this->curricula->addElement($principal, $establishmentId, self::id($request), $input->done())), 201);
    }

    public function updateElement(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $this->elementFields($input, false);

        return $this->json(CurriculumService::element($this->curricula->updateElement($principal, $establishmentId, self::id($request), $input->done())));
    }

    public function removeElement(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->curricula->removeElement($principal, $establishmentId, self::id($request));

        return $this->json(['id' => self::id($request), 'deleted' => true]);
    }

    /** @return array<string, mixed> maquette lisible (permission + périmètre), sinon 404 */
    private function row(ServerRequestInterface $request): array
    {
        return $this->readable($request, 'hi_curricula', self::id($request), CurriculumService::READ, CurriculumService::scope(...), 'Maquette introuvable.');
    }

    private function unitFields(Input $input): void
    {
        $input->code('code', 20);
        $input->string('title');
        $input->enum('unit_type', UnitType::values());
        $input->int('credits', 0, 60, false);
        $input->number('coefficient', 0, 100, 0.01, false);
        $input->code('choice_group', 20, false);
        $input->int('choice_count', 1, 10, false, true);
        $input->string('national_exam_paper', 40, false, true);
        $input->int('cc_weight_percent', 0, 100, false, true);
        $input->int('sort_index', 0, 1000, false);
    }

    private function elementFields(Input $input, bool $create): void
    {
        $input->code('code', 20, $create);
        $input->string('title', 160, $create);
        $input->int('credits', 0, 30, false);
        $input->number('coefficient', 0, 100, 0.01, false);
        foreach (['cm_hours', 'td_hours', 'tp_hours', 'personal_hours'] as $field) {
            $input->number($field, 0, 2000, 0.25, false);
        }
        $input->int('cc_weight_percent', 0, 100, false, true);
        $input->int('sort_index', 0, 1000, false);
    }

    /** @return list<int> */
    private static function ordinals(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 4) {
            throw new \InvalidArgumentException('Liste de 1 à 4 numéros de semestre attendue.');
        }
        foreach ($value as $ordinal) {
            if (!is_int($ordinal) || $ordinal < 1 || $ordinal > 12) {
                throw new \InvalidArgumentException('Numéro de semestre entre 1 et 12 attendu.');
            }
        }
        $unique = array_values(array_unique($value));
        sort($unique);

        return $unique;
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Workshops\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Apps\Secondary\Workshops\Application\InternshipService;
use Scholaris\Modules\Apps\Secondary\Workshops\Application\WorkshopDocuments;
use Scholaris\Modules\Apps\Secondary\Workshops\Application\WorkshopService;

/** Guarded atelier, sécurité, stock, évaluation pratique et stages (T908–T910). */
final class WorkshopsController extends Controller
{
    private const READ = ['secondary.workshops.read', 'secondary.workshops.manage', 'secondary.workshops.session'];
    private const MANAGE = ['secondary.workshops.manage'];
    private const SESSION = ['secondary.workshops.session', 'secondary.workshops.manage'];
    private const STOCK_READ = ['secondary.stock.manage', 'secondary.workshops.costs.read'];
    private const STOCK_WRITE = ['secondary.stock.manage'];
    private const SAFETY = ['secondary.safety.manage', 'secondary.workshops.manage'];
    private const INTERNSHIPS = ['secondary.internships.manage'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly WorkshopService $workshops,
        private readonly InternshipService $internships,
        private readonly WorkshopDocuments $documents,
    ) {
        parent::__construct($responder, $access, $store);
    }

    /** DOC-21 : convention de stage, émise quand le stage est préparé. */
    public function issueAgreement(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishment, self::INTERNSHIPS);
        $locale = self::locale($request);

        return $this->json($this->documents->agreement($principal, $establishment, $perimeter, self::id($request), $locale), 201);
    }

    /** DOC-22 : fiche d'appréciation du stagiaire, après l'appréciation du tuteur. */
    public function issueAppraisal(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishment, self::INTERNSHIPS);
        $locale = self::locale($request);

        return $this->json($this->documents->appraisal($principal, $establishment, $perimeter, self::id($request), $locale), 201);
    }

    /** DOC-23 : fiche de travaux pratiques et grille remise à un élève de la classe. */
    public function issueSheet(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishment, self::SESSION);
        $input = Input::of($request);
        $structureId = (string) $input->reference('structure_id');
        $personId = (string) $input->reference('person_id');
        $locale = $input->enum('locale', ['fr', 'en'], false);
        $input->done();

        return $this->json($this->documents->sheet($principal, $establishment, $perimeter, self::id($request), $structureId, $personId, $locale), 201);
    }

    /** DOC-24 : état du stock de matière d'œuvre, par le chef des travaux. */
    public function issueStock(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishment, self::MANAGE);
        $input = Input::of($request);
        $personId = $input->reference('person_id', false, true);
        $locale = $input->enum('locale', ['fr', 'en'], false);
        $input->done();

        return $this->json($this->documents->stock($principal, $establishment, $perimeter, self::id($request, 'workshop_id'), $personId, $locale), 201);
    }

    private static function locale(ServerRequestInterface $request): ?string
    {
        $input = Input::of($request);
        $locale = $input->enum('locale', ['fr', 'en'], false);
        $input->done();

        return $locale;
    }

    public function workshops(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $this->access->require($principal, $establishment, self::READ);
        return $this->page(['data' => $this->workshops->workshops($principal->tenantId, $establishment)]);
    }

    public function createWorkshop(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishment, self::MANAGE);
        $input = Input::of($request);
        $input->string('label', 160);
        $input->reference('stream_id', false, true);
        $input->reference('room_id', false, true);
        $input->int('capacity', 1, 10000, false, true);
        $input->reference('chief_person_id', false, true);
        return $this->json($this->workshops->createWorkshop($principal, $establishment, $perimeter, $input->done()), 201);
    }

    public function updateWorkshop(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishment, self::MANAGE);
        [$input, $nullableFields] = self::inputWithNullables($request, true, ['stream_id', 'room_id', 'capacity', 'chief_person_id']);
        $input->string('label', 160, false);
        $input->reference('stream_id', false, true);
        $input->reference('room_id', false, true);
        $input->int('capacity', 1, 10000, false, true);
        $input->reference('chief_person_id', false, true);
        $data = $input->done();
        foreach ($nullableFields as $field) {
            $data[$field] = null;
        }
        return $this->json($this->workshops->updateWorkshop($principal, $establishment, $perimeter, self::id($request), $data));
    }

    public function workstations(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $this->access->require($principal, $establishment, self::READ);
        return $this->page(['data' => $this->workshops->workstations($principal->tenantId, $establishment, self::query($request, 'workshop_id'))]);
    }

    public function createWorkstation(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishment, self::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'workstation');
        $input = Input::of($request);
        $input->reference('workshop_id');
        $input->string('label', 160);
        $input->bool('machine');
        $input->custom('ppe_required', self::stringList(...));
        $data = $input->done();
        return $this->json($this->workshops->createWorkstation($principal, $establishment, $data['workshop_id'], $data['label'], $data['machine'], $data['ppe_required']), 201);
    }

    public function updateWorkstation(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishment, self::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'workstation');
        $input = Input::of($request, true);
        $input->string('label', 160, false);
        $input->bool('machine', false);
        $input->custom('ppe_required', self::stringList(...), false);
        $input->enum('status', ['active', 'inactive'], false);
        $data = $input->done();
        if (isset($data['ppe_required'])) {
            $data['ppe_required'] = json_encode($data['ppe_required'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        return $this->json($this->workshops->updateWorkstation($principal, $establishment, self::id($request), $data));
    }

    public function sheets(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $this->access->require($principal, $establishment, self::READ);
        return $this->page(['data' => $this->workshops->sheets($principal->tenantId, $establishment, self::query($request, 'workshop_id'))]);
    }

    public function createSheet(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishment, self::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'practical_sheet');
        [$input, $nullableFields] = self::inputWithNullables($request, false, ['safety_cap']);
        $input->reference('workshop_id');
        $input->string('title', 160);
        $input->string('objective', 1000);
        $input->custom('materials', self::stringList(...));
        $input->custom('tools', self::stringList(...));
        $input->custom('safety_rules', self::stringList(...));
        $input->custom('criteria', self::criteria(...));
        $input->number('base_score', 0.01, 1000);
        $input->custom('safety_cap', self::nullableNumber(0, 1000), false);
        $data = $input->done();
        foreach ($nullableFields as $field) {
            $data[$field] = null;
        }
        if (isset($data['safety_cap']) && $data['safety_cap'] > $data['base_score']) {
            throw HttpException::validation(['safety_cap' => ['Le plafond ne peut pas dépasser la base de cette grille.']]);
        }
        return $this->json($this->workshops->createSheet($principal, $establishment, $data['workshop_id'], $data['title'], $data['objective'], $data['materials'], $data['tools'], $data['safety_rules'], $data['criteria'], $data['base_score'], $data['safety_cap'] ?? null), 201);
    }

    public function updateSheet(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishment, self::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'practical_sheet');
        [$input, $nullableFields] = self::inputWithNullables($request, true, ['safety_cap']);
        $input->string('title', 160, false);
        $input->string('objective', 1000, false);
        $input->custom('materials', self::stringList(...), false);
        $input->custom('tools', self::stringList(...), false);
        $input->custom('safety_rules', self::stringList(...), false);
        $input->custom('criteria', self::criteria(...), false);
        $input->number('base_score', 0.01, 1000, 0.01, false);
        $input->custom('safety_cap', self::nullableNumber(0, 1000), false);
        $data = $input->done();
        foreach ($nullableFields as $field) {
            $data[$field] = null;
        }
        if (array_key_exists('safety_cap', $data) || array_key_exists('base_score', $data)) {
            $before = $this->workshops->sheet($principal->tenantId, $establishment, self::id($request));
            $base = (float) ($data['base_score'] ?? $before['base_score']);
            $cap = $data['safety_cap'] ?? $before['safety_cap'];
            if ($cap !== null && (float) $cap > $base) {
                throw HttpException::validation(['safety_cap' => ['Le plafond ne peut pas dépasser la base de cette grille.']]);
            }
        }
        return $this->json($this->workshops->updateSheet($principal, $establishment, self::id($request), $data));
    }

    public function sessions(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishment, self::SESSION);
        return $this->page(['data' => $this->workshops->sessions($principal->tenantId, $establishment, $perimeter, self::query($request, 'workshop_id'))]);
    }

    public function createSession(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishment, self::SESSION);
        $input = Input::of($request);
        $input->reference('workshop_id');
        $input->reference('structure_id');
        $input->reference('sheet_id');
        $input->date('session_date');
        $input->reference('teacher_person_id');
        $input->code('client_uuid', 40);
        $input->custom('learners', self::learners(...));
        $data = $input->done();
        $workshopId = self::id($request, 'workshop_id');
        if ($workshopId !== $data['workshop_id']) {
            throw HttpException::validation(['workshop_id' => ['L’atelier du chemin doit correspondre à celui du corps.']]);
        }
        $result = $this->workshops->createSession($principal, $establishment, $perimeter, $workshopId, $data['structure_id'], $data['sheet_id'], $data['session_date'], $data['teacher_person_id'], $data['client_uuid'], $data['learners']);
        return $this->json(['session' => $result['session'], 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }

    public function items(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $this->access->require($principal, $establishment, self::STOCK_READ);
        return $this->page(['data' => $this->workshops->items($principal->tenantId, $establishment, self::query($request, 'workshop_id'))]);
    }

    public function createItem(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $this->access->require($principal, $establishment, self::STOCK_WRITE);
        $input = Input::of($request);
        $input->reference('workshop_id');
        $input->string('label', 160);
        $input->string('unit_label', 40);
        $input->int('unit_cost', 0, PHP_INT_MAX);
        $input->number('stock_qty', 0, 1000000000, 0.01);
        $data = $input->done();
        return $this->json($this->workshops->createItem($principal, $establishment, $data['workshop_id'], $data['label'], $data['unit_label'], $data['unit_cost'], $data['stock_qty']), 201);
    }

    public function moveItem(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $this->access->require($principal, $establishment, self::STOCK_WRITE);
        $input = Input::of($request);
        $input->enum('move_kind', ['in', 'out']);
        $input->number('quantity', 0.01, 1000000000, 0.01);
        $input->reference('session_id', false, true);
        $data = $input->done();
        return $this->json($this->workshops->moveItem($principal, $establishment, self::id($request), $data['move_kind'], $data['quantity'], $data['session_id'] ?? null), 201);
    }

    public function safetyEvents(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $this->access->require($principal, $establishment, self::SAFETY);
        return $this->page(['data' => $this->workshops->safetyEvents($principal->tenantId, $establishment, self::query($request, 'workshop_id'))]);
    }

    public function createSafetyEvent(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $this->access->require($principal, $establishment, self::SAFETY);
        $input = Input::of($request);
        $input->enum('event_kind', ['incident', 'ppe_check', 'machine_check']);
        $input->reference('session_id', false, true);
        $input->reference('person_id', false, true);
        $input->reference('workstation_id', false, true);
        $input->string('description', 1000);
        $input->custom('occurred_at', self::instant(...), false);
        $data = $input->done();
        return $this->json($this->workshops->safetyEvent($principal, $establishment, self::query($request, 'workshop_id'), $data), 201);
    }

    public function evaluate(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishment, self::SESSION);
        $input = Input::of($request);
        $input->reference('person_id');
        $input->custom('observed', self::observed(...));
        $input->bool('serious_safety_breach');
        $data = $input->done();
        $session = $this->workshops->session($principal->tenantId, $establishment, self::id($request, 'session_id'));
        $this->access->assertWritableClass($principal, $perimeter, (string) $session['structure_id'], 'practical_evaluation', self::id($request, 'session_id'));
        return $this->json($this->workshops->evaluate($principal, $establishment, self::id($request, 'session_id'), $data['person_id'], $data['observed'], $data['serious_safety_breach']), 201);
    }

    public function companies(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $this->access->require($principal, $establishment, self::INTERNSHIPS);
        return $this->page(['data' => $this->internships->companies($principal->tenantId, $establishment)]);
    }

    public function createCompany(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishment, self::INTERNSHIPS);
        $this->access->assertWhole($principal, $perimeter, 'internship_company');
        $input = Input::of($request);
        $input->string('label', 160);
        $input->string('sector', 120, false, true);
        $input->string('address', 500, false, true);
        $input->string('contact_name', 120, false, true);
        $input->string('contact_phone', 40, false, true);
        return $this->json($this->internships->createCompany($principal, $establishment, $perimeter, $input->done()), 201);
    }

    public function updateCompany(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishment, self::INTERNSHIPS);
        $this->access->assertWhole($principal, $perimeter, 'internship_company');
        [$input, $nullableFields] = self::inputWithNullables($request, true, ['sector', 'address', 'contact_name', 'contact_phone']);
        $input->string('label', 160, false);
        $input->string('sector', 120, false, true);
        $input->string('address', 500, false, true);
        $input->string('contact_name', 120, false, true);
        $input->string('contact_phone', 40, false, true);
        $input->enum('status', ['active', 'inactive'], false);
        $data = $input->done();
        foreach ($nullableFields as $field) {
            $data[$field] = null;
        }
        return $this->json($this->internships->updateCompany($principal, $establishment, $perimeter, self::id($request), $data));
    }

    public function internships(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishment, self::INTERNSHIPS);
        return $this->page(['data' => $this->internships->internships($principal->tenantId, $establishment, $perimeter)]);
    }

    public function createInternship(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishment, self::INTERNSHIPS);
        $input = Input::of($request);
        $input->reference('person_id');
        $input->reference('structure_id');
        $input->reference('company_id');
        $input->string('tutor_name', 120);
        $input->date('starts_on');
        $input->date('ends_on');
        $input->reference('agreement_document_id', false, true);
        $input->number('score_coefficient', 0.01, 1000, 0.01, false);
        return $this->json($this->internships->createInternship($principal, $establishment, $perimeter, $input->done()), 201);
    }

    public function updateInternship(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishment, self::INTERNSHIPS);
        [$input, $nullableFields] = self::inputWithNullables($request, true, ['agreement_document_id', 'tutor_appraisal', 'report_received_on', 'score', 'score_coefficient']);
        $input->reference('company_id', false);
        $input->string('tutor_name', 120, false);
        $input->date('starts_on', false);
        $input->date('ends_on', false);
        $input->reference('agreement_document_id', false, true);
        $input->string('tutor_appraisal', 4000, false, true);
        $input->date('report_received_on', false, true);
        $input->custom('score', self::nullableNumber(0, 1000), false);
        $input->custom('score_coefficient', self::nullableNumber(0.01, 1000), false);
        $input->enum('status', ['planned', 'ongoing', 'completed', 'cancelled'], false);
        $data = $input->done();
        foreach ($nullableFields as $field) {
            $data[$field] = null;
        }
        $before = $this->internships->internshipRecord($principal->tenantId, $establishment, self::id($request));
        $this->access->assertWritableClass($principal, $perimeter, (string) $before['structure_id'], 'internship', self::id($request));
        return $this->json($this->internships->updateInternship($principal, $establishment, self::id($request), $data));
    }

    public function visits(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishment, self::INTERNSHIPS);
        $internshipId = self::query($request, 'internship_id');
        $internship = $this->internships->internshipRecord($principal->tenantId, $establishment, $internshipId);
        $this->access->assertReadableClass($perimeter, (string) $internship['structure_id']);
        $rows = $this->internships->visits($principal->tenantId, $establishment, $internshipId);
        return $this->page(['data' => $rows]);
    }

    public function createVisit(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishment] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishment, self::INTERNSHIPS);
        $input = Input::of($request);
        $input->reference('visitor_person_id');
        $input->date('visited_on');
        $input->string('notes', 2000);
        $data = $input->done();
        $internshipId = self::id($request, 'internship_id');
        $internship = $this->internships->internshipRecord($principal->tenantId, $establishment, $internshipId);
        $this->access->assertWritableClass($principal, $perimeter, (string) $internship['structure_id'], 'internship_visit', $internshipId);
        return $this->json($this->internships->visit($principal, $establishment, $internshipId, $data['visitor_person_id'], $data['visited_on'], $data['notes']), 201);
    }

    private static function query(ServerRequestInterface $request, string $key): string
    {
        $query = $request->getQueryParams();
        $value = $query[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw HttpException::validation([$key => ['Ce paramètre est obligatoire.']]);
        }
        return $value;
    }

    /**
     * @param list<string> $nullable
     * @return array{Input, list<string>}
     */
    private static function inputWithNullables(ServerRequestInterface $request, bool $partial, array $nullable): array
    {
        $body = Input::body($request);
        $explicitNulls = [];
        foreach ($nullable as $field) {
            if (array_key_exists($field, $body) && $body[$field] === null) {
                $explicitNulls[] = $field;
                unset($body[$field]);
            }
        }
        return [new Input($body, $partial), $explicitNulls];
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
            throw new \InvalidArgumentException('Liste attendue (100 éléments au plus).');
        }
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '' || mb_strlen(trim($item)) > 200) {
                throw new \InvalidArgumentException('Chaque élément doit être un texte non vide de 200 caractères au plus.');
            }
        }
        return array_values(array_unique(array_map('trim', $value)));
    }

    /** @return list<array{code: string, label: string, points: float|int}> */
    private static function criteria(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 100) {
            throw new \InvalidArgumentException('Liste de critères non vide attendue.');
        }
        $items = [];
        foreach ($value as $criterion) {
            if (!is_array($criterion) || !is_string($criterion['code'] ?? null) || preg_match('/^[A-Za-z0-9._-]{1,40}$/', $criterion['code']) !== 1 || !is_string($criterion['label'] ?? null) || trim($criterion['label']) === '' || (!is_int($criterion['points'] ?? null) && !is_float($criterion['points'] ?? null)) || $criterion['points'] <= 0) {
                throw new \InvalidArgumentException('Critère {code, label, points} invalide.');
            }
            $items[] = ['code' => $criterion['code'], 'label' => trim($criterion['label']), 'points' => $criterion['points']];
        }
        return $items;
    }

    /** @return list<array{person_id: string, workstation_id?: ?string, ppe_items?: list<string>}> */
    private static function learners(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 500) {
            throw new \InvalidArgumentException('Liste d’élèves attendue (500 au plus).');
        }
        $learners = [];
        foreach ($value as $learner) {
            if (!is_array($learner) || !is_string($learner['person_id'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $learner['person_id']) !== 1 || (isset($learner['workstation_id']) && (!is_string($learner['workstation_id']) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $learner['workstation_id']) !== 1))) {
                throw new \InvalidArgumentException('Élève de séance invalide.');
            }
            $learners[] = ['person_id' => $learner['person_id'], 'workstation_id' => $learner['workstation_id'] ?? null, 'ppe_items' => self::stringList($learner['ppe_items'] ?? [])];
        }
        return $learners;
    }

    /** @return array<string, float|int> */
    private static function observed(mixed $value): array
    {
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            throw new \InvalidArgumentException('Objet code-critère => points attendu.');
        }
        $scores = [];
        foreach ($value as $code => $score) {
            if (!is_string($code) || preg_match('/^[A-Za-z0-9._-]{1,40}$/', $code) !== 1 || (!is_int($score) && !is_float($score))) {
                throw new \InvalidArgumentException('Points observés invalides.');
            }
            $scores[$code] = $score;
        }
        return $scores;
    }

    private static function instant(mixed $value): string
    {
        if (!is_string($value) || !str_contains($value, 'T') || strtotime($value) === false) {
            throw new \InvalidArgumentException('Date-heure ISO 8601 attendue.');
        }
        return $value;
    }

    private static function nullableNumber(float $min, float $max): \Closure
    {
        return static function (mixed $value) use ($min, $max): ?float {
            if ($value === null) {
                return null;
            }
            if ((!is_int($value) && !is_float($value)) || $value < $min || $value > $max) {
                throw new \InvalidArgumentException('Nombre hors limites.');
            }
            return (float) $value;
        };
    }
}

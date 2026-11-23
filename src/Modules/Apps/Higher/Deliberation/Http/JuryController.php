<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Academic\Application\Access;
use Scholaris\Modules\Apps\Higher\Academic\Application\Listing;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\RegulationBook;
use Scholaris\Modules\Apps\Higher\Academic\Http\Controller;
use Scholaris\Modules\Apps\Higher\Academic\Http\Input;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\DeliberationPermissions as P;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\JuryDocumentService;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\JuryService;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\PreparationService;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\RulesProvider;
use Scholaris\Modules\Apps\Higher\Deliberation\Documents\DeliberationDocumentTypes as Types;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;

/**
 * Jurys de délibération (EX-DEL-02 à 05) : composition, présents et quorum, simulation (API-13), points de jury, rattrapage,
 * documents du jury (DOC-14, 15, 16), modèle de règles à publier, portail du membre de jury.
 */
final class JuryController extends Controller
{
    private const SCOPE = ['school' => 'school_id', 'program' => 'program_id', 'level' => 'level_id', 'group' => 'structure_id'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        Listing $listing,
        private readonly JuryService $juries,
        private readonly PreparationService $preparation,
        private readonly JuryDocumentService $documents,
        private readonly RulesProvider $rules,
        private readonly AcademicDirectory $academic,
        private readonly RegulationBook $regulations,
        private readonly UserPersonLink $users,
    ) {
        parent::__construct($responder, $access, $store, $listing);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page(
            $request,
            JuryService::TABLE,
            [P::READ],
            self::SCOPE,
            ['structure_id', 'period_id', 'academic_year_id', 'status', 'exam_session', 'school_id', 'program_id', 'level_id'],
            ['created_at', 'period_id', 'status'],
            'created_at DESC',
            JuryService::jury(...),
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $row = $this->juries->readable($principal, $establishmentId, self::id($request), [P::READ, P::JURY_PORTAL]);

        return $this->json($this->juries->present($principal->tenantId, $establishmentId, $row));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('structure_id');
        $input->reference('period_id');
        $input->string('period_label', 80, false);
        $input->reference('academic_year_id');
        $input->enum('exam_session', JuryService::SESSIONS, false);
        $input->reference('president_person_id');
        $input->references('members', 30);
        $input->int('quorum_min', 1, 31, false);
        $input->string('agenda', 2000, false, true);

        return $this->json($this->juries->create($principal, $establishmentId, $input->done()), 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $input->reference('president_person_id');
        $input->references('members', 30);
        $input->int('quorum_min', 1, 31);
        $input->string('agenda', 2000, true, true);

        return $this->json($this->juries->update($principal, $establishmentId, self::id($request), $input->done()));
    }

    public function attendance(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $present = $input->references('present', 31) ?? [];
        $values = $input->done();

        return $this->json($this->juries->attendance($principal, $establishmentId, self::id($request), $present));
    }

    /** API-13 : simulation sans écriture des résultats, cas limites à moins de `margin` point d'un seuil (défaut 0,5). */
    public function simulate(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->custom('overrides', static function (mixed $v): array {
            if (!is_array($v) || ($v !== [] && array_is_list($v))) {
                throw new \InvalidArgumentException('Objet d\'options de règles attendu.');
            }

            return $v;
        }, false);
        $input->number('margin', 0.0, 2.0, 0.01, false);
        $values = $input->done();

        return $this->json($this->preparation->simulate($principal, $establishmentId, self::id($request), (array) ($values['overrides'] ?? []), (float) ($values['margin'] ?? 0.5)));
    }

    /** Points de jury (RG-DEL-2) : plafonnés, motivés ; 0 retire les points. */
    public function points(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('person_id');
        $input->number('points', 0.0, 5.0, 0.01);
        $input->string('reason', 500, false);
        $values = $input->done();

        return $this->json($this->juries->grantPoints($principal, $establishmentId, self::id($request), (string) $values['person_id'], (float) $values['points'], (string) ($values['reason'] ?? '')));
    }

    public function resitList(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->responder->json(['data' => $this->preparation->resitCandidates($principal, $establishmentId, self::id($request))]);
    }

    public function convokeResit(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->bool('notify', false);
        $values = $input->done();

        return $this->json($this->preparation->convokeResit($principal, $establishmentId, self::id($request), ($values['notify'] ?? false) === true));
    }

    /** DOC-14 (grille), DOC-15 (PV : empreinte vérifiée), DOC-16 (liste des résultats : après signature du président). */
    public function document(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->enum('code', [Types::GRID, Types::MINUTES, Types::RESULTS_LIST]);
        $input->custom('lines', self::lines(...));
        $input->int('rule_version', 0, 1000000, false);
        $input->enum('locale', ['fr', 'en'], false);
        $values = $input->done();
        /** @var list<array{person_id: string, average: float|null, credits_earned: int, credits_total: int, proposal?: string|null, decision: string|null, reason: string|null}> $lines */
        $lines = $values['lines'];

        return $this->json($this->documents->issue(
            $principal,
            $establishmentId,
            self::id($request),
            (string) $values['code'],
            $lines,
            isset($values['rule_version']) ? (int) $values['rule_version'] : null,
            isset($values['locale']) ? (string) $values['locale'] : null,
        ), 201);
    }

    /** Version de règles à publier au socle (POST /calculation-rules) pour une filière et une année, d'après son règlement. */
    public function rulesTemplate(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $query = $request->getQueryParams();
        $programId = is_string($query['program_id'] ?? null) ? $query['program_id'] : '';
        $yearId = is_string($query['academic_year_id'] ?? null) ? $query['academic_year_id'] : '';
        $program = $this->academic->program($principal->tenantId, $establishmentId, $programId) ?? throw HttpException::notFound('Filière introuvable.');
        $perimeter = $this->access->require($principal, $establishmentId, [P::READ, P::PREPARE]);
        $this->access->assertReadable($perimeter, ['school' => $program->schoolId, 'program' => $program->id]);
        $regulation = $this->regulations->effective($principal->tenantId, $establishmentId, $program->schoolId, $program->regime, $yearId);

        return $this->json([
            'program_id' => $program->id, 'structure_id' => $program->id, 'academic_year_id' => $yearId, 'regime' => $program->regime,
            'regulation' => $regulation === null ? null : ['id' => $regulation->id, 'version' => $regulation->version, 'status' => $regulation->status],
        ] + $this->rules->template($program->regime, $regulation->parameters ?? []));
    }

    /** Portail du membre de jury (R21) : ses jurys, l'état du PV et sa signature. */
    public function portal(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, [P::JURY_PORTAL, P::READ]);
        $personId = $this->users->personIdForUser($principal->tenantId, $principal->userId);
        if ($personId === null) {
            return $this->responder->json(['data' => []]);
        }
        $out = [];
        foreach ($this->store->select('hi_jury_members', $principal->tenantId, $establishmentId, ['person_id' => $personId], 'created_at DESC', 200) as $member) {
            $jury = $this->store->find(JuryService::TABLE, $principal->tenantId, $establishmentId, (string) $member['jury_id']);
            if ($jury !== null) {
                $view = $this->juries->present($principal->tenantId, $establishmentId, $jury);
                $view['my_role'] = (string) $member['member_role'];
                $view['my_signature'] = in_array($personId, array_column($view['signatures'], 'person_id'), true);
                $out[] = $view;
            }
        }

        return $this->responder->json(['data' => $out]);
    }

    /** @return list<array<string, mixed>> */
    private static function lines(mixed $v): array
    {
        if (!is_array($v) || !array_is_list($v) || count($v) > Types::LINE_SLOTS) {
            throw new \InvalidArgumentException(sprintf('Liste de %d lignes au plus attendue.', Types::LINE_SLOTS));
        }
        $out = [];
        foreach ($v as $line) {
            if (!is_array($line) || !is_string($line['person_id'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $line['person_id']) !== 1) {
                throw new \InvalidArgumentException('Chaque ligne porte un person_id valide.');
            }
            $average = $line['average'] ?? null;
            if ($average !== null && !is_int($average) && !is_float($average) && !(is_string($average) && is_numeric($average))) {
                throw new \InvalidArgumentException('Moyenne numérique ou nulle attendue.');
            }
            $text = static fn (mixed $s): ?string => is_string($s) && $s !== '' ? mb_substr($s, 0, 500) : null;
            $out[] = [
                'person_id' => $line['person_id'], 'average' => $average === null ? null : (float) $average,
                'credits_earned' => (int) ($line['credits_earned'] ?? 0), 'credits_total' => (int) ($line['credits_total'] ?? 0),
                'proposal' => $text($line['proposal'] ?? null), 'decision' => $text($line['decision'] ?? null), 'reason' => $text($line['reason'] ?? null),
            ];
        }

        return $out;
    }
}

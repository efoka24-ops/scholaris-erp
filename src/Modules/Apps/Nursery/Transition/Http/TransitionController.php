<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Transition\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Documents\Application\NurseryDocuments;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\Input;
use Scholaris\Modules\Apps\Nursery\Support\Paging;
use Scholaris\Modules\Apps\Nursery\Transition\Application\ClosureReadiness;
use Scholaris\Modules\Apps\Nursery\Transition\Application\LiaisonService;
use Scholaris\Modules\Apps\Nursery\Transition\Application\TransitionService;

/** Passage de fin d'année : propositions, avis de l'éducatrice, décision de la direction, fiche de liaison et état de clôture. */
final class TransitionController extends Controller
{
    private const READ = ['nursery.transition.decide', 'nursery.transition.opinion'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        private readonly TransitionService $transitions,
        private readonly LiaisonService $liaisons,
        private readonly ClosureReadiness $readiness,
    ) {
        parent::__construct($responder, $access);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);

        return $this->page($this->transitions->page($perimeter, Paging::fromRequest($request), self::query($request, 'section_id'), self::query($request, 'status')));
    }

    public function propose(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $input = Input::of($request);
        $input->reference('section_id');

        return $this->responder->json(['data' => $this->transitions->propose($principal, $perimeter, (string) $input->done()['section_id'])], 201);
    }

    public function opinion(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.transition.opinion']);
        $input = Input::of($request);
        $input->string('opinion', 500);

        return $this->json($this->transitions->opinion($principal, $perimeter, self::id($request), (string) $input->done()['opinion']));
    }

    public function decide(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.transition.decide']);
        $input = Input::of($request);
        $input->enum('decision', TransitionService::DECISIONS);
        $input->string('reason', 500, false, true);
        $input->string('parent_request_ref', 120, false, true);
        $input->reference('target_section_id', false, true);
        /** @var array{decision: string, reason?: ?string, parent_request_ref?: ?string, target_section_id?: ?string} $data */
        $data = $input->done();

        return $this->json($this->transitions->decide($principal, $perimeter, self::id($request), $data));
    }

    public function closure(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.transition.decide']);
        $this->access->assertWhole($principal, $perimeter, 'closure');
        $year = self::query($request, 'academic_year_id') ?? throw HttpException::validation(['academic_year_id' => ['Année scolaire requise.']]);
        [$blocking, $detail] = $this->readiness->count($principal->tenantId, $establishmentId, $year);

        return $this->json(['academic_year_id' => $year, 'blocking_count' => $blocking, 'ready' => $blocking === 0, 'detail' => $detail]);
    }

    public function liaisons(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);

        return $this->page($this->liaisons->page($perimeter, Paging::fromRequest($request), self::query($request, 'section_id')));
    }

    public function createLiaison(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $input = Input::of($request);
        $input->reference('learner_id');

        return $this->json($this->liaisons->create($principal, $perimeter, (string) $input->done()['learner_id']), 201);
    }

    public function updateLiaison(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $input = Input::of($request, true);
        $input->string('path_summary', 1000, false, true);
        $input->string('strengths', 2000, false, true);
        $input->string('needs', 1000, false, true);
        $input->string('destination', 160, false, true);

        return $this->json($this->liaisons->update($principal, $perimeter, self::id($request), $input->done()));
    }

    public function signLiaison(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.transition.decide']);
        $input = Input::of($request);
        $input->enum('locale', NurseryDocuments::LOCALES, false);

        return $this->json($this->liaisons->sign($principal, $perimeter, self::id($request), (string) ($input->done()['locale'] ?? 'fr')));
    }

    private static function query(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getQueryParams()[$name] ?? null;

        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $value) === 1 ? $value : null;
    }
}

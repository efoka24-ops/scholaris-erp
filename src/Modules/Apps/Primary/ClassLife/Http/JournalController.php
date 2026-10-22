<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\ClassLife\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Primary\ClassLife\Application\FamilyService;
use Scholaris\Modules\Apps\Primary\ClassLife\Application\JournalService;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\Controller;
use Scholaris\Modules\Apps\Primary\Support\Input;
use Scholaris\Modules\Apps\Primary\Support\Store;

/** Cahier de liaison (maître) et portail parent (journal publié, compétences publiées, accusés de lecture) : EX-PRI-38, 39. */
final class JournalController extends Controller
{
    public function __construct(JsonResponder $responder, Access $access, Store $store, private readonly JournalService $journal, private readonly FamilyService $family)
    {
        parent::__construct($responder, $access, $store);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $query = $request->getQueryParams();
        $from = is_string($query['from'] ?? null) ? $query['from'] : null;
        $to = is_string($query['to'] ?? null) ? $query['to'] : null;

        return $this->json(['items' => $this->journal->list($principal, $establishmentId, self::id($request), $from, $to)]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->date('entry_date', false);
        $input->enum('entry_kind', JournalService::KINDS, false);
        $input->string('title');
        $input->string('body', 5000);
        $input->reference('subject_id', false);
        $input->bool('visible_to_parents', false);

        return $this->json($this->journal->create($principal, $establishmentId, self::id($request), $input->done()), 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $input->date('entry_date');
        $input->enum('entry_kind', JournalService::KINDS);
        $input->string('title');
        $input->string('body', 5000);
        $input->bool('visible_to_parents', false);

        return $this->json($this->journal->update($principal, $establishmentId, self::id($request), $input->done()));
    }

    public function publish(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->journal->publish($principal, $establishmentId, self::id($request)));
    }

    public function children(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json(['items' => $this->family->children($principal, $establishmentId)]);
    }

    public function familyJournal(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json(['items' => $this->family->journal($principal, $establishmentId, self::id($request))]);
    }

    public function familyCompetencies(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $period = $request->getQueryParams()['period_id'] ?? null;

        return $this->json($this->family->competencies($principal, $establishmentId, self::id($request), is_string($period) && $period !== '' ? $period : null));
    }

    public function seen(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->family->seen($principal, $establishmentId, self::id($request)));
    }
}

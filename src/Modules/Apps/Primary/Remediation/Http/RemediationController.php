<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Remediation\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Primary\Remediation\Application\RemediationService;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\Controller;
use Scholaris\Modules\Apps\Primary\Support\Input;
use Scholaris\Modules\Apps\Primary\Support\Store;

/** Registre de remédiation d'une classe et fiches de suivi individualisées (arrêté du 14 août 2026). */
final class RemediationController extends Controller
{
    public function __construct(JsonResponder $responder, Access $access, Store $store, private readonly RemediationService $service)
    {
        parent::__construct($responder, $access, $store);
    }

    public function register(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $q = $request->getQueryParams();

        return $this->json($this->service->register($principal, $establishmentId, self::id($request), self::day($q['from'] ?? null), self::day($q['to'] ?? null)));
    }

    public function createSession(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->date('held_on');
        $input->int('duration_minutes', 1, 600);
        $input->string('remediation_form', 30);
        $input->string('subject_code', 40, false);
        $input->string('subject_label');
        $input->string('objective', 255);
        $input->string('notes', 2000, false, true);
        $input->reference('period_id', false, true);
        $input->references('person_ids', 80);
        return $this->json($this->service->createSession($principal, $establishmentId, self::id($request), $input->done()), 201);
    }

    public function plans(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $q = $request->getQueryParams();
        $class = is_string($q['class_id'] ?? null) && $q['class_id'] !== '' ? $q['class_id'] : null;
        $person = is_string($q['person_id'] ?? null) && $q['person_id'] !== '' ? $q['person_id'] : null;

        return $this->json(['items' => $this->service->plans($principal, $establishmentId, $class, $person)]);
    }

    public function plan(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->service->plan($principal, $establishmentId, self::id($request)));
    }

    public function createPlan(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('person_id');
        $input->reference('class_id');
        $input->string('subject_code', 40, false);
        $input->string('subject_label');
        $input->string('difficulties', 2000);
        $input->string('actions', 2000);
        $input->reference('period_id', false, true);
        $input->date('opened_on', false);

        return $this->json($this->service->createPlan($principal, $establishmentId, $input->done()), 201);
    }

    public function review(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->date('reviewed_on', false);
        $input->string('progress_note', 500);
        $input->enum('status_after', RemediationService::STATUSES);
        $input->string('actions', 2000, false);

        return $this->json($this->service->review($principal, $establishmentId, self::id($request), $input->done()), 201);
    }

    private static function day(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}

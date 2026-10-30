<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Councils\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Primary\Councils\Application\CouncilService;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\Controller;
use Scholaris\Modules\Apps\Primary\Support\Input;
use Scholaris\Modules\Apps\Primary\Support\Store;

/** Conseil des maîtres (EX-PRI-25, 27) : ouverture, séance, validation, réouverture avant émission. */
final class CouncilController extends Controller
{
    public function __construct(JsonResponder $responder, Access $access, Store $store, private readonly CouncilService $councils)
    {
        parent::__construct($responder, $access, $store);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $q = $request->getQueryParams();
        $class = is_string($q['class_id'] ?? null) && $q['class_id'] !== '' ? $q['class_id'] : null;
        $year = is_string($q['academic_year_id'] ?? null) && $q['academic_year_id'] !== '' ? $q['academic_year_id'] : null;

        return $this->json(['items' => $this->councils->list($principal, $establishmentId, $class, $year)]);
    }

    public function open(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('class_id');
        $input->reference('period_id');
        $input->date('starts_on');
        $input->string('period_label', 80, false);
        $input->date('held_on', false, true);
        $input->custom('sequences', static function (mixed $value): array {
            if (!is_array($value) || !array_is_list($value) || count($value) > 12) {
                throw new \InvalidArgumentException('Liste de 12 séquences au plus attendue.');
            }
            foreach ($value as $item) {
                if (!is_array($item) || !is_string($item['id'] ?? null) || !is_string($item['starts_on'] ?? null)) {
                    throw new \InvalidArgumentException('Chaque séquence porte « id » et « starts_on ».');
                }
            }

            return $value;
        }, false);

        return $this->json($this->councils->open($principal, $establishmentId, $input->done()), 201);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->councils->find($principal, $establishmentId, self::id($request)));
    }

    public function updateLine(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $input->string('appraisal', 500, false, true);
        $input->string('conduct_code', 20, false, true);
        $input->custom('decision', static function (mixed $value): ?string {
            if ($value !== null && (!is_string($value) || !in_array($value, CouncilService::DECISIONS, true))) {
                throw new \InvalidArgumentException('Valeurs possibles : ' . implode(', ', CouncilService::DECISIONS) . '.');
            }

            return $value;
        }, false);
        $input->string('reason', 255, false, true);
        $input->bool('remediation_flag', false);
        $input->string('remediation_note', 255, false, true);

        return $this->json($this->councils->updateLine($principal, $establishmentId, self::id($request), self::id($request, 'line_id'), $input->done()));
    }

    public function refresh(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->councils->refresh($principal, $establishmentId, self::id($request)));
    }

    public function validate(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->councils->validate($principal, $establishmentId, self::id($request)));
    }

    public function reopen(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->string('reason', 255);
        $values = $input->done();

        return $this->json($this->councils->reopen($principal, $establishmentId, self::id($request), (string) $values['reason']));
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\ReportCards\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Primary\Councils\Application\CouncilService;
use Scholaris\Modules\Apps\Primary\ReportCards\Application\ReportCardService;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\Controller;
use Scholaris\Modules\Apps\Primary\Support\Input;
use Scholaris\Modules\Apps\Primary\Support\Store;

/** Bulletins : liste, aperçu, émission en lot, publication, rectificatif, portail parent (EX-PRI-26, 28, 39). */
final class ReportCardController extends Controller
{
    public function __construct(JsonResponder $responder, Access $access, Store $store, private readonly ReportCardService $cards)
    {
        parent::__construct($responder, $access, $store);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json(['items' => $this->cards->list($principal, $establishmentId, self::id($request))]);
    }

    public function preview(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $q = $request->getQueryParams();
        $person = $q['person_id'] ?? null;
        if (!is_string($person) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $person) !== 1) {
            throw HttpException::validation(['person_id' => ['L\'élève est obligatoire.']]);
        }
        $kind = ($q['kind'] ?? ReportCardService::TERM) === ReportCardService::ANNUAL ? ReportCardService::ANNUAL : ReportCardService::TERM;

        return $this->json($this->cards->preview($principal, $establishmentId, self::id($request), $person, $kind));
    }

    public function issue(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->cards->issue($principal, $establishmentId, self::id($request)));
    }

    public function publish(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->cards->publish($principal, $establishmentId, self::id($request)));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->cards->show($principal, $establishmentId, self::id($request)));
    }

    public function rectify(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->string('reason', 500);
        $input->string('appraisal', 500, false, true);
        $input->string('conduct_code', 20, false, true);
        $input->enum('decision', CouncilService::DECISIONS, false);
        $input->string('remediation_note', 255, false, true);
        $values = $input->done();
        $reason = (string) $values['reason'];
        unset($values['reason']);

        return $this->json($this->cards->rectify($principal, $establishmentId, self::id($request), $reason, $values), 201);
    }

    public function family(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json(['items' => $this->cards->forFamily($principal, $establishmentId, self::id($request))]);
    }
}

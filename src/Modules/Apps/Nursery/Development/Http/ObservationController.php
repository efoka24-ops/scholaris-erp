<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Development\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Development\Application\ObservationService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\Input;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Paging;

/** Observation en un geste (éducatrice), lecture et suivi de fréquence. Aucune note, moyenne, total ni rang n'est rendu. */
final class ObservationController extends Controller
{
    private const READ = ['nursery.dev.read', 'nursery.dev.observe', 'nursery.dev.validate'];

    public function __construct(JsonResponder $responder, Access $access, private readonly ObservationService $observations, private readonly NurseryClock $clock)
    {
        parent::__construct($responder, $access);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.dev.observe']);
        $input = Input::of($request);
        self::fields($input);
        $result = $this->observations->record($principal, $perimeter, $input->done());

        return $this->json($result['observation'], $result['duplicate'] ? 200 : 201);
    }

    /** Champs d'une observation (partagés avec le lot hors ligne). */
    public static function fields(Input $input): void
    {
        $input->reference('learner_id');
        $input->reference('item_id');
        $input->string('level_code', 20);
        $input->string('remark', 500, false);
        $input->date('observed_on', false);
        $input->string('client_uuid', 40, false);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);

        return $this->page($this->observations->page($perimeter, Paging::fromRequest($request), $this->query($request, 'learner_id'), $this->query($request, 'section_id')));
    }

    public function coverage(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $section = $this->query($request, 'section_id') ?? throw HttpException::validation(['section_id' => ['Section requise.']]);
        $month = $this->query($request, 'month') ?? substr($this->clock->today(), 0, 7);
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw HttpException::validation(['month' => ['Mois AAAA-MM attendu.']]);
        }

        return $this->page($this->observations->coverage($perimeter, Paging::fromRequest($request), $section, $month));
    }

    private function query(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getQueryParams()[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}

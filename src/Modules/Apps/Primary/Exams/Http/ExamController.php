<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Exams\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\ExportsList;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Primary\Exams\Application\ExamService;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\Controller;
use Scholaris\Modules\Apps\Primary\Support\Input;
use Scholaris\Modules\Apps\Primary\Support\Store;

/** Examen de fin de cycle (CEP, FSLC) : sessions, candidats, fiches, numéros, résultats officiels, export, frais. */
final class ExamController extends Controller
{
    use ExportsList;

    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly ExamService $exams,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
        parent::__construct($responder, $access, $store);
    }

    public function catalogue(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->exams->catalogue($principal, $establishmentId));
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $year = $request->getQueryParams()['academic_year_id'] ?? null;

        return $this->json(['items' => $this->exams->sessions($principal, $establishmentId, is_string($year) && $year !== '' ? $year : null)]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('academic_year_id');
        $input->string('exam_code', 20);
        $input->reference('level_structure_id');
        self::sessionFields($input);

        return $this->json($this->exams->createSession($principal, $establishmentId, $input->done()), 201);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->exams->session($principal, $establishmentId, self::id($request)));
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        self::sessionFields($input);

        return $this->json($this->exams->updateSession($principal, $establishmentId, self::id($request), $input->done()));
    }

    public function status(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->enum('status', ExamService::STATUSES);
        $v = $input->done();

        return $this->json($this->exams->transition($principal, $establishmentId, self::id($request), (string) $v['status']));
    }

    public function candidates(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json(['items' => $this->exams->candidates($principal, $establishmentId, self::id($request))]);
    }

    public function enrolLevel(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->exams->enrolLevel($principal, $establishmentId, self::id($request)));
    }

    public function addCandidate(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('person_id');
        $v = $input->done();

        return $this->json($this->exams->addCandidate($principal, $establishmentId, self::id($request), (string) $v['person_id']), 201);
    }

    public function updateCandidate(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $input->string('candidate_number', 40, false, true);
        $input->string('sub_centre_label', 160, false, true);
        $input->custom('fields', static function (mixed $v): array {
            if (!is_array($v) || ($v !== [] && array_is_list($v)) || count($v) > 40) {
                throw new \InvalidArgumentException('Objet « champ => valeur » attendu.');
            }

            return $v;
        }, false);

        return $this->json($this->exams->updateCandidate($principal, $establishmentId, self::id($request), $input->done()));
    }

    public function withdraw(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->string('reason', 255);
        $v = $input->done();

        return $this->json($this->exams->withdraw($principal, $establishmentId, self::id($request), (string) $v['reason']));
    }

    public function sheet(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->exams->sheet($principal, $establishmentId, self::id($request)));
    }

    public function numbers(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->exams->assignNumbers($principal, $establishmentId, self::id($request)));
    }

    public function results(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->custom('entries', static function (mixed $v): array {
            if (!is_array($v) || !array_is_list($v) || count($v) > 3000) {
                throw new \InvalidArgumentException('Liste de 3000 résultats au plus attendue.');
            }
            foreach ($v as $item) {
                if (!is_array($item)) {
                    throw new \InvalidArgumentException('Chaque résultat est un objet.');
                }
            }

            return $v;
        });
        $v = $input->done();

        return $this->json($this->exams->recordResults($principal, $establishmentId, self::id($request), $v['entries']));
    }

    public function import(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->string('csv', 200000);
        $v = $input->done();

        return $this->json($this->exams->importResults($principal, $establishmentId, self::id($request), (string) $v['csv']));
    }

    public function publish(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->exams->publishResults($principal, $establishmentId, self::id($request)));
    }

    public function invoice(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->exams->invoice($principal, $establishmentId, self::id($request)));
    }

    public function export(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $data = $this->exams->exportRows($principal, $establishmentId, self::id($request));

        return $this->csvResponse($this->responses, $this->streams, 'primaire-examen-candidats.csv', $data['headers'], $data['rows'], $request);
    }

    public function family(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json(['items' => $this->exams->forFamily($principal, $establishmentId, self::id($request))]);
    }

    private static function sessionFields(Input $input): void
    {
        $input->string('label', 160, false);
        $input->string('centre_label', 160, false, true);
        $input->string('sub_centre_label', 160, false, true);
        $input->date('registration_opens_on', false, true);
        $input->date('registration_closes_on', false, true);
        $input->date('written_on', false, true);
        $input->string('fee_code', 40, false, true);
    }
}

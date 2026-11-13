<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Orientation\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\Councils\Orientation\Application\OrientationService;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/** API du sous-module activable Orientation : vœux, avis, décisions et recours. */
final class OrientationController extends Controller
{
    public function __construct(JsonResponder $responder, Access $access, Store $store, private readonly OrientationService $orientation)
    {
        parent::__construct($responder, $access, $store);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $query = $request->getQueryParams();

        return $this->responder->json(['data' => $this->orientation->index($principal, $establishmentId, self::requiredQuery($query, 'class_id'), self::requiredQuery($query, 'academic_year_id'))]);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->orientation->show($principal, $establishmentId, self::id($request, 'personId'), self::requiredQuery($request->getQueryParams(), 'academic_year_id')));
    }

    public function showOwn(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->orientation->showOwn($principal, $establishmentId, self::requiredQuery($request->getQueryParams(), 'academic_year_id')));
    }

    public function familyCases(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->responder->json(['data' => $this->orientation->familyCases($principal, $establishmentId, self::requiredQuery($request->getQueryParams(), 'academic_year_id'))]);
    }

    public function saveWishes(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $year = $input->reference('academic_year_id');
        $wishes = $input->references('wishes', 8);
        $input->done();

        return $this->json($this->orientation->saveWishes($principal, $establishmentId, self::id($request, 'personId'), (string) $year, $wishes ?? []));
    }

    public function saveOwnWishes(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $year = $input->reference('academic_year_id');
        $wishes = $input->references('wishes', 8);
        $input->done();

        return $this->json($this->orientation->saveOwnWishes($principal, $establishmentId, (string) $year, $wishes ?? []));
    }

    public function saveAdvice(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $year = $input->reference('academic_year_id');
        $opinion = $input->string('counsellor_opinion', 255, false, true);
        $stream = $input->reference('proposed_stream_id', false, true);
        $interview = $input->date('interview_on', false, true);
        $values = $input->done();
        unset($values['academic_year_id']);
        if (array_key_exists('counsellor_opinion', $values)) {
            $values['counsellor_opinion'] = $opinion;
        }
        if (array_key_exists('proposed_stream_id', $values)) {
            $values['proposed_stream_id'] = $stream;
        }
        if (array_key_exists('interview_on', $values)) {
            $values['interview_on'] = $interview;
        }

        return $this->json($this->orientation->saveAdvice($principal, $establishmentId, self::id($request, 'personId'), (string) $year, $values));
    }

    public function decide(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $year = $input->reference('academic_year_id');
        $stream = $input->reference('stream_id');
        $reason = $input->string('reason', 255);
        $input->done();

        return $this->json($this->orientation->decide($principal, $establishmentId, self::id($request, 'personId'), (string) $year, (string) $stream, (string) $reason), 201);
    }

    public function appeals(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->responder->json(['data' => $this->orientation->appeals($principal, $establishmentId, self::requiredQuery($request->getQueryParams(), 'class_id'))]);
    }

    public function ownAppeals(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->responder->json(['data' => $this->orientation->ownAppeals($principal, $establishmentId, self::requiredQuery($request->getQueryParams(), 'academic_year_id'))]);
    }

    public function appeal(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $year = $input->reference('academic_year_id');
        $reason = $input->string('reason', 255);
        $input->done();

        return $this->json($this->orientation->appeal($principal, $establishmentId, self::id($request, 'personId'), (string) $year, (string) $reason), 201);
    }

    public function decideAppeal(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $outcome = $input->enum('outcome', ['accepted', 'rejected']);
        $reason = $input->string('reason', 255);
        $stream = $input->reference('revised_stream_id', false, true);
        $input->done();

        return $this->json($this->orientation->decideAppeal($principal, $establishmentId, self::id($request), (string) $outcome, (string) $reason, $stream));
    }

    /** @param array<string, mixed> $query */
    private static function requiredQuery(array $query, string $key): string
    {
        $value = $query[$key] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > 40) {
            throw HttpException::validation([$key => ['Paramètre obligatoire (40 caractères au plus).']]);
        }

        return $value;
    }
}

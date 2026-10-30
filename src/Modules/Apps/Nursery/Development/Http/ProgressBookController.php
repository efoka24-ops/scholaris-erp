<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Development\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Development\Application\ProgressBookService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\Input;
use Scholaris\Modules\Apps\Nursery\Support\Paging;

/** Carnet de suivi trimestriel : rédaction par l'éducatrice, validation et publication par la directrice. */
final class ProgressBookController extends Controller
{
    public function __construct(JsonResponder $responder, Access $access, private readonly ProgressBookService $books)
    {
        parent::__construct($responder, $access);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.dev.read', 'nursery.dev.observe', 'nursery.dev.validate']);
        $query = $request->getQueryParams();
        $pick = static fn (string $name): ?string => isset($query[$name]) && is_string($query[$name]) && $query[$name] !== '' ? $query[$name] : null;

        return $this->page($this->books->page($perimeter, Paging::fromRequest($request), $pick('section_id'), $pick('status'), $pick('learner_id')));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.dev.read', 'nursery.dev.observe', 'nursery.dev.validate']);

        return $this->json($this->books->get($perimeter, self::id($request)));
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.dev.observe']);
        $input = Input::of($request);
        $input->reference('learner_id');
        $input->reference('period_id', false);

        return $this->json($this->books->create($principal, $perimeter, $input->done()), 201);
    }

    public function write(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.dev.observe']);
        $input = Input::of($request, true);
        $input->string('synthesis', 3000, false);
        $input->custom('domains', static function (mixed $value): array {
            if (!is_array($value) || array_is_list($value) && $value !== []) {
                throw new \InvalidArgumentException('Objet « code de domaine => texte » attendu.');
            }
            foreach ($value as $code => $text) {
                if (!is_string($code) || !is_string($text) || mb_strlen($text) > 2000) {
                    throw new \InvalidArgumentException('Chaque domaine porte un texte de 2000 caractères au plus.');
                }
            }

            return $value;
        }, false);

        return $this->json($this->books->write($principal, $perimeter, self::id($request), $input->done()));
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->books->submit($principal, $this->access->require($principal, $establishmentId, ['nursery.dev.observe']), self::id($request)));
    }

    public function validate(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->books->validate($principal, $this->access->require($principal, $establishmentId, ['nursery.dev.validate']), self::id($request)));
    }

    public function sendBack(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->books->sendBack($principal, $this->access->require($principal, $establishmentId, ['nursery.dev.validate']), self::id($request)));
    }

    public function publish(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->books->publish($principal, $this->access->require($principal, $establishmentId, ['nursery.dev.validate']), self::id($request)));
    }
}

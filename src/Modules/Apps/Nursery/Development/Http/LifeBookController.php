<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Development\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Development\Application\LifeBookService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\Input;
use Scholaris\Modules\Apps\Nursery\Support\Paging;
use Scholaris\Modules\Socle\People\Contracts\MediaContent;

/** Cahier de vie : rédaction, étiquetage des médias et publication par l'éducatrice de la section. */
final class LifeBookController extends Controller
{
    private const WRITE = ['nursery.lifebook.write'];
    private const READ = ['nursery.lifebook.read', 'nursery.lifebook.write'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        private readonly LifeBookService $lifebook,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
        parent::__construct($responder, $access);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $section = $request->getQueryParams()['section_id'] ?? null;

        return $this->page($this->lifebook->staffPage($perimeter, Paging::fromRequest($request), is_string($section) && $section !== '' ? $section : null));
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::WRITE);
        $input = Input::of($request);
        $input->reference('section_id');
        $input->string('title', 160);
        $input->string('body', 5000, false);
        $input->date('activity_on', false);

        return $this->json($this->lifebook->create($principal, $perimeter, $input->done()), 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::WRITE);
        $input = Input::of($request, true);
        $input->string('title', 160, false);
        $input->string('body', 5000, false);
        $input->date('activity_on', false);

        return $this->json($this->lifebook->update($principal, $perimeter, self::id($request), $input->done()));
    }

    public function addMedia(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::WRITE);
        $input = Input::of($request);
        $input->string('content_base64', 3_000_000);
        $input->references('children', 50, false);
        $input->bool('no_child_visible', false);

        return $this->json($this->lifebook->addMedia($principal, $perimeter, self::id($request), $input->done()), 201);
    }

    public function tag(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::WRITE);
        $input = Input::of($request);
        $input->references('children', 50, false);
        $input->bool('no_child_visible', false);
        $data = $input->done();

        return $this->json($this->lifebook->tag($principal, $perimeter, self::id($request), (array) ($data['children'] ?? []), ($data['no_child_visible'] ?? false) === true));
    }

    public function publish(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->lifebook->publish($principal, $this->access->require($principal, $establishmentId, self::WRITE), self::id($request)));
    }

    public function withdraw(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->lifebook->withdraw($principal, $this->access->require($principal, $establishmentId, self::WRITE), self::id($request)));
    }

    public function media(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);

        return $this->binary($this->lifebook->staffMedia($principal, $perimeter, self::id($request)));
    }

    /** Réponse binaire d'un média (jamais mise en cache partagée). */
    public function binary(MediaContent $content): ResponseInterface
    {
        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', $content->mime)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($this->streams->createStream($content->content));
    }
}

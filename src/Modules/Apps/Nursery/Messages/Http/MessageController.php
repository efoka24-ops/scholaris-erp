<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Messages\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Messages\Application\SectionMessageService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\Input;
use Scholaris\Modules\Apps\Nursery\Support\Paging;

/** Messages de l'école aux parents des sections. */
final class MessageController extends Controller
{
    private const PERMISSIONS = ['nursery.message.send'];

    public function __construct(JsonResponder $responder, Access $access, private readonly SectionMessageService $messages)
    {
        parent::__construct($responder, $access);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->page($this->messages->page($this->access->require($principal, $establishmentId, self::PERMISSIONS), Paging::fromRequest($request)));
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::PERMISSIONS);
        $input = Input::of($request);
        $input->string('subject', 160);
        $input->string('body', 600);
        $input->references('section_ids', 50, false);

        return $this->json($this->messages->send($principal, $perimeter, $input->done()), 201);
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Documents\Application\DocumentService;
use Scholaris\Modules\Socle\Documents\Application\TemplateService;
use Scholaris\Modules\Socle\Documents\Http\Policies\DocumentPolicy;
use Scholaris\Modules\Socle\Documents\Http\Requests\Validator;
use Scholaris\Modules\Socle\Documents\Http\Resources\Resources;
use Scholaris\Modules\Socle\Documents\Infrastructure\TemplateRepository;

/** Modèles HTML par établissement et par langue, versionnés : brouillon, validation, nouvelle version. */
final class TemplateController extends BaseController
{
    private const MAX_HTML = 200000;

    public function __construct(HttpKit $kit, private readonly TemplateRepository $templates, private readonly TemplateService $service)
    {
        parent::__construct($kit);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, DocumentPolicy::READ, DocumentPolicy::TEMPLATES);
        $type = $request->getQueryParams()['filter']['type_code'] ?? null;
        $rows = $this->templates->all($this->tenant(), $this->establishment(), is_string($type) ? $type : null);

        return $this->responder->json(['data' => array_map(Resources::template(...), $rows)]);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, DocumentPolicy::READ, DocumentPolicy::TEMPLATES);

        return $this->responder->json(['data' => Resources::template($this->find($request))]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, DocumentPolicy::TEMPLATES);
        $v = new Validator(Validator::body($request), ['type_code', 'locale', 'html']);
        $type = $v->pattern('type_code', '/^[a-z][a-z0-9_]{1,29}$/', 'Code de type invalide.', true, 30);
        $locale = $v->enum('locale', DocumentService::LOCALES, true);
        $html = $v->string('html', self::MAX_HTML, true);
        $v->done();

        $row = $this->service->createTemplate($this->tenant(), $this->establishment(), $principal->userId, (string) $type, (string) $locale, (string) $html);

        return $this->responder->json(['data' => Resources::template($row)], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, DocumentPolicy::TEMPLATES);
        $v = new Validator(Validator::body($request), ['html']);
        $html = $v->string('html', self::MAX_HTML, true);
        $v->done();

        $row = $this->service->updateTemplate($this->tenant(), $this->establishment(), $principal->userId, $this->id($request), (string) $html);

        return $this->responder->json(['data' => Resources::template($row)]);
    }

    public function validateTemplate(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, DocumentPolicy::TEMPLATES);

        return $this->responder->json(['data' => Resources::template(
            $this->service->validate($this->tenant(), $this->establishment(), $principal->userId, $this->id($request)),
        )]);
    }

    public function newVersion(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, DocumentPolicy::TEMPLATES);
        $v = new Validator(Validator::body($request, true), ['html']);
        $html = $v->string('html', self::MAX_HTML);
        $v->done();

        $row = $this->service->newVersion($this->tenant(), $this->establishment(), $principal->userId, $this->id($request), $html);

        return $this->responder->json(['data' => Resources::template($row)], 201);
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, DocumentPolicy::TEMPLATES);
        $this->service->delete($this->tenant(), $this->establishment(), $principal->userId, $this->id($request));

        return $this->responder->json(['data' => ['deleted' => true]]);
    }

    /** @return array<string, mixed> */
    private function find(ServerRequestInterface $request): array
    {
        return $this->templates->find($this->tenant(), $this->establishment(), $this->id($request)) ?? throw HttpException::notFound('Modèle introuvable.');
    }
}

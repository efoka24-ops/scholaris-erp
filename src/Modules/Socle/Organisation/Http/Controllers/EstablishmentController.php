<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Controllers;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\ExportsList;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Organisation\Application\EstablishmentService;
use Scholaris\Modules\Socle\Organisation\Domain\EstablishmentType;
use Scholaris\Modules\Socle\Organisation\Domain\ModuleCatalog;
use Scholaris\Modules\Socle\Organisation\Http\Policies\OrganisationPolicy;
use Scholaris\Modules\Socle\Organisation\Http\RequestContext;
use Scholaris\Modules\Socle\Organisation\Http\Requests\CreateEstablishmentRequest;
use Scholaris\Modules\Socle\Organisation\Http\Requests\UpdateBrandingRequest;
use Scholaris\Modules\Socle\Organisation\Http\Requests\UpdateEstablishmentRequest;
use Scholaris\Modules\Socle\Organisation\Http\Requests\UpdateModulesRequest;
use Scholaris\Modules\Socle\Organisation\Http\Resources\EstablishmentResource;
use Scholaris\Modules\Socle\Organisation\Infrastructure\EstablishmentMapper;
use Scholaris\Modules\Socle\Organisation\Infrastructure\Repositories;

/**
 * Établissements, identité visuelle et modules activés. Routes sans X-Establishment-Id : l'établissement visé est
 * dans l'URL et toujours cherché dans le client de la personne authentifiée (autre client = 404).
 */
final class EstablishmentController
{
    use ExportsList;

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly RequestContext $context,
        private readonly Repositories $repositories,
        private readonly OrganisationPolicy $policy,
        private readonly EstablishmentService $establishments,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->context->principal($request);
        $query = ListQuery::fromRequest($request, ['type'], ['name', 'type', 'created_at']);

        $tenantWide = $this->policy->canRead($principal, null);
        $keep = $tenantWide ? null : fn (array $row): bool => $this->policy->canRead($principal, (string) $row['id']);
        [$rows, $next] = $this->repositories->request('establishments')->page($query, [], $keep);
        if (!$tenantWide && $rows === []) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de consulter les établissements.');
        }

        $links = ['next' => null];
        if ($next !== null) {
            $params = $request->getQueryParams();
            $params['cursor'] = $next;
            $links['next'] = $request->getUri()->getPath() . '?' . http_build_query($params);
        }

        return $this->responder->json([
            'data' => (new EstablishmentResource())->collection($rows),
            'links' => $links,
            'meta' => ['next_cursor' => $next],
        ]);
    }

    /** Export CSV / Excel des établissements visibles par la personne (mêmes filtres et mêmes droits que la liste). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->context->principal($request);
        $tenantWide = $this->policy->canRead($principal, null);
        $keep = $tenantWide ? null : fn (array $row): bool => $this->policy->canRead($principal, (string) $row['id']);
        $rows = [];
        $cursor = null;
        do {
            $params = $request->getQueryParams();
            $params['per_page'] = (string) ListQuery::MAX_PER_PAGE;
            unset($params['cursor']);
            if ($cursor !== null) {
                $params['cursor'] = $cursor;
            }
            $query = ListQuery::fromRequest($request->withQueryParams($params), ['type'], ['name', 'type', 'created_at']);
            [$chunk, $cursor] = $this->repositories->request('establishments')->page($query, [], $keep);
            array_push($rows, ...$chunk);
        } while ($cursor !== null && count($rows) < 5000);
        if (!$tenantWide && $rows === []) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de consulter les établissements.');
        }
        $resource = new EstablishmentResource();

        return $this->csvResponse(
            new Psr17Factory(),
            new Psr17Factory(),
            'etablissements.csv',
            ['Identifiant', 'Nom', 'Type', 'Modules activés', 'Créé le', 'Modifié le'],
            array_map(static function (array $row) use ($resource): array {
                $r = $resource->toArray($row);
                $modules = is_array($r['modules_enabled']) ? implode(', ', array_map('strval', array_filter($r['modules_enabled'], 'is_scalar'))) : '';

                return [$r['id'], $r['name'], $r['type'], $modules, $r['created_at'], $r['updated_at']];
            }, array_slice($rows, 0, 5000)),
            $request,
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $row = $this->context->establishment($this->id($request));
        $this->policy->authorizeRead($this->context->principal($request), (string) $row['id']);

        return $this->responder->json(['data' => (new EstablishmentResource())->toArray($row)]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->context->principal($request);
        $this->policy->authorizeManage($principal, null);
        $data = (new CreateEstablishmentRequest())->fromHttp($request);

        $row = $this->establishments->create($this->context->tenantId(), (string) $data['name'], EstablishmentType::from((string) $data['type']));
        $resource = (new EstablishmentResource())->toArray($row);
        $this->context->audit($request, 'establishment.created', 'establishment', (string) $row['id'], null, $resource);

        return $this->responder->json(['data' => $resource], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $before = $this->context->establishment($this->id($request));
        $this->policy->authorizeManage($this->context->principal($request), (string) $before['id']);
        $data = (new UpdateEstablishmentRequest())->fromHttp($request);

        $row = $this->establishments->rename($this->context->tenantId(), (string) $before['id'], (string) $data['name']);
        $resource = new EstablishmentResource();
        $this->context->audit($request, 'establishment.updated', 'establishment', (string) $row['id'], $resource->toArray($before), $resource->toArray($row));

        return $this->responder->json(['data' => $resource->toArray($row)]);
    }

    public function branding(ServerRequestInterface $request): ResponseInterface
    {
        $row = $this->context->establishment($this->id($request));
        $this->policy->authorizeRead($this->context->principal($request), (string) $row['id']);

        return $this->responder->json(['data' => EstablishmentMapper::decode((string) $row['branding'])]);
    }

    public function updateBranding(ServerRequestInterface $request): ResponseInterface
    {
        $before = $this->context->establishment($this->id($request));
        $this->policy->authorizeManage($this->context->principal($request), (string) $before['id']);
        $changes = (new UpdateBrandingRequest())
            ->forEstablishment($this->context->tenantId(), (string) $before['id'])
            ->fromHttp($request, true);

        $row = $this->establishments->updateBranding($this->context->tenantId(), (string) $before['id'], $changes);
        $this->context->audit(
            $request,
            'establishment.branding_updated',
            'establishment',
            (string) $row['id'],
            ['branding' => EstablishmentMapper::decode((string) $before['branding'])],
            ['branding' => EstablishmentMapper::decode((string) $row['branding'])],
        );

        return $this->responder->json(['data' => EstablishmentMapper::decode((string) $row['branding'])]);
    }

    public function modules(ServerRequestInterface $request): ResponseInterface
    {
        $row = $this->context->establishment($this->id($request));
        $this->policy->authorizeRead($this->context->principal($request), (string) $row['id']);

        return $this->responder->json(['data' => $this->modulesPayload($row)]);
    }

    public function updateModules(ServerRequestInterface $request): ResponseInterface
    {
        $before = $this->context->establishment($this->id($request));
        $this->policy->authorizeManage($this->context->principal($request), (string) $before['id']);
        $data = (new UpdateModulesRequest())->fromHttp($request);

        /** @var list<string> $modules */
        $modules = $data['modules'];
        $row = $this->establishments->setModules($this->context->tenantId(), (string) $before['id'], $modules);
        $this->context->audit(
            $request,
            'establishment.modules_updated',
            'establishment',
            (string) $row['id'],
            ['modules_enabled' => EstablishmentMapper::decode((string) $before['modules_enabled'])],
            ['modules_enabled' => $modules],
        );

        return $this->responder->json(['data' => $this->modulesPayload($row)]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{modules_enabled: array<mixed>, available: list<string>}
     */
    private function modulesPayload(array $row): array
    {
        return ['modules_enabled' => EstablishmentMapper::decode((string) $row['modules_enabled']), 'available' => ModuleCatalog::KEYS];
    }

    private function id(ServerRequestInterface $request): string
    {
        return (string) $request->getAttribute('id');
    }
}

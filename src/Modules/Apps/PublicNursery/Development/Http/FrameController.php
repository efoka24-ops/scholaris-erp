<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Development\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicNursery\Development\Application\FrameService;
use Scholaris\Modules\Apps\PublicNursery\Support\EstablishmentGuard;
use Scholaris\Modules\Apps\PublicNursery\Support\Paging;

final class FrameController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PermissionChecker $permissions,
        private readonly EstablishmentGuard $guard,
        private readonly FrameService $frames,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, 'settings.manage');
        $paging = Paging::fromRequest($request);

        return $this->responder->json($paging->envelope($this->frames->all($principal->tenantId, $paging->limit(), $paging->offset)));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, 'settings.manage');
        $row = $this->frames->show($principal->tenantId, (string) $request->getAttribute('id'));
        if ($row === null) {
            throw HttpException::notFound('Cadre introuvable.');
        }

        return $this->responder->json(['data' => $row]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, 'settings.manage');
        $body = $this->jsonBody($request);
        $row = $this->frames->save($principal->tenantId, $body);

        return $this->responder->json(['data' => $row], 201);
    }

    public function importCsv(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, 'settings.manage');
        $body = $this->jsonBody($request);
        $csv = $body['csv'] ?? null;
        if (!is_string($csv)) {
            throw HttpException::validation(['csv' => ['CSV requis.']]);
        }
        $validateOnly = ($body['validate_only'] ?? false) === true;

        $report = $this->frames->importCsv($principal->tenantId, $csv, $validateOnly);
        if ($report['errors'] !== [] && !$validateOnly) {
            throw HttpException::validation(['csv' => ['Import refusé.']], 'Aucune ligne n\'a été enregistrée.');
        }

        return $this->responder->json(['data' => $report], $report['created'] > 0 ? 201 : 200);
    }

    private function authorize(ServerRequestInterface $request, string ...$anyOf): Principal
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        $establishment = self::establishment($request);
        $this->guard->assertNursery($principal, $principal->tenantId, $establishment);
        foreach ($anyOf as $permission) {
            if ($this->permissions->can($principal, $permission, $establishment)) {
                return $principal;
            }
        }

        throw HttpException::forbidden('Permission requise : ' . implode(' ou ', $anyOf) . '.');
    }

    /** @return array<string, mixed> */
    private function jsonBody(ServerRequestInterface $request): array
    {
        $raw = trim((string) $request->getBody());
        $body = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($body)) {
            throw HttpException::validation(['body' => ['JSON attendu.']]);
        }

        return $body;
    }

    private static function establishment(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('establishment_id');

        return is_string($id) && $id !== '' ? $id : throw HttpException::badRequest('En-tête X-Establishment-Id manquant.');
    }
}

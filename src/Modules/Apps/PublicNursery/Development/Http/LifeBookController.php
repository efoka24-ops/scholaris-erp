<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Development\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicNursery\Development\Application\LifeBookService;
use Scholaris\Modules\Apps\PublicNursery\Support\EstablishmentGuard;
use Scholaris\Modules\Apps\PublicNursery\Support\Paging;
use Scholaris\Modules\Apps\PublicNursery\Support\SectionScopeResolver;

final class LifeBookController
{
    private const READ = ['public_nursery.lifebook.write', 'public_nursery.lifebook.view', 'settings.manage'];
    private const WRITE = ['public_nursery.lifebook.write', 'settings.manage'];

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PermissionChecker $permissions,
        private readonly EstablishmentGuard $guard,
        private readonly SectionScopeResolver $scopes,
        private readonly LifeBookService $lifeBooks,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, self::READ);
        $establishmentId = self::establishment($request);
        $structureId = $this->queryString($request, 'structure_id');
        $scope = $this->scopes->resolve($principal, self::READ, $establishmentId);
        if ($structureId !== null) {
            $this->guard->assertSection($principal, $scope, $structureId);
        }
        $paging = Paging::fromRequest($request);
        $rows = $this->lifeBooks->all(
            $principal->tenantId,
            $establishmentId,
            $structureId,
            $paging->limit(),
            $paging->offset,
            $scope->isWholeEstablishment() ? null : $scope->sectionIds(),
        );

        return $this->responder->json($paging->envelope($rows));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, self::READ);
        $row = $this->lifeBooks->find($principal->tenantId, self::establishment($request), (string) $request->getAttribute('id'));
        if ($row === null) {
            throw HttpException::notFound('Cahier de vie introuvable.');
        }
        $this->assertSection($principal, self::READ, self::establishment($request), (string) $row['structure_id']);

        return $this->responder->json(['data' => $row]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, self::WRITE);
        $body = $this->jsonBody($request);
        $structureId = $this->string($body, 'structure_id');
        $this->assertSection($principal, self::WRITE, self::establishment($request), $structureId);
        $entry = $this->lifeBooks->saveDraft($principal->tenantId, self::establishment($request), $structureId, $principal->userId, $body);
        $items = $body['media'] ?? [];
        if (!is_array($items)) {
            throw HttpException::validation(['media' => ['Tableau de médias attendu.']]);
        }
        foreach ($items as $media) {
            if (is_array($media)) {
                $this->lifeBooks->attachMedia($principal->tenantId, self::establishment($request), (string) $entry['id'], $media);
            }
        }

        return $this->responder->json(['data' => $this->lifeBooks->find($principal->tenantId, self::establishment($request), (string) $entry['id']) ?? $entry], 201);
    }

    public function attachMedia(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, self::WRITE);
        $this->assertEntrySection($principal, $request, self::WRITE);
        $body = $this->jsonBody($request);

        return $this->responder->json(['data' => $this->lifeBooks->attachMedia($principal->tenantId, self::establishment($request), (string) $request->getAttribute('id'), $body)], 201);
    }

    public function publish(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, self::WRITE);
        $this->assertEntrySection($principal, $request, self::WRITE);

        return $this->responder->json(['data' => $this->lifeBooks->publish($principal->tenantId, self::establishment($request), (string) $request->getAttribute('id'))]);
    }

    public function media(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, self::READ);
        $this->assertEntrySection($principal, $request, self::READ);
        $expires = filter_var($request->getQueryParams()['expires'] ?? null, FILTER_VALIDATE_INT);
        $signature = (string) ($request->getQueryParams()['signature'] ?? '');
        if (!is_int($expires) || $expires < 1 || $signature === '') {
            throw HttpException::validation(['media' => ['Lien signé incomplet.']]);
        }
        $content = $this->lifeBooks->readMedia($principal->tenantId, self::establishment($request), (string) $request->getAttribute('id'), (string) $request->getAttribute('mediaId'), $expires, $signature);

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', $content->mime)
            ->withHeader('Content-Disposition', 'inline; filename="media.' . $content->extension . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStream($content->content));
    }

    /** @param list<string> $anyOf */
    private function authorize(ServerRequestInterface $request, array $anyOf): Principal
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

    /** @param list<string> $anyOf */
    private function assertSection(Principal $principal, array $anyOf, string $establishmentId, string $sectionId): void
    {
        $this->guard->assertSection($principal, $this->scopes->resolve($principal, $anyOf, $establishmentId), $sectionId);
    }

    /** @param list<string> $anyOf */
    private function assertEntrySection(Principal $principal, ServerRequestInterface $request, array $anyOf): void
    {
        $establishmentId = self::establishment($request);
        $structureId = $this->lifeBooks->structureOf($principal->tenantId, $establishmentId, (string) $request->getAttribute('id'))
            ?? throw HttpException::notFound('Cahier de vie introuvable.');
        $this->assertSection($principal, $anyOf, $establishmentId, $structureId);
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

    /** @param array<string, mixed> $body */
    private function string(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw HttpException::validation([$key => ['Valeur requise.']]);
        }

        return trim($value);
    }

    private function queryString(ServerRequestInterface $request, string $key): ?string
    {
        $value = $request->getQueryParams()[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function establishment(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('establishment_id');

        return is_string($id) && $id !== '' ? $id : throw HttpException::badRequest('En-tête X-Establishment-Id manquant.');
    }
}

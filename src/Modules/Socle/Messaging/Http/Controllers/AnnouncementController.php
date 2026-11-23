<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Messaging\Application\MessagingClock;
use Scholaris\Modules\Socle\Messaging\Http\Access;
use Scholaris\Modules\Socle\Messaging\Http\Requests\AnnouncementRequest;
use Scholaris\Modules\Socle\Messaging\Http\Resources\AnnouncementResource;
use Scholaris\Modules\Socle\Messaging\Infrastructure\Store;

/** Annonces d'un établissement : publiées à la création, éventuellement expirées. */
final class AnnouncementController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly Access $access,
        private readonly Store $store,
        private readonly MessagingClock $clock,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeRead($request);
        $query = ListQuery::fromRequest($request);
        [$rows, $next] = $this->store->page('announcements', $this->access->tenantId(), $this->access->establishmentId(), [], $query);

        return Pages::respond($this->responder, $request, new AnnouncementResource(), $rows, $next);
    }

    /** Export CSV / Excel des annonces (mêmes droits que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeRead($request);
        $rows = Exports::collect($request, [], fn (ListQuery $query): array => $this->store->page('announcements', $this->access->tenantId(), $this->access->establishmentId(), [], $query));

        return Exports::respond($request, 'annonces.csv', new AnnouncementResource(), [
            'id' => 'Identifiant', 'title' => 'Titre', 'body' => 'Texte', 'published_at' => 'Publiée le', 'expires_at' => 'Expire le',
            'created_by' => 'Créée par', 'created_at' => 'Créée le', 'updated_at' => 'Modifiée le',
        ], $rows);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeRead($request);

        return $this->responder->json(['data' => (new AnnouncementResource())->toArray($this->find($request))]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeSend($request);
        $data = (new AnnouncementRequest())->fromHttp($request);
        $id = (string) new Ulid();
        $now = $this->clock->iso();
        $this->store->insert('announcements', [
            'id' => $id,
            'tenant_id' => $this->access->tenantId(),
            'establishment_id' => $this->access->establishmentId(),
            'created_by' => $this->access->actorId($request),
            'title' => $data['title'],
            'body' => $data['body'],
            'published_at' => $now,
            'expires_at' => $data['expires_at'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $row = $this->store->find('announcements', $this->access->tenantId(), $this->access->establishmentId(), $id) ?? throw HttpException::notFound();
        $this->access->audit($request, 'announcement.created', 'announcement', $id, null, (new AnnouncementResource())->toArray($row));

        return $this->responder->json(['data' => (new AnnouncementResource())->toArray($row)], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeSend($request);
        $existing = $this->find($request);
        $data = (new AnnouncementRequest())->fromHttp($request, true);
        $this->store->update('announcements', $this->access->tenantId(), $this->access->establishmentId(), (string) $existing['id'], $data + ['updated_at' => $this->clock->iso()]);
        $row = $this->store->find('announcements', $this->access->tenantId(), $this->access->establishmentId(), (string) $existing['id']) ?? throw HttpException::notFound();
        $this->access->audit($request, 'announcement.updated', 'announcement', (string) $existing['id'], (new AnnouncementResource())->toArray($existing), (new AnnouncementResource())->toArray($row));

        return $this->responder->json(['data' => (new AnnouncementResource())->toArray($row)]);
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeSend($request);
        $existing = $this->find($request);
        $this->store->delete('announcements', $this->access->tenantId(), $this->access->establishmentId(), (string) $existing['id']);
        $this->access->audit($request, 'announcement.deleted', 'announcement', (string) $existing['id'], (new AnnouncementResource())->toArray($existing), null);

        return $this->responder->json(['data' => null]);
    }

    /** @return array<string, mixed> */
    private function find(ServerRequestInterface $request): array
    {
        return $this->store->find('announcements', $this->access->tenantId(), $this->access->establishmentId(), (string) $request->getAttribute('id'))
            ?? throw HttpException::notFound('Annonce introuvable.');
    }
}

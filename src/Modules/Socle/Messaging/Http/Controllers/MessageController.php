<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Messaging\Application\AudienceResolver;
use Scholaris\Modules\Socle\Messaging\Application\MessageService;
use Scholaris\Modules\Socle\Messaging\Http\Access;
use Scholaris\Modules\Socle\Messaging\Http\Requests\AudiencePreviewRequest;
use Scholaris\Modules\Socle\Messaging\Http\Requests\MessageRequest;
use Scholaris\Modules\Socle\Messaging\Http\Resources\DeliveryResource;
use Scholaris\Modules\Socle\Messaging\Http\Resources\MessageResource;
use Scholaris\Modules\Socle\Messaging\Infrastructure\DeliveryRepository;
use Scholaris\Modules\Socle\Messaging\Infrastructure\Store;

/** Messages en masse : création, aperçu d'audience, envoi, liste et suivi de délivrance par destinataire. */
final class MessageController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly Access $access,
        private readonly Store $store,
        private readonly MessageService $messages,
        private readonly AudienceResolver $resolver,
        private readonly DeliveryRepository $deliveries,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeRead($request);
        $query = ListQuery::fromRequest($request, ['status', 'template_key']);
        [$rows, $next] = $this->store->page('messages', $this->access->tenantId(), $this->access->establishmentId(), $query->filters, $query);

        return Pages::respond($this->responder, $request, new MessageResource(), $rows, $next);
    }

    /** Export CSV / Excel des messages (mêmes filtres et mêmes droits que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeRead($request);
        $rows = Exports::collect($request, ['status', 'template_key'], fn (ListQuery $query): array => $this->store->page('messages', $this->access->tenantId(), $this->access->establishmentId(), $query->filters, $query));

        return Exports::respond($request, 'messages.csv', new MessageResource(), [
            'id' => 'Identifiant', 'template_key' => 'Modèle', 'body' => 'Corps', 'channels' => 'Canaux', 'audience' => 'Audience', 'urgent' => 'Urgent',
            'status' => 'Statut', 'scheduled_at' => 'Programmé le', 'recipients_count' => 'Destinataires', 'created_by' => 'Créé par', 'created_at' => 'Créé le',
        ], $rows);
    }

    /** Export CSV / Excel du journal d'envoi d'un message (mêmes filtres et mêmes droits que la liste des livraisons). */
    public function exportDeliveries(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeRead($request);
        $tenantId = $this->access->tenantId();
        $establishmentId = $this->access->establishmentId();
        $message = $this->messages->message($tenantId, $establishmentId, $this->id($request));
        $rows = Exports::collect($request, ['status', 'channel'], fn (ListQuery $query): array => $this->store->page('message_deliveries', $tenantId, $establishmentId, ['message_id' => (string) $message['id']] + $query->filters, $query));

        return Exports::respond($request, 'journal-envoi.csv', new DeliveryResource(), [
            'id' => 'Identifiant', 'message_id' => 'Message', 'recipient_id' => 'Destinataire', 'channel' => 'Canal', 'status' => 'Statut', 'reason' => 'Motif',
            'provider_reference' => 'Référence opérateur', 'not_before' => 'Pas avant', 'attempts' => 'Tentatives', 'sent_at' => 'Envoyé le', 'delivered_at' => 'Remis le', 'created_at' => 'Créé le',
        ], $rows);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeRead($request);
        $row = $this->messages->message($this->access->tenantId(), $this->access->establishmentId(), $this->id($request));

        return $this->responder->json(['data' => $this->detail($row)]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeSend($request);
        $data = (new MessageRequest())->fromHttp($request);
        $row = $this->messages->createDraft($this->access->tenantId(), $this->access->establishmentId(), $this->access->actorId($request), $data);
        $this->access->audit($request, 'message.created', 'message', (string) $row['id'], null, (new MessageResource())->toArray($row));

        return $this->responder->json(['data' => $this->detail($row)], 201);
    }

    public function send(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeSend($request);
        $row = $this->messages->send($this->access->tenantId(), $this->access->establishmentId(), $this->id($request));
        $this->access->audit($request, 'message.sent', 'message', (string) $row['id'], null, (new MessageResource())->toArray($row));

        return $this->responder->json(['data' => $this->detail($row)]);
    }

    public function deliveries(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeRead($request);
        $tenantId = $this->access->tenantId();
        $establishmentId = $this->access->establishmentId();
        $message = $this->messages->message($tenantId, $establishmentId, $this->id($request));
        $query = ListQuery::fromRequest($request, ['status', 'channel']);
        [$rows, $next] = $this->store->page('message_deliveries', $tenantId, $establishmentId, ['message_id' => (string) $message['id']] + $query->filters, $query);

        return Pages::respond($this->responder, $request, new DeliveryResource(), $rows, $next);
    }

    public function audiencePreview(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeSend($request);
        $data = (new AudiencePreviewRequest())->fromHttp($request);

        return $this->responder->json(['data' => $this->resolver->preview($this->access->tenantId(), $this->access->establishmentId(), $data['audience'])]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function detail(array $row): array
    {
        return (new MessageResource())->toArray($row) + [
            'delivery_counts' => (object) $this->deliveries->countsByStatus((string) $row['tenant_id'], (string) $row['establishment_id'], (string) $row['id']),
        ];
    }

    private function id(ServerRequestInterface $request): string
    {
        return (string) $request->getAttribute('id');
    }
}

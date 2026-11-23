<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Messaging\Application\ChatService;
use Scholaris\Modules\Socle\Messaging\Http\Access;
use Scholaris\Modules\Socle\Messaging\Http\Resources\ConversationReportResource;
use Scholaris\Modules\Socle\Messaging\Infrastructure\Store;

/** File de modération : signalements de messages, traités par un modérateur (messaging.moderate). */
final class ConversationReportController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly Access $access,
        private readonly Store $store,
        private readonly ChatService $chat,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeModerate($request);
        $query = ListQuery::fromRequest($request, ['status', 'conversation_id']);
        [$rows, $next] = $this->store->page('conversation_reports', $this->access->tenantId(), $this->access->establishmentId(), $query->filters, $query);

        return Pages::respond($this->responder, $request, new ConversationReportResource(), $rows, $next);
    }

    /** Export CSV / Excel des signalements (mêmes filtres et mêmes droits que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeModerate($request);
        $rows = Exports::collect($request, ['status', 'conversation_id'], fn (ListQuery $query): array => $this->store->page('conversation_reports', $this->access->tenantId(), $this->access->establishmentId(), $query->filters, $query));

        return Exports::respond($request, 'conversations-signalees.csv', new ConversationReportResource(), [
            'id' => 'Identifiant', 'conversation_id' => 'Conversation', 'message_id' => 'Message', 'reported_by' => 'Signalé par', 'reason' => 'Motif',
            'status' => 'Statut', 'resolved_by' => 'Traité par', 'resolved_at' => 'Traité le', 'created_at' => 'Signalé le',
        ], $rows);
    }

    public function dismiss(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeModerate($request);
        $report = $this->chat->dismiss($this->access->tenantId(), $this->access->establishmentId(), $this->access->actorId($request), (string) $request->getAttribute('id'));
        $this->access->audit($request, 'conversation.report_dismissed', 'conversation_report', (string) $report['id'], null, null);

        return $this->responder->json(['data' => (new ConversationReportResource())->toArray($report)]);
    }
}

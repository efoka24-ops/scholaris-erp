<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Messaging\Application\ChatService;
use Scholaris\Modules\Socle\Messaging\Http\Access;
use Scholaris\Modules\Socle\Messaging\Http\Requests\ChatMessageRequest;
use Scholaris\Modules\Socle\Messaging\Http\Requests\ConversationRequest;
use Scholaris\Modules\Socle\Messaging\Http\Requests\ReasonRequest;
use Scholaris\Modules\Socle\Messaging\Http\Resources\ChatMessageResource;
use Scholaris\Modules\Socle\Messaging\Http\Resources\ConversationReportResource;
use Scholaris\Modules\Socle\Messaging\Http\Resources\ConversationResource;
use Scholaris\Modules\Socle\Messaging\Infrastructure\ChatRepository;
use Scholaris\Modules\Socle\Messaging\Infrastructure\Store;

/**
 * Messagerie interne modérée. Les participants (permission messaging.chat) ne voient que leurs conversations ;
 * un modérateur (messaging.moderate) voit toutes celles de l'établissement, et chaque lecture hors participation est auditée.
 */
final class ConversationController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly Access $access,
        private readonly Store $store,
        private readonly ChatRepository $repository,
        private readonly ChatService $chat,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = ListQuery::fromRequest($request, ['status', 'kind', 'scope']);
        $filters = $query->filters;
        $scope = $filters['scope'] ?? 'mine';
        unset($filters['scope']);
        if (!in_array($scope, ['mine', 'all'], true)) {
            throw HttpException::validation(['filter[scope]' => ['Valeurs possibles : mine, all.']]);
        }

        if ($scope === 'all') {
            $this->access->authorizeModerate($request);
            [$rows, $next] = $this->store->page('conversations', $this->access->tenantId(), $this->access->establishmentId(), $filters, $query);
        } else {
            $this->access->authorizeChat($request);
            [$rows, $next] = $this->repository->pageForPerson(
                $this->access->tenantId(),
                $this->access->establishmentId(),
                $this->person($request),
                $filters,
                $query,
            );
        }

        return Pages::respond($this->responder, $request, new ConversationResource(), $rows, $next);
    }

    public function contacts(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeChat($request);
        $learner = $request->getQueryParams()['learner_id'] ?? null;
        if (!is_string($learner) || preg_match(ConversationRequest::ID_PATTERN, $learner) !== 1) {
            throw HttpException::validation(['learner_id' => ['Ce paramètre est obligatoire.']]);
        }

        return $this->responder->json(['data' => $this->chat->contacts($this->access->tenantId(), $this->access->establishmentId(), $this->person($request), $learner)]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeChat($request);
        $data = (new ConversationRequest())->fromHttp($request);
        $row = $this->chat->create($this->access->tenantId(), $this->access->establishmentId(), $this->person($request), $data);
        $this->access->audit($request, 'conversation.created', 'conversation', (string) $row['id'], null, (new ConversationResource())->toArray($row));

        return $this->responder->json(['data' => $this->detail($row)], 201);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$row] = $this->open($request);

        return $this->responder->json(['data' => $this->detail($row)]);
    }

    public function messages(ServerRequestInterface $request): ResponseInterface
    {
        [$row, $participant, $moderatorView] = $this->open($request);
        $tenantId = $this->access->tenantId();
        $query = ListQuery::fromRequest($request);
        [$rows, $next] = $this->store->page('conversation_messages', $tenantId, $this->access->establishmentId(), ['conversation_id' => (string) $row['id']], $query);

        if ($participant !== null) {
            if ($rows !== []) {
                $this->chat->markRead($tenantId, (string) $row['id'], (string) $participant['person_id'], (string) $rows[0]['id']);
            }
        } else {
            $this->access->audit($request, 'conversation.moderator_read', 'conversation', (string) $row['id'], null, null);
        }

        return Pages::respond($this->responder, $request, new ChatMessageResource($moderatorView), $rows, $next);
    }

    public function post(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeChat($request);
        $data = (new ChatMessageRequest())->fromHttp($request);
        $message = $this->chat->post($this->access->tenantId(), $this->access->establishmentId(), $this->person($request), $this->id($request), $data);

        return $this->responder->json(['data' => (new ChatMessageResource())->toArray($message)], 201);
    }

    public function report(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeChat($request);
        $data = (new ReasonRequest())->fromHttp($request);
        $report = $this->chat->report(
            $this->access->tenantId(),
            $this->access->establishmentId(),
            $this->person($request),
            $this->id($request),
            (string) $request->getAttribute('messageId'),
            (string) $data['reason'],
        );
        $this->access->audit($request, 'conversation.message_reported', 'conversation_report', (string) $report['id'], null, (new ConversationReportResource())->toArray($report));

        return $this->responder->json(['data' => (new ConversationReportResource())->toArray($report)], 201);
    }

    public function hide(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeModerate($request);
        $data = (new ReasonRequest())->fromHttp($request);
        $message = $this->chat->hide(
            $this->access->tenantId(),
            $this->access->establishmentId(),
            $this->access->actorId($request),
            $this->id($request),
            (string) $request->getAttribute('messageId'),
            (string) $data['reason'],
        );
        $this->access->audit($request, 'conversation.message_hidden', 'conversation_message', (string) $message['id'], null, ['reason' => $data['reason']]);

        return $this->responder->json(['data' => (new ChatMessageResource(true))->toArray($message)]);
    }

    public function restore(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeModerate($request);
        $message = $this->chat->restore(
            $this->access->tenantId(),
            $this->access->establishmentId(),
            $this->access->actorId($request),
            $this->id($request),
            (string) $request->getAttribute('messageId'),
        );
        $this->access->audit($request, 'conversation.message_restored', 'conversation_message', (string) $message['id'], null, null);

        return $this->responder->json(['data' => (new ChatMessageResource(true))->toArray($message)]);
    }

    public function archive(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeModerate($request);
        $row = $this->chat->archive($this->access->tenantId(), $this->access->establishmentId(), $this->id($request));
        $this->access->audit($request, 'conversation.archived', 'conversation', (string) $row['id'], null, null);

        return $this->responder->json(['data' => (new ConversationResource())->toArray($row)]);
    }

    /**
     * Ouvre une conversation en lecture : participant (messaging.chat) ou modérateur.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: bool} conversation, participation, vue modérateur
     */
    private function open(ServerRequestInterface $request): array
    {
        $moderator = $this->access->canModerate($request);
        if (!$moderator) {
            $this->access->authorizeChat($request);
        }
        $opened = $this->chat->visibleTo(
            $this->access->tenantId(),
            $this->access->establishmentId(),
            $this->id($request),
            $this->personOrNull($request) ?? '',
            $moderator,
        );

        return [$opened['conversation'], $opened['participant'], $moderator];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function detail(array $row): array
    {
        return (new ConversationResource())->toArray($row) + ['participants' => $this->chat->participants($this->access->tenantId(), (string) $row['id'])];
    }

    private function person(ServerRequestInterface $request): string
    {
        return $this->chat->personOf($this->access->tenantId(), $this->access->actorId($request));
    }

    private function personOrNull(ServerRequestInterface $request): ?string
    {
        try {
            return $this->person($request);
        } catch (HttpException) {
            return null;
        }
    }

    private function id(ServerRequestInterface $request): string
    {
        return (string) $request->getAttribute('id');
    }
}

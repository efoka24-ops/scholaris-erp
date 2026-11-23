<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Messaging\Application\ChatService;
use Scholaris\Modules\Socle\Messaging\Application\SupportService;
use Scholaris\Modules\Socle\Messaging\Http\Access;
use Scholaris\Modules\Socle\Messaging\Http\Requests\SupportConversationRequest;
use Scholaris\Modules\Socle\Messaging\Http\Resources\ConversationResource;

/**
 * POST /support-conversations : un administrateur de l'établissement (messaging.support) ouvre une conversation avec le
 * support SCHOLARIS. La suite de l'échange passe par les routes /conversations/{id}/messages (participant).
 */
final class SupportConversationController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly Access $access,
        private readonly SupportService $support,
        private readonly ChatService $chat,
    ) {
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeSupport($request);
        $data = (new SupportConversationRequest())->fromHttp($request);
        $tenantId = $this->access->tenantId();
        $establishmentId = $this->access->establishmentId();
        $person = $this->chat->personOf($tenantId, $this->access->actorId($request));
        $row = $this->support->openFromClient($tenantId, $establishmentId, $person, (string) $data['subject'], (string) $data['body']);
        $this->access->audit($request, 'conversation.support_opened', 'conversation', (string) $row['id'], null, ['subject' => $data['subject']]);

        return $this->responder->json(['data' => (new ConversationResource())->toArray($row) + [
            'participants' => $this->chat->participants($tenantId, (string) $row['id']),
        ]], 201);
    }
}

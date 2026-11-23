<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Identity\Contracts\AccountAdministration;
use Scholaris\Modules\Socle\Messaging\Contracts\SupportDesk;
use Scholaris\Modules\Socle\Organisation\Contracts\ClientProvisioning;
use Scholaris\Modules\Socle\Platform\Application\AdministratorAccounts;
use Scholaris\Modules\Socle\Platform\Application\PlatformAudit;

/**
 * Boîte du support SCHOLARIS (back-office). Lecture et réponse : `platform.support.manage` (platform_admin,
 * platform_support) ; ouverture vers les administrateurs d'un client : `platform.support.open` (platform_admin).
 * Cloisonnement : tout passe par le contrat SupportDesk, qui ne sert QUE les conversations de nature « support » ;
 * les conversations internes des établissements restent introuvables (404). Chaque écriture est journalisée.
 */
final class SupportAdminController
{
    public const MANAGE = 'platform.support.manage';
    public const OPEN = 'platform.support.open';
    /** Rôles de la plateforme dont les membres peuvent recevoir une conversation (platform_support : C-112). */
    public const AGENT_ROLES = [AdministratorAccounts::PLATFORM_ROLE, 'platform_support'];
    private const ID = '/^[A-Za-z0-9_-]{1,40}$/';

    /** @var array<string, string>|null */
    private ?array $tenantNames = null;
    /** @var array<string, array<string, string>> */
    private array $establishmentNames = [];

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PlatformGuard $guard,
        private readonly SupportDesk $desk,
        private readonly ClientProvisioning $clients,
        private readonly AccountAdministration $accounts,
        private readonly PlatformAudit $audit,
    ) {
    }

    /** GET /platform/support/conversations — filtres status (open, pending, resolved), tenant_id, assignee (« none » = non assignées). */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, self::MANAGE);
        $query = ListQuery::fromRequest($request, ['status', 'tenant_id', 'assignee']);
        foreach (['tenant_id', 'assignee'] as $filter) {
            if (isset($query->filters[$filter]) && preg_match(self::ID, $query->filters[$filter]) !== 1) {
                throw HttpException::validation(['filter[' . $filter . ']' => ['Identifiant invalide.']]);
            }
        }
        $page = $this->desk->inbox($query->filters, $query->cursor, $query->perPage);

        return $this->responder->json([
            'data' => array_map(fn (array $row): array => $this->label($row), $page['items']),
            'meta' => ['next_cursor' => $page['next_cursor']],
        ]);
    }

    /** GET /platform/support/conversations/{id} */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, self::MANAGE);

        return $this->responder->json(['data' => $this->label($this->desk->conversation(PlatformGuard::param($request, 'id')))]);
    }

    /** GET /platform/support/conversations/{id}/messages — du plus récent au plus ancien ; marque la conversation lue. */
    public function messages(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, self::MANAGE);
        $query = ListQuery::fromRequest($request);
        $cursor = ListQuery::decodeCursor($query->cursor);
        $page = $this->desk->messages(PlatformGuard::param($request, 'id'), $cursor, $query->perPage);

        return $this->responder->json([
            'data' => $page['items'],
            'meta' => ['next_cursor' => $page['next_cursor'] === null ? null : ListQuery::encodeCursor($page['next_cursor'])],
        ]);
    }

    /** POST /platform/support/conversations/{id}/messages {body} */
    public function reply(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, self::MANAGE);
        $input = Input::of($request, ['body']);
        $body = $input->longText('body', 5000, true);
        $input->validate();
        $id = PlatformGuard::param($request, 'id');
        $message = $this->desk->reply($id, $principal->userId, (string) $body);
        $conversation = $this->desk->conversation($id);
        $this->audit->record((string) $conversation['tenant_id'], $principal->userId, 'platform.support_replied', 'conversation', $id, [], PlatformGuard::ip($request));

        return $this->responder->json(['data' => $message], 201);
    }

    /** POST /platform/support/conversations/{id}/status {status} */
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, self::MANAGE);
        $input = Input::of($request, ['status']);
        $status = $input->choice('status', SupportDesk::STATUSES);
        $input->validate();
        $id = PlatformGuard::param($request, 'id');
        $conversation = $this->desk->setStatus($id, (string) $status);
        $this->audit->record((string) $conversation['tenant_id'], $principal->userId, 'platform.support_status_changed', 'conversation', $id, ['status' => $status], PlatformGuard::ip($request));

        return $this->responder->json(['data' => $this->label($conversation)]);
    }

    /** POST /platform/support/conversations/{id}/assign {user_id: string|null} — membre du support, ou null pour désassigner. */
    public function assign(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, self::MANAGE);
        $input = Input::of($request, ['user_id']);
        $userId = $input->has('user_id') && $input->raw('user_id') !== null ? $input->text('user_id', 1, 40, true, self::ID) : null;
        $input->validate();
        if ($userId !== null && !in_array($userId, array_column($this->agentList(), 'user_id'), true)) {
            throw HttpException::validation(['user_id' => ['Membre du support inconnu ou inactif.']]);
        }
        $id = PlatformGuard::param($request, 'id');
        $conversation = $this->desk->assign($id, $userId);
        $this->audit->record((string) $conversation['tenant_id'], $principal->userId, 'platform.support_assigned', 'conversation', $id, ['assignee' => $userId], PlatformGuard::ip($request));

        return $this->responder->json(['data' => $this->label($conversation)]);
    }

    /** POST /platform/support/conversations {tenant_id, establishment_id, subject, body} — vers les super administrateurs actifs du client. */
    public function open(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, self::OPEN);
        $input = Input::of($request, ['tenant_id', 'establishment_id', 'subject', 'body']);
        $tenantId = $input->text('tenant_id', 1, 40, true, self::ID);
        $establishmentId = $input->text('establishment_id', 1, 40, true, self::ID);
        $subject = $input->text('subject', 1, 160);
        $body = $input->longText('body', 5000, true);
        $input->validate();
        // Client et établissement : vérifiés par SupportDesk (établissement de ce client, sinon 422).
        if ($tenantId === PlatformAudit::PLATFORM_TENANT) {
            throw HttpException::validation(['tenant_id' => ['Client inconnu.']]);
        }
        $recipients = [];
        foreach ($this->accounts->holders((string) $tenantId, AdministratorAccounts::CLIENT_ROLE) as $holder) {
            if ($holder['status'] === 'active') {
                $recipients[] = $holder['person_id'];
            }
        }
        $conversation = $this->desk->openToClient((string) $tenantId, (string) $establishmentId, $recipients, $principal->userId, (string) $subject, (string) $body);
        $this->audit->record((string) $tenantId, $principal->userId, 'platform.support_opened', 'conversation', (string) $conversation['id'], ['establishment_id' => $establishmentId, 'recipients' => count($recipients)], PlatformGuard::ip($request));

        return $this->responder->json(['data' => $this->label($conversation)], 201);
    }

    /** GET /platform/support/agents — membres de la plateforme à qui assigner une conversation (nom seulement). */
    public function agents(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, self::MANAGE);

        return $this->responder->json(['data' => $this->agentList()]);
    }

    /** @return list<array{user_id: string, name: string, role: string}> */
    private function agentList(): array
    {
        $agents = [];
        foreach (self::AGENT_ROLES as $role) {
            try {
                $holders = $this->accounts->holders(PlatformAudit::PLATFORM_TENANT, $role);
            } catch (HttpException) {
                // Rôle pas encore déclaré par Identity (platform_support arrive avec C-112).
                continue;
            }
            foreach ($holders as $holder) {
                if ($holder['status'] === 'active' && !isset($agents[$holder['user_id']])) {
                    $agents[$holder['user_id']] = ['user_id' => $holder['user_id'], 'name' => trim($holder['first_name'] . ' ' . $holder['last_name']), 'role' => $role];
                }
            }
        }

        return array_values($agents);
    }

    /**
     * Ajoute le nom du client et de l'établissement (lus par le contrat d'Organisation, mis en cache pour la requête).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function label(array $row): array
    {
        if ($this->tenantNames === null) {
            $this->tenantNames = [];
            foreach ($this->clients->tenants() as $tenant) {
                $this->tenantNames[$tenant['id']] = $tenant['name'];
            }
        }
        $tenantId = (string) $row['tenant_id'];
        if (!isset($this->establishmentNames[$tenantId])) {
            $this->establishmentNames[$tenantId] = [];
            try {
                foreach ($this->clients->establishmentsOf($tenantId) as $establishment) {
                    $this->establishmentNames[$tenantId][$establishment['id']] = $establishment['name'];
                }
            } catch (HttpException) {
                // Client absent de l'annuaire : la conversation reste lisible, sans nom.
            }
        }

        return $row + [
            'tenant_name' => $this->tenantNames[$tenantId] ?? null,
            'establishment_name' => $this->establishmentNames[$tenantId][(string) $row['establishment_id']] ?? null,
        ];
    }
}

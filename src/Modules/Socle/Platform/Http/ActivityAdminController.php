<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\ExportsList;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Audit\Contracts\ActivityJournal;
use Scholaris\Modules\Socle\Identity\Contracts\AccountActivity;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryMonitor;
use Scholaris\Modules\Socle\Organisation\Contracts\ClientProvisioning;
use Scholaris\Modules\Socle\Platform\Application\ClientLifecycle;
use Scholaris\Modules\Socle\Platform\Application\PlatformAudit;
use Scholaris\Modules\Socle\Platform\Domain\Mask;

/**
 * Suivi des actions (C-112 point 3) : journal d'activité transversal (tous les clients) et son export CSV ; fiche d'un
 * utilisateur vue par la plateforme (connexions, actions, messages reçus, sessions) et déconnexion forcée (2FA récente,
 * journalisée). La réémission d'un code d'accès reste POST /platform/clients/{tenant}/users/{user}/access-code.
 * Téléphones et e-mails toujours masqués.
 */
final class ActivityAdminController
{
    use ExportsList;

    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';
    private const FILTERS = ['tenant_id', 'actor_id', 'action', 'from', 'to'];
    private const EXPORT_MAX = 5000;

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PlatformGuard $guard,
        private readonly ActivityJournal $journal,
        private readonly AccountActivity $accounts,
        private readonly DeliveryMonitor $deliveries,
        private readonly ClientProvisioning $clientDirectory,
        private readonly ClientLifecycle $clients,
        private readonly PlatformAudit $audit,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    /** GET /platform/activity */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.audit.read');
        $query = ListQuery::fromRequest($request, self::FILTERS);
        $filters = $this->filters($query->filters);
        $rows = $this->journal->search($filters, $query->cursor, $query->perPage + 1);
        $next = null;
        if (count($rows) > $query->perPage) {
            $rows = array_slice($rows, 0, $query->perPage);
            $next = $rows[$query->perPage - 1]['cursor'];
        }

        return $this->responder->json(['data' => $this->named($rows), 'meta' => ['next_cursor' => $next]]);
    }

    /** GET /platform/activity/export : fichier CSV (5 000 lignes au plus, les plus récentes). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.audit.export');
        $filters = $this->filters(ListQuery::fromRequest($request, self::FILTERS)->filters);
        $rows = array_map(static fn (array $r): array => [
            $r['occurred_at'], $r['tenant_name'], $r['tenant_id'], $r['actor_id'] ?? '', $r['action'], $r['object_type'], $r['object_id'], $r['ip'] ?? '',
        ], $this->named($this->journal->search($filters, null, self::EXPORT_MAX)));

        return $this->csvResponse($this->responses, $this->streams, 'journal-activite.csv', ['date', 'client', 'identifiant_client', 'acteur', 'action', 'objet', 'identifiant_objet', 'ip'], $rows, $request);
    }

    /** GET /platform/clients/{tenant}/users/{user} : fiche d'un utilisateur vue par la plateforme. */
    public function user(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.users.read');
        $tenantId = (string) $this->clients->show(PlatformGuard::param($request, 'tenant'))['id'];
        $account = $this->accounts->account($tenantId, PlatformGuard::param($request, 'user'));
        $logins = $this->journal->search(['tenant_id' => $tenantId, 'actor_id' => $account['user_id'], 'action' => 'identity.login'], null, 20);
        $actions = $this->journal->search(['tenant_id' => $tenantId, 'actor_id' => $account['user_id']], null, 50);
        $messages = $this->deliveries->journal(['tenant_id' => $tenantId, 'recipient_id' => $account['person_id']], null, 50);

        return $this->responder->json(['data' => [
            'id' => $account['user_id'],
            'person_id' => $account['person_id'],
            'tenant_id' => $tenantId,
            'first_name' => $account['first_name'],
            'last_name' => $account['last_name'],
            'phone' => Mask::phone($account['phone']),
            'email' => Mask::email($account['email']),
            'status' => $account['status'],
            'roles' => $account['roles'],
            'two_factor_enabled' => $account['two_factor_enabled'],
            'last_login_at' => $account['last_login_at'],
            'created_at' => $account['created_at'],
            'sessions' => $account['sessions'],
            'logins' => array_map(static fn (array $r): array => ['occurred_at' => $r['occurred_at'], 'ip' => $r['ip'], 'method' => $r['details']['method'] ?? null], $logins),
            'actions' => array_map(static fn (array $r): array => ['occurred_at' => $r['occurred_at'], 'action' => $r['action'], 'object_type' => $r['object_type'], 'object_id' => $r['object_id']], $actions),
            'messages' => $messages,
        ]]);
    }

    /** POST /platform/clients/{tenant}/users/{user}/logout : déconnexion forcée de toutes les sessions (2FA récente). */
    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.users.logout', true);
        Input::of($request, [])->validate();
        $tenantId = (string) $this->clients->show(PlatformGuard::param($request, 'tenant'))['id'];
        $userId = PlatformGuard::param($request, 'user');
        $count = $this->accounts->revokeSessions($tenantId, $userId, $principal->userId);
        $this->audit->record($tenantId, $principal->userId, 'platform.user_sessions_revoked', 'user', $userId, ['sessions' => $count], PlatformGuard::ip($request));

        return $this->responder->json(['data' => ['revoked_sessions' => $count]]);
    }

    /**
     * @param array<string, string> $raw
     * @return array{tenant_id?: string, actor_id?: string, action?: string, from?: string, to?: string}
     */
    private function filters(array $raw): array
    {
        $out = [];
        foreach (['from', 'to'] as $date) {
            if (isset($raw[$date])) {
                if (preg_match(self::DATE, $raw[$date]) !== 1) {
                    throw HttpException::validation(['filter[' . $date . ']' => ['Date attendue au format AAAA-MM-JJ.']]);
                }
                $out[$date] = $raw[$date];
            }
        }
        foreach (['tenant_id', 'actor_id', 'action'] as $key) {
            if (isset($raw[$key]) && $raw[$key] !== '') {
                $out[$key] = mb_substr($raw[$key], 0, 100);
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function named(array $rows): array
    {
        $names = [PlatformAudit::PLATFORM_TENANT => 'Plateforme SCHOLARIS'];
        foreach ($this->clientDirectory->tenants() as $tenant) {
            $names[$tenant['id']] = $tenant['name'];
        }

        return array_map(static fn (array $r): array => ['id' => $r['tenant_id'] . ':' . $r['seq'], 'tenant_name' => $names[$r['tenant_id']] ?? $r['tenant_id']] + array_diff_key($r, ['cursor' => 0]), $rows);
    }
}

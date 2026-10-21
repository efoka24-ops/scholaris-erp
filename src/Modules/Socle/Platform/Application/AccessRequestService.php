<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use Scholaris\Core\Config\Config;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\RateLimit\RateLimiter;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Identity\Contracts\ClientAdministrators;
use Scholaris\Modules\Socle\Organisation\Contracts\ClientProvisioning;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;
use Scholaris\Modules\Socle\Platform\Domain\Calendar;
use Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb;

/**
 * Demandes d'accès publiques (formulaire sans compte) et leur traitement par l'administrateur de plateforme.
 * Le jeton de suivi n'est remis qu'une fois au demandeur ; seule son empreinte SHA-256 est conservée.
 */
final class AccessRequestService
{
    public const PENDING = 'pending';
    public const INFO_REQUESTED = 'info_requested';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const STATUSES = [self::PENDING, self::INFO_REQUESTED, self::ACCEPTED, self::REJECTED];

    /** Anti-abus : 5 demandes par heure et par adresse IP, 3 par jour et par e-mail ; suivi : 30 consultations par minute et par IP. */
    public const LIMIT_PER_IP = 5;
    public const LIMIT_PER_EMAIL = 3;
    public const TRACK_LIMIT_PER_IP = 30;

    public function __construct(
        private readonly PlatformDb $db,
        private readonly Catalog $catalog,
        private readonly EntitlementService $entitlements,
        private readonly ClientProvisioning $clients,
        private readonly EstablishmentDirectory $establishments,
        private readonly ClientAdministrators $administrators,
        private readonly PlatformNotifier $notifier,
        private readonly PlatformAudit $audit,
        private readonly PlatformClock $clock,
        private readonly RateLimiter $limiter,
        private readonly Config $config,
    ) {
    }

    /** Limitation de débit du formulaire, avant toute validation (un robot ne doit pas pouvoir sonder les règles). */
    public function throttleSubmission(string $ip): void
    {
        $this->hit('platform.access_request.ip|' . $ip, self::LIMIT_PER_IP, 3600);
    }

    public function throttleTracking(string $ip): void
    {
        $this->hit('platform.access_request.track|' . $ip, self::TRACK_LIMIT_PER_IP, 60);
    }

    /**
     * Enregistre une demande validée. Réponse identique que l'e-mail soit déjà connu ou non.
     *
     * @param array<string, string|null> $data champs validés
     * @return array{tracking_token: string, status: string}
     */
    public function submit(array $data, string $ip): array
    {
        $this->hit('platform.access_request.email|' . (string) $data['contact_email'], self::LIMIT_PER_EMAIL, 86400);

        $app = $this->catalog->find((string) $data['app_key']);
        if ($app === null || $app['status'] === 'retired' || $app['listed'] !== true) {
            throw HttpException::validation(['app_key' => ['Application inconnue ou retirée.']]);
        }
        if ($app['target'] !== $data['target_type']) {
            throw HttpException::validation(['app_key' => ['Cette application ne convient pas à ce type d\'établissement.']]);
        }

        $token = bin2hex(random_bytes(24));
        $id = (string) new Ulid();
        $now = $this->clock->iso();
        $this->db->insert('platform_access_requests', [
            'id' => $id,
            'token_hash' => hash('sha256', $token),
            'institution_name' => (string) $data['institution_name'],
            'target_type' => (string) $data['target_type'],
            'city' => (string) $data['city'],
            'region' => (string) $data['region'],
            'country' => (string) ($data['country'] ?? 'CM'),
            'contact_first_name' => (string) $data['contact_first_name'],
            'contact_last_name' => (string) $data['contact_last_name'],
            'contact_email' => (string) $data['contact_email'],
            'contact_phone' => $data['contact_phone'] ?? null,
            'contact_position' => $data['contact_position'] ?? null,
            'ministerial_authorization' => $data['ministerial_authorization'] ?? null,
            'app_key' => (string) $data['app_key'],
            'request_message' => $data['message'] ?? null,
            'locale' => ($data['locale'] ?? 'fr') === 'en' ? 'en' : 'fr',
            'status' => self::PENDING,
            'decision_reason' => null,
            'info_request' => null,
            'complement' => null,
            'decided_by' => null,
            'decided_at' => null,
            'tenant_id' => null,
            'establishment_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->audit->record(null, null, 'platform.access_request_submitted', 'access_request', $id, ['app_key' => $data['app_key']], $ip);

        $this->notifier->send('platform_access_request_received', ($data['locale'] ?? 'fr') === 'en' ? 'en' : 'fr', (string) $data['contact_email'], null, [
            'first_name' => (string) $data['contact_first_name'],
            'label' => (string) $app['label'],
            'establishment_name' => (string) $data['institution_name'],
            'reference' => substr($id, -8),
            'link' => $this->link('/demande-acces/' . $token),
        ]);

        return ['tracking_token' => $token, 'status' => self::PENDING];
    }

    /**
     * Réponse leurre pour le champ piège : même forme qu'une vraie demande, rien n'est enregistré ni envoyé.
     *
     * @return array{tracking_token: string, status: string}
     */
    public function decoy(): array
    {
        return ['tracking_token' => bin2hex(random_bytes(24)), 'status' => self::PENDING];
    }

    /** @return array<string, mixed> vue du demandeur (aucune donnée de contact) */
    public function track(string $token): array
    {
        $row = $this->byToken($token);
        $app = $this->catalog->find((string) $row['app_key']);

        return [
            'reference' => substr((string) $row['id'], -8),
            'institution_name' => (string) $row['institution_name'],
            'app_key' => (string) $row['app_key'],
            'app_label' => $app['label'] ?? (string) $row['app_key'],
            'status' => (string) $row['status'],
            'info_request' => $row['status'] === self::INFO_REQUESTED ? $row['info_request'] : null,
            'decision_reason' => $row['status'] === self::REJECTED ? $row['decision_reason'] : null,
            'submitted_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * Le demandeur répond à une demande de complément : la demande repasse en attente.
     *
     * @return array<string, mixed>
     */
    public function complement(string $token, string $message, string $ip): array
    {
        $row = $this->byToken($token);
        if ($row['status'] !== self::INFO_REQUESTED) {
            throw HttpException::conflict('Aucun complément n\'est attendu pour cette demande.');
        }
        $previous = $row['complement'] === null ? '' : (string) $row['complement'] . "\n---\n";
        $this->db->update('platform_access_requests', [
            'complement' => mb_substr($previous . $message, -8000),
            'status' => self::PENDING,
            'updated_at' => $this->clock->iso(),
        ], ['id' => (string) $row['id']]);
        $this->audit->record(null, null, 'platform.access_request_completed', 'access_request', (string) $row['id'], [], $ip);

        return $this->track($token);
    }

    /**
     * Demandes, la plus récente d'abord (une nouvelle demande est toujours en première page). Les demandes archivées
     * n'apparaissent qu'avec $archived = true.
     *
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    public function page(?string $status, ?string $beforeId, int $perPage, bool $archived = false): array
    {
        $sql = 'SELECT * FROM platform_access_requests WHERE ' . ($archived ? 'archived_at IS NOT NULL' : 'archived_at IS NULL');
        $params = [];
        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        if ($beforeId !== null) {
            $sql .= ' AND id < ?';
            $params[] = $beforeId;
        }
        $rows = $this->db->all($sql . ' ORDER BY id DESC LIMIT ' . ($perPage + 1), $params);
        $next = null;
        if (count($rows) > $perPage) {
            $rows = array_slice($rows, 0, $perPage);
            $next = (string) $rows[$perPage - 1]['id'];
        }

        return [array_map(fn (array $row): array => $this->present($row), $rows), $next];
    }

    /** @return array<string, mixed> */
    public function show(string $id): array
    {
        return $this->present($this->findOrFail($id));
    }

    /**
     * Accepte : crée le client (ou rattache à `tenant_id`), l'établissement du type demandé, ouvre l'application en essai
     * et, pour un nouveau client, invite son premier administrateur (compte à activer par code).
     *
     * @return array<string, mixed>
     */
    public function accept(string $id, ?string $tenantId, string $actorId, ?string $ip, ?string $forceReason = null): array
    {
        $row = $this->findOrFail($id);
        $this->assertOpen($row);
        $appKey = (string) $row['app_key'];
        $app = $this->catalog->findOrFail($appKey);
        // F2 : application à venir ou retirée : refus 422 clair, avant toute création, sauf forçage motivé (force_reason).
        $forced = $forceReason !== null && trim($forceReason) !== '';
        if ($app['status'] !== ApplicationManifest::AVAILABLE && !$forced) {
            throw HttpException::validation(['app_key' => [$app['status'] === ApplicationManifest::COMING_SOON
                ? 'Cette application n\'est pas encore disponible : la demande ne peut pas l\'ouvrir en essai. Pour l\'ouvrir quand même, indiquez un motif (force_reason).'
                : 'Cette application est retirée du catalogue : la demande ne peut pas l\'ouvrir. Pour l\'ouvrir quand même, indiquez un motif (force_reason).']], 'Application non disponible.');
        }

        $newTenant = $tenantId === null;
        if ($tenantId === null) {
            $tenantId = $this->clients->createTenant((string) $row['institution_name']);
        } elseif (!$this->clients->tenantExists($tenantId)) {
            throw HttpException::validation(['tenant_id' => ['Client introuvable.']]);
        }

        $establishmentId = null;
        foreach ($this->establishments->forTenant($tenantId) as $establishment) {
            if ($establishment->type === $row['target_type'] && mb_strtolower($establishment->name) === mb_strtolower((string) $row['institution_name'])) {
                $establishmentId = $establishment->id;
            }
        }
        $establishmentId ??= $this->clients->createEstablishment($tenantId, (string) $row['institution_name'], (string) $row['target_type']);

        $current = $this->entitlements->row($tenantId, $appKey);
        $entitlement = $current === null || $current['state'] === ApplicationAccess::CLOSED
            ? $this->entitlements->open($tenantId, $appKey, EntitlementService::SOURCE_ACCESS_REQUEST, $actorId, $ip, $forced ? $forceReason : null)
            : $this->entitlements->present($current);

        $administrator = null;
        if ($newTenant) {
            $administrator = $this->administrators->invite(
                $tenantId,
                (string) $row['contact_first_name'],
                (string) $row['contact_last_name'],
                $row['contact_phone'] === null ? null : (string) $row['contact_phone'],
                (string) $row['contact_email'],
                (string) $row['locale'],
                $actorId,
            );
        }
        $this->saveContact($tenantId, $row);

        $now = $this->clock->iso();
        $this->db->update('platform_access_requests', [
            'status' => self::ACCEPTED,
            'decided_by' => $actorId,
            'decided_at' => $now,
            'tenant_id' => $tenantId,
            'establishment_id' => $establishmentId,
            'updated_at' => $now,
        ], ['id' => $id]);
        $this->audit->record($tenantId, $actorId, 'platform.access_request_accepted', 'access_request', $id, [
            'app_key' => $appKey, 'establishment_id' => $establishmentId, 'new_tenant' => $newTenant,
            'administrator_person_id' => $administrator['person_id'] ?? null,
        ] + ($forced ? ['forced' => true, 'catalog_status' => $app['status'], 'force_reason' => $forceReason] : []), $ip);

        $this->notifier->send('platform_access_request_accepted', (string) $row['locale'], (string) $row['contact_email'], $row['contact_phone'] === null ? null : (string) $row['contact_phone'], [
            'first_name' => (string) $row['contact_first_name'],
            'label' => (string) $app['label'],
            'establishment_name' => (string) $row['institution_name'],
            'due_on' => Calendar::display((string) $entitlement['trial_ends_on']),
            'link' => $this->link('/activation'),
        ]);

        return $this->show($id) + [
            'entitlement' => $entitlement,
            'administrator' => $administrator === null ? null : ['person_id' => $administrator['person_id'], 'user_id' => $administrator['user_id'], 'login' => $administrator['login'], 'status' => 'pending_activation'],
        ];
    }

    /** @return array<string, mixed> */
    public function reject(string $id, string $reason, string $actorId, ?string $ip): array
    {
        $row = $this->findOrFail($id);
        $this->assertOpen($row);
        $now = $this->clock->iso();
        $this->db->update('platform_access_requests', [
            'status' => self::REJECTED, 'decision_reason' => $reason, 'decided_by' => $actorId, 'decided_at' => $now, 'updated_at' => $now,
        ], ['id' => $id]);
        $this->audit->record(null, $actorId, 'platform.access_request_rejected', 'access_request', $id, ['reason' => $reason], $ip);
        $this->notifier->send('platform_access_request_rejected', (string) $row['locale'], (string) $row['contact_email'], null, [
            'first_name' => (string) $row['contact_first_name'],
            'establishment_name' => (string) $row['institution_name'],
            'details' => $reason,
        ]);

        return $this->show($id);
    }

    /** @return array<string, mixed> */
    public function requestInfo(string $id, string $message, string $actorId, ?string $ip): array
    {
        $row = $this->findOrFail($id);
        $this->assertOpen($row);
        $this->db->update('platform_access_requests', [
            'status' => self::INFO_REQUESTED, 'info_request' => $message, 'updated_at' => $this->clock->iso(),
        ], ['id' => $id]);
        $this->audit->record(null, $actorId, 'platform.access_request_info_requested', 'access_request', $id, [], $ip);
        $this->notifier->send('platform_access_request_info', (string) $row['locale'], (string) $row['contact_email'], null, [
            'first_name' => (string) $row['contact_first_name'],
            'establishment_name' => (string) $row['institution_name'],
            'details' => $message,
            'link' => $this->link('/demande-acces'),
        ]);

        return $this->show($id);
    }

    public function pendingCount(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM platform_access_requests WHERE archived_at IS NULL AND status IN (?, ?)', [self::PENDING, self::INFO_REQUESTED]);
    }

    /**
     * Archive une demande refusée, ou une demande de test non traitée : retirée des files, conservée et tracée.
     * Une demande acceptée (un client existe) ne s'archive pas.
     *
     * @return array<string, mixed>
     */
    public function archive(string $id, string $reason, string $actorId, ?string $ip): array
    {
        $row = $this->findOrFail($id);
        if ($row['archived_at'] !== null) {
            throw HttpException::conflict('Cette demande est déjà archivée.');
        }
        if ($row['status'] === self::ACCEPTED) {
            throw HttpException::conflict('Une demande acceptée ne s\'archive pas : gérez le client correspondant.');
        }
        $now = $this->clock->iso();
        $this->db->update('platform_access_requests', ['archived_at' => $now, 'archived_by' => $actorId, 'archive_reason' => $reason, 'updated_at' => $now], ['id' => $id]);
        $this->audit->record(null, $actorId, 'platform.access_request_archived', 'access_request', $id, ['status' => $row['status'], 'reason' => $reason], $ip);

        return $this->show($id);
    }

    /**
     * Supprime définitivement une demande ARCHIVÉE qui n'a créé aucun client (demande de test, données personnelles du
     * demandeur effacées). La suppression est journalisée avec la référence et le nom de l'institution.
     */
    public function delete(string $id, string $actorId, ?string $ip): void
    {
        $row = $this->findOrFail($id);
        if ($row['archived_at'] === null || $row['tenant_id'] !== null) {
            throw HttpException::conflict('Seule une demande archivée, sans client créé, peut être supprimée.');
        }
        $this->db->pdo()->prepare('DELETE FROM platform_access_requests WHERE id = ? AND archived_at IS NOT NULL AND tenant_id IS NULL')->execute([$id]);
        $this->audit->record(null, $actorId, 'platform.access_request_deleted', 'access_request', $id, [
            'reference' => substr($id, -8), 'institution_name' => (string) $row['institution_name'], 'status' => (string) $row['status'],
            'archive_reason' => $row['archive_reason'],
        ], $ip);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $app = $this->catalog->find((string) $row['app_key']);

        return [
            'id' => (string) $row['id'],
            'reference' => substr((string) $row['id'], -8),
            'status' => (string) $row['status'],
            'institution_name' => (string) $row['institution_name'],
            'target_type' => (string) $row['target_type'],
            'city' => (string) $row['city'],
            'region' => (string) $row['region'],
            'country' => (string) $row['country'],
            'contact' => [
                'first_name' => (string) $row['contact_first_name'],
                'last_name' => (string) $row['contact_last_name'],
                'email' => (string) $row['contact_email'],
                'phone' => $row['contact_phone'],
                'position' => $row['contact_position'],
            ],
            'ministerial_authorization' => $row['ministerial_authorization'],
            'app_key' => (string) $row['app_key'],
            'app_label' => $app['label'] ?? (string) $row['app_key'],
            'message' => $row['request_message'],
            'locale' => (string) $row['locale'],
            'info_request' => $row['info_request'],
            'complement' => $row['complement'],
            'decision_reason' => $row['decision_reason'],
            'decided_by' => $row['decided_by'],
            'decided_at' => $row['decided_at'],
            'tenant_id' => $row['tenant_id'],
            'establishment_id' => $row['establishment_id'],
            'archived_at' => $row['archived_at'] ?? null,
            'archive_reason' => $row['archive_reason'] ?? null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @return array<string, mixed> */
    private function findOrFail(string $id): array
    {
        return $this->db->one('SELECT * FROM platform_access_requests WHERE id = ?', [$id]) ?? throw HttpException::notFound('Demande introuvable.');
    }

    /** @return array<string, mixed> */
    private function byToken(string $token): array
    {
        if (preg_match('/^[a-f0-9]{48}$/', $token) !== 1) {
            throw HttpException::notFound('Demande introuvable.');
        }

        return $this->db->one('SELECT * FROM platform_access_requests WHERE token_hash = ?', [hash('sha256', $token)])
            ?? throw HttpException::notFound('Demande introuvable.');
    }

    /** @param array<string, mixed> $row */
    private function assertOpen(array $row): void
    {
        if (($row['archived_at'] ?? null) !== null || !in_array($row['status'], [self::PENDING, self::INFO_REQUESTED], true)) {
            throw HttpException::conflict('Cette demande a déjà été traitée.');
        }
    }

    /** @param array<string, mixed> $row */
    private function saveContact(string $tenantId, array $row): void
    {
        if ($this->db->one('SELECT tenant_id FROM platform_clients WHERE tenant_id = ?', [$tenantId]) !== null) {
            return;
        }
        $now = $this->clock->iso();
        $this->db->insert('platform_clients', [
            'tenant_id' => $tenantId,
            'contact_name' => trim($row['contact_first_name'] . ' ' . $row['contact_last_name']),
            'contact_email' => (string) $row['contact_email'],
            'contact_phone' => $row['contact_phone'] === null ? null : (string) $row['contact_phone'],
            'locale' => (string) $row['locale'],
            'access_request_id' => (string) $row['id'],
            'city' => (string) $row['city'],
            'region' => (string) $row['region'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function hit(string $key, int $limit, int $window): void
    {
        $retryAfter = $this->limiter->hit($key, $limit, $window);
        if ($retryAfter > 0) {
            throw HttpException::tooManyRequests($retryAfter);
        }
    }

    private function link(string $path): string
    {
        return rtrim((string) $this->config->get('app.url', ''), '/') . $path;
    }
}

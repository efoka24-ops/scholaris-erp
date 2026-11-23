<?php

declare(strict_types=1);

namespace Tests\Support\Audit;

use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Security\PermissionChecker;
use Tests\Support\AppTestCase;

/**
 * Base des tests de conformité : Kernel de test, horloge figée, droits par utilisateur.
 * Clients t1 (alice : gestionnaire ; carl : second validateur ; root : export) et t2 (bob).
 */
abstract class AuditTestCase extends AppTestCase
{
    protected PrivacyClock $clock;
    protected PrivacyPermissions $permissions;

    protected function bootAudit(): void
    {
        $this->clock = new PrivacyClock();
        $this->permissions = new PrivacyPermissions();
        $this->boot([PermissionChecker::class => $this->permissions, Clock::class => $this->clock]);
        $this->permissions->grant('alice', 'privacy.requests.read', 'privacy.requests.manage');
        $this->permissions->grant('carl', 'privacy.requests.read', 'privacy.requests.manage');
        $this->permissions->grant('root', 'privacy.export', 'privacy.requests.read');
        $this->permissions->grant('bob', 'privacy.requests.read', 'privacy.requests.manage', 'privacy.export');
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    protected function api(string $method, string $path, string $user, string $tenant, ?array $body = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $user . ':' . $tenant];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $response = $this->call($method, '/api/v1' . $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
        $raw = (string) $response->getBody();
        $json = str_contains($response->getHeaderLine('Content-Type'), 'json') && $raw !== '' ? (array) json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : [];

        return [$response, $json];
    }

    /** @return array<string, mixed> */
    protected function newRequest(string $kind, string $subject = 'p1', string $user = 'alice', string $tenant = 't1'): array
    {
        [$response, $json] = $this->api('POST', '/data-requests', $user, $tenant, ['kind' => $kind, 'subject_person_id' => $subject, 'details' => 'Demande de test']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }
}

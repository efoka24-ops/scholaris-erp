<?php

declare(strict_types=1);

namespace Tests\Feature\Regularisation;

use Scholaris\Modules\Socle\Platform\Application\SubscriptionBilling;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\Platform\PlatformTestCase;

/**
 * Base des tests de régularisation : deux clients (IPES, bloqué dans les tests ; Institut du Littoral, jamais bloqué), chacun avec
 * l'application Supérieur ouverte, un contact de facturation pour IPES et l'administrateur de plateforme `root`.
 */
abstract class RegularisationCase extends PlatformTestCase
{
    /** @var array{tenant: string, establishment: string} */
    protected array $ipes;
    /** @var array{tenant: string, establishment: string} */
    protected array $other;

    protected function setUp(): void
    {
        $this->bootPlatform();
        $this->ipes = $this->client('IPES de Bonabéri');
        $this->other = $this->client('Institut du Littoral');
        $this->connections()->pdo()->prepare('INSERT INTO platform_clients (tenant_id, contact_name, contact_email, contact_phone, locale, access_request_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NULL, ?, ?)')
            ->execute([$this->ipes['tenant'], 'Direction', 'compta@ipes.test', '+237690000009', 'fr', '2026-10-09T09:00:00+01:00', '2026-10-09T09:00:00+01:00']);
        $this->api('POST', '/applications/higher/open', 'boss', $this->ipes['tenant'], null, $this->ipes['establishment']);
        $this->api('POST', '/applications/higher/open', 'boss', $this->other['tenant'], null, $this->other['establishment']);
    }

    protected function access(): ApplicationAccess
    {
        return $this->kernel->container()->get(ApplicationAccess::class);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>}
     */
    protected function admin(string $method, string $path, array $body = []): array
    {
        return $this->api($method, $path, self::ROOT, null, $body === [] && $method === 'GET' ? null : $body);
    }

    /** Bloque IPES comme le fait le super admin. */
    protected function lock(string $level): void
    {
        [$response] = $this->admin('POST', '/platform/clients/' . $this->ipes['tenant'] . '/regularisation/lock', ['reason' => 'Impayé de test', 'level' => $level]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    /** IPES : échéance dépassée de 16 jours, blocage automatique (lecture seule par défaut) par la commande quotidienne. */
    protected function lateAndSuspended(): void
    {
        $this->clock->day('2027-01-22');
        $this->kernel->container()->get(SubscriptionBilling::class)->run();
        self::assertSame('suspended', $this->entitlement($this->ipes['tenant'])['state']);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>}
     */
    protected function asBoss(string $method, string $path, ?array $body = null, ?string $tenant = null, array $headers = []): array
    {
        $client = $tenant === $this->other['tenant'] ? $this->other : $this->ipes;

        return $this->api($method, $path, 'boss', $client['tenant'], $body, $client['establishment'], $headers);
    }
}

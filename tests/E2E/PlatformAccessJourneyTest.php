<?php

declare(strict_types=1);

namespace Tests\E2E;

use Nyholm\Psr7\ServerRequest;
use Scholaris\Modules\Socle\Identity\Contracts\PlatformAdministrators;
use Tests\E2E\Support\RealStackTestCase;

/**
 * Parcours plateforme sur la pile réelle : demande d'accès publique (sans compte), suivi par jeton, administrateur de
 * plateforme créé par l'outil console réel puis connecté avec enrôlement TOTP réel, acceptation (2FA récente exigée),
 * création du client, de l'établissement et de l'essai de 90 jours, activation du premier administrateur invité (code SMS
 * simulé), lanceur d'applications du client. Cloisonnement plateforme / client vérifié dans les deux sens.
 */
final class PlatformAccessJourneyTest extends RealStackTestCase
{
    protected function setUp(): void
    {
        $this->bootRealStack(['app' => ['url' => 'https://app.scholaris.test']]);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>}
     */
    private function publicCall(string $method, string $path, ?array $body = null, string $ip = '198.51.100.20'): array
    {
        $headers = ['Accept-Language' => 'fr', 'User-Agent' => 'Recette E2E'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        return $this->send(new ServerRequest($method, '/api/v1' . $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR), '1.1', ['REMOTE_ADDR' => $ip]));
    }

    public function testDemandeDAccesPubliqueAcceptationAvec2faEtOuvertureDeLEssai(): void
    {
        // 1. Catalogue public et demande d'accès sans compte.
        [$catalog, $catalogJson] = $this->publicCall('GET', '/public/applications');
        self::assertSame(200, $catalog->getStatusCode());
        self::assertContains('secondary', array_column($catalogJson['data'], 'key'));

        $phone = '+237 690 11 22 33';
        [$submitted, $submittedJson] = $this->publicCall('POST', '/public/access-requests', [
            'institution_name' => 'Collège Bilingue de Recette', 'target_type' => 'secondary', 'city' => 'Douala', 'region' => 'Littoral',
            'contact_first_name' => 'Awa', 'contact_last_name' => 'Ngo Bassa', 'contact_email' => 'direction@college-recette.test',
            'contact_phone' => $phone, 'app_key' => 'secondary', 'establishment_subtype' => 'college_prive', 'message' => 'Rentrée de septembre.',
        ]);
        self::assertSame(202, $submitted->getStatusCode(), (string) $submitted->getBody());
        $tracking = (string) $submittedJson['data']['tracking_token'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{48}$/', $tracking);
        self::assertSame('pending', $this->publicCall('GET', '/public/access-requests/' . $tracking)[1]['data']['status']);

        // 2. Administrateur de plateforme : outil console réel, connexion, 2FA OBLIGATOIRE (enrôlement TOTP par l'API).
        $root = $this->kernel->container()->get(PlatformAdministrators::class)->create('+237699888777', 'Plato', 'Forme');
        $this->clock->advance(max(0, time() - $this->clock->now()) + 1);
        [$login, $loginJson] = $this->api('POST', '/auth/login', null, ['identifier' => $root['login'], 'password' => $root['password']]);
        self::assertSame(200, $login->getStatusCode(), (string) $login->getBody());
        self::assertTrue($loginJson['two_factor_required'] ?? false, 'La 2FA est obligatoire pour l\'administrateur de plateforme.');
        $preToken = (string) $loginJson['token'];
        [$before2fa] = $this->api('GET', '/platform/access-requests', $preToken);
        self::assertContains($before2fa->getStatusCode(), [401, 403], 'Aucun accès plateforme avant la 2FA.');
        $platform = $this->signIn($root['login'], $root['password']);

        $dashboard = $this->ok('GET', '/platform/dashboard', $platform)['data'];
        self::assertNotEmpty($dashboard);
        $pending = $this->ok('GET', '/platform/access-requests?filter[status]=pending', $platform)['data'];
        self::assertCount(1, $pending);
        $requestId = (string) $pending[0]['id'];
        self::assertSame('Collège Bilingue de Recette', $this->ok('GET', '/platform/access-requests/' . $requestId, $platform)['data']['institution_name']);

        // 3. 2FA récente : au-delà de 15 minutes, l'acceptation est refusée jusqu'à une nouvelle vérification.
        $this->clock->advance(16 * 60);
        [$stale, $staleJson] = $this->api('POST', '/platform/access-requests/' . $requestId . '/accept', $platform, []);
        self::assertSame(403, $stale->getStatusCode(), (string) $stale->getBody());
        self::assertSame('https://api.scholaris.cm/errors/two-factor-recent-required', $staleJson['type'] ?? null);
        $this->clock->advance(31);
        $this->ok('POST', '/auth/2fa/verify', $platform, ['code' => $this->totp($root['login'])]);

        // F2 : « secondary » est annoncée (coming_soon) : refus 422 sans motif, ouverture forcée et tracée avec un motif.
        [$refused, $refusedJson] = $this->api('POST', '/platform/access-requests/' . $requestId . '/accept', $platform, []);
        self::assertSame(422, $refused->getStatusCode(), (string) $refused->getBody());
        self::assertArrayHasKey('app_key', $refusedJson['errors'] ?? []);
        $accepted = $this->ok('POST', '/platform/access-requests/' . $requestId . '/accept', $platform, ['force_reason' => 'Établissement pilote de la recette.'])['data'];
        self::assertSame('accepted', $accepted['status']);
        self::assertSame('trial', $accepted['entitlement']['state']);
        self::assertSame(90, $accepted['entitlement']['trial_days_left']);
        self::assertSame('pending_activation', $accepted['administrator']['status']);
        $tenant = (string) $accepted['tenant_id'];
        $establishment = (string) $accepted['establishment_id'];
        self::assertSame('accepted', $this->publicCall('GET', '/public/access-requests/' . $tracking)[1]['data']['status']);
        self::assertSame(409, $this->api('POST', '/platform/access-requests/' . $requestId . '/accept', $platform, [])[0]->getStatusCode());

        $client = $this->ok('GET', '/platform/clients/' . $tenant, $platform)['data'];
        self::assertStringContainsString($tenant, (string) json_encode($client));

        // 4. Le premier administrateur invité active son compte (code SMS simulé), se connecte (2FA) et voit son essai.
        $normalized = '+237690112233';
        $this->activate($normalized, 'Recette-Admin-Invite-2026!');
        $admin = $this->signIn($normalized, 'Recette-Admin-Invite-2026!');
        $contexts = $this->ok('GET', '/me/contexts', $admin);
        self::assertContains($establishment, array_column($contexts['data'], 'establishment_id') ?: array_column($contexts['data'], 'id'), (string) json_encode($contexts));
        $apps = $this->ok('GET', '/me/applications', $admin);
        $opened = array_values(array_filter($apps['data'], static fn (array $a): bool => ($a['key'] ?? $a['app_key'] ?? null) === 'secondary'));
        self::assertCount(1, $opened, (string) json_encode($apps));
        self::assertSame('trial', $opened[0]['state'] ?? null, (string) json_encode($opened[0]));

        // Le client commence à travailler dans son établissement.
        $this->ok('POST', '/academic-years', $admin, ['label' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-07-31'], $establishment, [], 201);

        // 5. Cloisonnement : la plateforme ne lit pas les données métier ; le client n'accède pas à /platform.
        [$platformOnClient] = $this->api('GET', '/people', $platform, null, $establishment);
        self::assertContains($platformOnClient->getStatusCode(), [403, 404], (string) $platformOnClient->getBody());
        [$clientOnPlatform] = $this->api('GET', '/platform/dashboard', $admin);
        self::assertSame(403, $clientOnPlatform->getStatusCode());

        // Audit plateforme.
        $audit = $this->pdo()->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id = ? AND action = ?');
        $audit->execute(['platform', 'platform.access_request_accepted']);
        self::assertSame(1, (int) $audit->fetchColumn());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Scholaris\Modules\Socle\Identity\Application\TokenService;
use Scholaris\Modules\Socle\Identity\Contracts\PlatformAdministrators;
use Scholaris\Modules\Socle\Messaging\Contracts\NotificationChannelPreferences;
use Tests\Support\Platform\PlatformTestCase;

/** C-112 point 6 : « Mon profil », réglages personnels, réglages de sécurité de la plateforme, résumé quotidien. */
final class PlatformProfileTest extends PlatformTestCase
{
    private string $me;

    protected function setUp(): void
    {
        $this->bootPlatform();
        $this->me = $this->kernel->container()->get(PlatformAdministrators::class)->create('moi@scholaris.test', 'Emmanuel', 'Foka')['user_id'];
        $this->permissions->grant(self::ROOT, 'platform', 'platform.settings.read');
    }

    public function testProfilModifiableEtReglagesPersonnels(): void
    {
        [$response, $profile] = $this->api('GET', '/me/profile', $this->me);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('Emmanuel', $profile['data']['first_name']);
        self::assertTrue($profile['data']['platform_member']);
        self::assertSame(['app' => true, 'sms' => false, 'email' => false], $profile['data']['preferences']['channels']);

        [$updated, $json] = $this->api('PATCH', '/me/profile', $this->me, null, ['last_name' => 'Foka Tchoua', 'phone' => '690 00 12 34', 'locale' => 'en']);
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getBody());
        self::assertSame('Foka Tchoua', $json['data']['last_name']);
        self::assertSame('+237690001234', $json['data']['phone']);
        self::assertSame('en', $json['data']['locale']);
        self::assertSame(422, $this->api('PATCH', '/me/profile', $this->me, null, ['phone' => '12'])[0]->getStatusCode());
        self::assertSame(422, $this->api('PATCH', '/me/profile', $this->me, null, ['phone' => null, 'email' => null])[0]->getStatusCode(), 'un identifiant reste obligatoire');

        $other = $this->kernel->container()->get(PlatformAdministrators::class)->create('autre@scholaris.test', 'Awa', 'Ngo')['user_id'];
        self::assertSame(409, $this->api('PATCH', '/me/profile', $other, null, ['email' => 'moi@scholaris.test'])[0]->getStatusCode());

        [$prefs, $json] = $this->api('PATCH', '/me/preferences', $this->me, null, ['notify_email' => true, 'daily_digest' => true]);
        self::assertSame(200, $prefs->getStatusCode(), (string) $prefs->getBody());
        self::assertTrue($json['data']['preferences']['channels']['email']);
        self::assertTrue($json['data']['preferences']['daily_digest']);
        self::assertSame(422, $this->api('PATCH', '/me/preferences', $this->me, null, ['notify_sms' => 'oui'])[0]->getStatusCode());

        // Raccord C-113 : la messagerie lit les canaux choisis.
        $person = (string) $this->connections()->pdo()->query("SELECT person_id FROM users WHERE email = 'moi@scholaris.test'")->fetchColumn();
        self::assertSame(['email'], $this->kernel->container()->get(NotificationChannelPreferences::class)->extraChannelsFor('platform', $person));

        // Résumé quotidien : un e-mail à l'abonné.
        $command = $this->kernel->container()->get('console.command.platform:daily-digest');
        ob_start();
        self::assertSame(0, $command->run([]));
        ob_end_clean();
        $digest = $this->notifier->of('platform_daily_digest');
        self::assertCount(1, $digest);
        self::assertSame('moi@scholaris.test', $digest[0]['email']);
    }

    public function testDeconnecterLesAutresAppareils(): void
    {
        $tokens = $this->kernel->container()->get(TokenService::class);
        $tokens->issue('platform', $this->me, 'D1', false, false);
        $tokens->issue('platform', $this->me, 'D2', false, false);
        [, $profile] = $this->api('GET', '/me/profile', $this->me);
        self::assertCount(2, $profile['data']['sessions']);
        [$response, $json] = $this->api('POST', '/me/sessions/logout-others', $this->me, null, []);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(2, $json['data']['revoked_sessions'], 'doublure d\'authentification : aucune session courante');
        self::assertSame([], $this->api('GET', '/me/profile', $this->me)[1]['data']['sessions']);
    }

    public function testReglagesDeSecuriteParRoleSuperAdminSeul(): void
    {
        [, $security] = $this->api('GET', '/platform/settings/security', self::ROOT);
        self::assertSame(8, $security['data']['session_hours']['value']);
        $roles = array_column($security['data']['roles'], null, 'code');
        self::assertTrue($roles['platform_support']['locked']);
        self::assertTrue($roles['enseignant']['two_factor_required']);

        [$changed, $json] = $this->api('PATCH', '/platform/settings/security', self::ROOT, null, ['session_hours' => 4, 'two_factor' => ['enseignant' => false]]);
        self::assertSame(200, $changed->getStatusCode(), (string) $changed->getBody());
        self::assertSame(4, $json['data']['session_hours']['value']);
        self::assertFalse(array_column($json['data']['roles'], null, 'code')['enseignant']['two_factor_required']);
        self::assertSame(409, $this->api('PATCH', '/platform/settings/security', self::ROOT, null, ['two_factor' => ['platform_support' => false]])[0]->getStatusCode());
        self::assertSame(422, $this->api('PATCH', '/platform/settings/security', self::ROOT, null, ['session_hours' => 500])[0]->getStatusCode());

        // La durée réglée s'applique aux nouvelles sessions.
        $issued = $this->kernel->container()->get(TokenService::class)->issue('platform', $this->me, 'D1', false, false);
        self::assertSame(4 * 3600, strtotime($issued['expires_at']) - $this->clock->now());

        $this->permissions->grant('support', 'platform', 'platform.access', 'platform.dashboard.read');
        self::assertSame(403, $this->api('GET', '/platform/settings/security', 'support')[0]->getStatusCode());
        $this->permissions->grant('auditor', 'platform', 'platform.access', 'platform.settings.read');
        $this->secondFactor->allow('auditor');
        self::assertSame(200, $this->api('GET', '/platform/settings/security', 'auditor')[0]->getStatusCode());
        self::assertSame(403, $this->api('PATCH', '/platform/settings/security', 'auditor', null, ['session_hours' => 6])[0]->getStatusCode());
    }

    public function testUnUtilisateurDUnClientAAussiSonProfil(): void
    {
        $tenant = $this->client('Institut du Littoral')['tenant'];
        [$invited, $json] = $this->api('POST', '/platform/clients/' . $tenant . '/administrators', self::ROOT, null, ['first_name' => 'Paul', 'last_name' => 'Mballa', 'email' => 'paul@client.test']);
        self::assertSame(201, $invited->getStatusCode());
        [$response, $profile] = $this->api('GET', '/me/profile', (string) $json['data']['id'], $tenant);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertFalse($profile['data']['platform_member']);
        self::assertSame(422, $this->api('PATCH', '/me/preferences', (string) $json['data']['id'], $tenant, ['daily_digest' => true])[0]->getStatusCode());
    }
}

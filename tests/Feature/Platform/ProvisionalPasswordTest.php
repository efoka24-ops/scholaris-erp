<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Psr\Http\Message\ResponseInterface;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Tests\Support\Identity\RecordingMail;
use Tests\Support\Platform\FlakySms;
use Tests\Support\Platform\PlatformTestCase;

/**
 * Mot de passe provisoire donné depuis le back-office (MDP-PROV) : rendu une fois, utilisable aussitôt, l'ancien refusé,
 * changement exigé au choix, envoi e-mail ou SMS, jamais journalisé ni enregistré, 2FA récente, droits, compte bloqué, fréquence.
 */
final class ProvisionalPasswordTest extends PlatformTestCase
{
    private const OLD_PASSWORD = 'Baobab-Garoua-2026!';

    private RecordingMail $mail;
    private FlakySms $sms;

    protected function setUp(): void
    {
        $this->bootPlatform();
        $this->mail = new RecordingMail();
        $this->sms = new FlakySms();
        $this->kernel->container()->set(MailGateway::class, $this->mail);
        $this->kernel->container()->set(SmsGateway::class, $this->sms);
    }

    /** Client fictif avec un administrateur activé (mot de passe choisi). @return array{tenant: string, user: string} */
    private function activeAdministrator(): array
    {
        [$created, $client] = $this->api('POST', '/platform/clients', self::ROOT, null, ['name' => 'Institut Polytechnique du Nord', 'contact_name' => 'Awa Ngo', 'contact_email' => 'direction@ipn.test']);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $tenant = (string) $client['data']['id'];
        [$invited, $admin] = $this->api('POST', '/platform/clients/' . $tenant . '/administrators', self::ROOT, null, ['first_name' => 'Paul', 'last_name' => 'Biya', 'email' => 'paul@ipn.test', 'phone' => '+237 690 12 34 56']);
        self::assertSame(201, $invited->getStatusCode(), (string) $invited->getBody());
        $user = (string) $admin['data']['id'];

        [, $code] = $this->api('POST', '/platform/clients/' . $tenant . '/users/' . $user . '/access-code', self::ROOT, null, ['send' => 'none']);
        [, $ticket] = $this->raw('POST', '/auth/activation/verify', ['Content-Type' => 'application/json'], json_encode(['identifier' => 'paul@ipn.test', 'code' => (string) $code['data']['code']], JSON_THROW_ON_ERROR));
        [$set] = $this->raw('POST', '/auth/activation/password', ['Content-Type' => 'application/json'], json_encode(['ticket' => $ticket['ticket'], 'password' => self::OLD_PASSWORD], JSON_THROW_ON_ERROR));
        self::assertSame(200, $set->getStatusCode(), (string) $set->getBody());

        return ['tenant' => $tenant, 'user' => $user];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    private function give(string $tenant, string $user, array $body = [], string $actor = self::ROOT): array
    {
        return $this->api('POST', '/platform/clients/' . $tenant . '/users/' . $user . '/provisional-password', $actor, null, $body);
    }

    /** @return array{0: ResponseInterface, 1: array<string, mixed>} */
    private function login(string $identifier, string $password): array
    {
        return $this->raw('POST', '/auth/login', ['Content-Type' => 'application/json'], json_encode(['identifier' => $identifier, 'password' => $password], JSON_THROW_ON_ERROR));
    }

    /** Tout ce qui est enregistré en base sur les messages et le journal. */
    private function stored(): string
    {
        $out = '';
        foreach (['audit_log', 'messages', 'message_deliveries', 'one_time_codes', 'platform_settings'] as $table) {
            $out .= json_encode($this->connections()->pdo()->query('SELECT * FROM ' . $table)->fetchAll(\PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        }

        return $out;
    }

    public function testMotDePasseRenduUneFoisUtilisableAussitotEtAncienRefuse(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->activeAdministrator();
        [$session] = $this->login('paul@ipn.test', self::OLD_PASSWORD);
        self::assertSame(200, $session->getStatusCode(), (string) $session->getBody());

        $mailsBefore = count($this->mail->sent);
        [$response, $json] = $this->give($tenant, $user);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $password = (string) $json['data']['password'];
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Za-km-z2-9]{10,12}$/', $password, 'lisible, sans 0/O ni 1/l/I');
        self::assertFalse($json['data']['must_change_password'], 'réglage par défaut : pas de changement exigé');
        self::assertSame('not_sent', $json['data']['delivery']['status']);
        $share = $json['data']['share'];
        self::assertSame('Institut Polytechnique du Nord', $share['establishment_name']);
        self::assertSame('paul@ipn.test', $share['login']);
        self::assertSame('https://app.scholaris.test/connexion', $share['login_url']);
        foreach (['Institut Polytechnique du Nord', 'paul@ipn.test', $password, 'https://app.scholaris.test/connexion'] as $expected) {
            self::assertStringContainsString($expected, $share['message']['text']);
        }
        self::assertStringNotContainsString($password, $share['message']['subject']);
        self::assertCount($mailsBefore, $this->mail->sent, 'afficher seulement : rien n\'est envoyé');

        $revoked = $this->connections()->pdo()->query("SELECT COUNT(*) FROM access_tokens WHERE user_id = '" . $user . "' AND revoked_at IS NULL")->fetchColumn();
        self::assertSame(0, (int) $revoked, 'les autres sessions sont déconnectées');

        [$old] = $this->login('paul@ipn.test', self::OLD_PASSWORD);
        self::assertSame(401, $old->getStatusCode(), 'l\'ancien mot de passe est refusé');
        [$new, $logged] = $this->login('paul@ipn.test', $password);
        self::assertSame(200, $new->getStatusCode(), (string) $new->getBody());
        self::assertFalse($logged['password_change_required']);

        // Jamais relu : ni dans la fiche, ni dans le journal, ni dans les messages enregistrés.
        $this->permissions->grant(self::ROOT, 'platform', 'platform.users.read');
        [, $card] = $this->api('GET', '/platform/clients/' . $tenant . '/users/' . $user, self::ROOT);
        self::assertStringNotContainsString($password, (string) json_encode($card));
        self::assertFalse($card['data']['provisional_password']['require_change_default']);
        self::assertSame(['email', 'sms'], $card['data']['provisional_password']['channels']);
        [$second, $again] = $this->give($tenant, $user);
        self::assertSame(201, $second->getStatusCode());
        self::assertStringNotContainsString($password, (string) json_encode($again), 'un second appel génère un autre mot de passe');
        self::assertStringNotContainsString($password, $this->stored());
        $actions = array_column($this->connections()->pdo()->query("SELECT action FROM audit_log WHERE action LIKE '%provisional%'")->fetchAll(\PDO::FETCH_ASSOC), 'action');
        self::assertContains('platform.provisional_password_issued', $actions);
        self::assertContains('identity.provisional_password_issued', $actions);
        $log = dirname(__DIR__, 3) . '/storage/logs/test.log';
        if (is_file($log)) {
            self::assertStringNotContainsString($password, (string) file_get_contents($log));
        }
    }

    public function testChangementExigeAuChoixEtParDefautSelonLeReglage(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->activeAdministrator();
        [, $json] = $this->give($tenant, $user, ['require_change' => true]);
        self::assertTrue($json['data']['must_change_password']);
        self::assertStringContainsString('Un nouveau mot de passe vous sera demandé', $json['data']['share']['message']['text']);
        [$login, $logged] = $this->login('paul@ipn.test', (string) $json['data']['password']);
        self::assertSame(200, $login->getStatusCode(), (string) $login->getBody());
        self::assertTrue($logged['password_change_required']);

        [$bad] = $this->give($tenant, $user, ['require_change' => 'oui']);
        self::assertSame(422, $bad->getStatusCode());

        [$read, $settings] = $this->api('GET', '/platform/settings', self::ROOT);
        self::assertSame(200, $read->getStatusCode());
        self::assertSame(0, $settings['data']['platform.provisional_password.require_change']['value']);
        [$saved] = $this->api('PATCH', '/platform/settings', self::ROOT, null, ['platform.provisional_password.require_change' => 1]);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        [, $byDefault] = $this->give($tenant, $user);
        self::assertTrue($byDefault['data']['must_change_password'], 'sans case transmise : le réglage de la plateforme');
        [, $unchecked] = $this->give($tenant, $user, ['require_change' => false]);
        self::assertFalse($unchecked['data']['must_change_password']);
        [, $none] = $this->login('paul@ipn.test', (string) $unchecked['data']['password']);
        self::assertFalse($none['password_change_required']);
    }

    public function testDoubleAuthentificationConserveeEtSignaleeDansLeMessage(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->activeAdministrator();
        $this->connections()->pdo()->prepare("UPDATE users SET two_factor_secret = 'secret-fictif', two_factor_confirmed_at = '2026-11-01T10:00:00+01:00' WHERE id = ?")->execute([$user]);

        [, $json] = $this->give($tenant, $user);
        self::assertTrue($json['data']['two_factor_enabled']);
        self::assertStringContainsString('double authentification reste demandé', $json['data']['share']['message']['text']);
        $kept = $this->connections()->pdo()->query("SELECT two_factor_confirmed_at FROM users WHERE id = '" . $user . "'")->fetchColumn();
        self::assertNotNull($kept);
        [, $logged] = $this->login('paul@ipn.test', (string) $json['data']['password']);
        self::assertTrue($logged['two_factor_required'] ?? false, (string) json_encode($logged));
    }

    public function testEnvoiParEmailEtParSmsSansEnregistrerLeMotDePasse(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->activeAdministrator();
        [$byMail, $mail] = $this->give($tenant, $user, ['send' => 'email']);
        self::assertSame(201, $byMail->getStatusCode(), (string) $byMail->getBody());
        self::assertSame('sent', $mail['data']['delivery']['status']);
        self::assertSame('p•••@ipn.test', $mail['data']['delivery']['masked_to']);
        $last = end($this->mail->sent);
        self::assertIsArray($last);
        self::assertSame('paul@ipn.test', $last['to']);
        self::assertStringContainsString((string) $mail['data']['password'], $last['body']);

        [$bySms, $sms] = $this->give($tenant, $user, ['send' => 'sms']);
        self::assertSame(201, $bySms->getStatusCode(), (string) $bySms->getBody());
        self::assertSame('sent', $sms['data']['delivery']['status']);
        $text = end($this->sms->sent)['message'] ?? '';
        self::assertStringContainsString((string) $sms['data']['password'], $text);
        self::assertLessThanOrEqual(300, mb_strlen($text), 'SMS court');

        $this->sms->failing = true;
        [$failed, $json] = $this->give($tenant, $user, ['send' => 'sms']);
        self::assertSame(201, $failed->getStatusCode(), 'le mot de passe reste affiché si l\'envoi échoue');
        self::assertSame('failed', $json['data']['delivery']['status']);

        $stored = $this->stored();
        foreach ([$mail, $sms, $json] as $issued) {
            self::assertStringNotContainsString((string) $issued['data']['password'], $stored);
        }
        $entries = $this->connections()->pdo()->query("SELECT after_json FROM audit_log WHERE action = 'platform.provisional_password_issued' AND tenant_id = '" . \Scholaris\Modules\Socle\Platform\Application\PlatformAudit::PLATFORM_TENANT . "' ORDER BY seq")->fetchAll(\PDO::FETCH_COLUMN);
        $details = array_map(static fn ($row): array => (array) json_decode((string) $row, true), $entries);
        self::assertSame(['email', 'sms', 'sms'], array_column($details, 'send'));
        self::assertSame(['sent', 'sent', 'failed'], array_column($details, 'delivery'));
        self::assertSame([false, false, false], array_column($details, 'require_change'));
    }

    public function testDoubleAuthentificationRecenteEtDroitsExiges(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->activeAdministrator();
        $this->secondFactor->revoke(self::ROOT);
        [$stale, $json] = $this->give($tenant, $user);
        self::assertSame(403, $stale->getStatusCode());
        self::assertStringContainsString('two-factor-recent-required', (string) $json['type']);
        $this->secondFactor->allow(self::ROOT);

        $this->permissions->grant('lecteur', 'platform', 'platform.access', 'platform.users.read');
        $this->secondFactor->allow('lecteur');
        [$reader] = $this->give($tenant, $user, [], 'lecteur');
        self::assertSame(403, $reader->getStatusCode(), 'la lecture des utilisateurs ne suffit pas');
        [$asClient] = $this->api('POST', '/platform/clients/' . $tenant . '/users/' . $user . '/provisional-password', 'boss', $tenant, []);
        self::assertSame(403, $asClient->getStatusCode());

        [$old] = $this->login('paul@ipn.test', self::OLD_PASSWORD);
        self::assertSame(200, $old->getStatusCode(), 'aucun refus n\'a changé le mot de passe');
    }

    public function testCompteSuspenduRefuseEtPropreCompteRefuse(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->activeAdministrator();
        [$blocked] = $this->api('POST', '/platform/clients/' . $tenant . '/administrators/' . $user . '/block', self::ROOT, null, []);
        self::assertSame(200, $blocked->getStatusCode(), (string) $blocked->getBody());
        [$refused, $json] = $this->give($tenant, $user);
        self::assertSame(409, $refused->getStatusCode());
        self::assertStringContainsString('suspendu', (string) ($json['detail'] ?? ''));

        [$self] = $this->api('POST', '/platform/administrators/' . self::ROOT . '/provisional-password', self::ROOT, null, []);
        self::assertSame(409, $self->getStatusCode(), 'jamais pour son propre compte');
        [$foreign] = $this->api('POST', '/platform/administrators/' . $user . '/provisional-password', self::ROOT, null, []);
        self::assertSame(404, $foreign->getStatusCode(), 'un compte de client n\'est pas un administrateur de plateforme');
    }

    public function testAdministrateurDePlateformeEtLimiteDeFrequence(): void
    {
        [$invited, $admin] = $this->api('POST', '/platform/administrators', self::ROOT, null, ['first_name' => 'Nadia', 'last_name' => 'Fotso', 'email' => 'nadia@plateforme.test']);
        self::assertSame(201, $invited->getStatusCode(), (string) $invited->getBody());
        $id = (string) $admin['data']['id'];
        $path = '/platform/administrators/' . $id . '/provisional-password';

        [$first, $json] = $this->api('POST', $path, self::ROOT, null, ['require_change' => true]);
        self::assertSame(201, $first->getStatusCode(), (string) $first->getBody());
        self::assertSame('Plateforme SCHOLARIS', $json['data']['share']['establishment_name']);
        [$login] = $this->login('nadia@plateforme.test', (string) $json['data']['password']);
        self::assertSame(200, $login->getStatusCode(), 'compte en attente : utilisable aussitôt');

        for ($i = 2; $i <= 5; $i++) {
            [$ok] = $this->api('POST', $path, self::ROOT, null, []);
            self::assertSame(201, $ok->getStatusCode());
        }
        [$sixth] = $this->api('POST', $path, self::ROOT, null, []);
        self::assertSame(429, $sixth->getStatusCode());
        self::assertNotSame('', $sixth->getHeaderLine('Retry-After'));
    }
}

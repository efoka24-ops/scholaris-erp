<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Tests\Support\Identity\RecordingMail;
use Tests\Support\Platform\FlakySms;
use Tests\Support\Platform\PlatformTestCase;

/** Code d'accès créé depuis le back-office pour un utilisateur précis : affiché une fois, copiable, envoyé à la demande. */
final class AccessCodeTest extends PlatformTestCase
{
    private RecordingMail $mail;
    private FlakySms $sms;

    protected function setUp(): void
    {
        $this->bootPlatform();
        $this->mail = new RecordingMail();
        $this->sms = new FlakySms();
        $this->kernel->container()->set(\Scholaris\Modules\Socle\Integrations\Contracts\MailGateway::class, $this->mail);
        $this->kernel->container()->set(SmsGateway::class, $this->sms);
    }

    /** @return array{tenant: string, user: string} */
    private function clientWithAdministrator(string $name = 'Institut Polytechnique du Nord', string $email = 'paul@ipn.test', string $phone = '+237 690 12 34 56'): array
    {
        [$created, $client] = $this->api('POST', '/platform/clients', self::ROOT, null, ['name' => $name, 'contact_name' => 'Awa Ngo', 'contact_email' => 'direction@' . md5($name) . '.test']);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $tenant = (string) $client['data']['id'];
        [$invited, $admin] = $this->api('POST', '/platform/clients/' . $tenant . '/administrators', self::ROOT, null, ['first_name' => 'Paul', 'last_name' => 'Biya', 'email' => $email, 'phone' => $phone]);
        self::assertSame(201, $invited->getStatusCode(), (string) $invited->getBody());

        return ['tenant' => $tenant, 'user' => (string) $admin['data']['id']];
    }

    /** @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>} */
    private function issue(string $tenant, string $user, string $send = 'none'): array
    {
        return $this->api('POST', '/platform/clients/' . $tenant . '/users/' . $user . '/access-code', self::ROOT, null, ['send' => $send]);
    }

    /** @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>} */
    private function verify(string $identifier, string $code): array
    {
        return $this->raw('POST', '/auth/activation/verify', ['Content-Type' => 'application/json'], json_encode(['identifier' => $identifier, 'code' => $code], JSON_THROW_ON_ERROR));
    }

    public function testLeCodeAfficheFonctionneSurLeParcoursDActivationPuisDeReinitialisation(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->clientWithAdministrator();

        [$list, $users] = $this->api('GET', '/platform/clients/' . $tenant . '/users', self::ROOT);
        self::assertSame(200, $list->getStatusCode(), (string) $list->getBody());
        self::assertSame([$user], array_column($users['data'], 'id'));
        self::assertSame(['super_admin'], $users['data'][0]['roles']);

        [$response, $json] = $this->issue($tenant, $user);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $code = (string) $json['data']['code'];
        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        self::assertSame('activation', $json['data']['purpose']);
        self::assertSame('not_sent', $json['data']['delivery']['status']);
        self::assertSame(86400, $json['data']['expires_in']);
        self::assertSame([], $this->mail->sent, 'afficher seulement : rien n\'est envoyé');
        $share = $json['data']['share'];
        self::assertSame('Institut Polytechnique du Nord', $share['establishment_name']);
        self::assertSame('paul@ipn.test', $share['login']);
        self::assertSame('https://app.scholaris.test/connexion', $share['login_url']);
        self::assertSame('https://app.scholaris.test/activation', $share['activation_url']);
        self::assertSame(['email', 'sms'], $share['channels']);
        self::assertStringContainsString($code, $share['message']['text']);
        self::assertStringContainsString('https://app.scholaris.test/activation', $share['message']['text']);
        self::assertLessThanOrEqual(480, mb_strlen($share['message']['sms']));

        [$verified, $ticket] = $this->verify('paul@ipn.test', $code);
        self::assertSame(200, $verified->getStatusCode(), (string) $verified->getBody());
        [$password] = $this->raw('POST', '/auth/activation/password', ['Content-Type' => 'application/json'], json_encode(['ticket' => $ticket['ticket'], 'password' => 'Baobab-Garoua-2026!'], JSON_THROW_ON_ERROR));
        self::assertSame(200, $password->getStatusCode(), (string) $password->getBody());

        [, $reset] = $this->issue($tenant, $user, 'email');
        self::assertSame('reset', $reset['data']['purpose'], 'compte actif : code de réinitialisation');
        self::assertSame('sent', $reset['data']['delivery']['status']);
        self::assertSame((string) $reset['data']['code'], $this->mailedCode('paul@ipn.test'));
        [$byPhone] = $this->verify('+237690123456', (string) $reset['data']['code']);
        self::assertSame(200, $byPhone->getStatusCode(), (string) $byPhone->getBody());
    }

    /**
     * @param list<string> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $statement = $this->connections()->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function mailedCode(string $to): ?string
    {
        foreach (array_reverse($this->mail->sent) as $mail) {
            if ($mail['to'] === $to && preg_match('/ : (\d{6}) \(/', $mail['body'], $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    public function testAcceptationDUneDemandeRecapitulatifCodeUtilisableEtEnvoiAutomatique(): void
    {
        $request = $this->submitRequest();
        [$accepted, $json] = $this->api('POST', '/platform/access-requests/' . $request['id'] . '/accept', self::ROOT, null, ['share_send' => 'email']);
        self::assertSame(200, $accepted->getStatusCode(), (string) $accepted->getBody());
        self::assertSame('no-store', $accepted->getHeaderLine('Cache-Control'));
        $access = $json['data']['access'];
        self::assertSame('activation', $access['purpose']);
        self::assertSame('Institut Supérieur du Littoral', $access['share']['establishment_name']);
        self::assertSame('direction@isl.test', $access['share']['login']);
        self::assertSame('sent', $access['delivery']['status']);
        $code = (string) $access['code'];
        self::assertSame($code, $this->mailedCode('direction@isl.test'));

        $tenant = (string) $json['data']['tenant_id'];
        $user = (string) $json['data']['administrator']['user_id'];
        [$bySms, $sent] = $this->api('POST', '/platform/clients/' . $tenant . '/users/' . $user . '/access-code/send', self::ROOT, null, ['channel' => 'sms', 'code' => $code]);
        self::assertSame(200, $bySms->getStatusCode(), (string) $bySms->getBody());
        self::assertSame('sent', $sent['data']['delivery']['status']);
        self::assertSame($code, $this->sms->lastCode('+237690000001'), 'le même code, sans régénération');
        [$wrong] = $this->api('POST', '/platform/clients/' . $tenant . '/users/' . $user . '/access-code/send', self::ROOT, null, ['channel' => 'sms', 'code' => $code === '000000' ? '111111' : '000000']);
        self::assertSame(422, $wrong->getStatusCode());

        [$verified] = $this->verify('direction@isl.test', $code);
        self::assertSame(200, $verified->getStatusCode(), (string) $verified->getBody());
        $logged = (string) json_encode($this->rows('SELECT after_json FROM audit_log'));
        self::assertStringNotContainsString('"' . $code . '"', $logged);
    }

    public function testLeCodeNEstJamaisStockeNiJournaliseEnClair(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->clientWithAdministrator();
        [, $json] = $this->issue($tenant, $user);
        $code = (string) $json['data']['code'];

        $row = $this->rows('SELECT * FROM one_time_codes WHERE user_id = ? AND consumed_at IS NULL', [$user])[0] ?? null;
        self::assertIsArray($row);
        self::assertNotSame($code, $row['code_hash']);
        self::assertSame('platform', $row['channel']);

        $entries = $this->rows("SELECT tenant_id, action, before_json, after_json FROM audit_log WHERE action LIKE '%access_code%'");
        $actions = array_column($entries, 'action');
        self::assertContains('identity.access_code_issued', $actions);
        self::assertContains('platform.access_code_issued', $actions);
        foreach ($entries as $entry) {
            self::assertStringNotContainsString($code, (string) $entry['after_json'] . (string) $entry['before_json']);
        }
    }

    public function testRefusSansDoubleAuthentificationRecenteEtPourSonPropreCompte(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->clientWithAdministrator();
        $this->secondFactor->revoke(self::ROOT);
        [$stale, $json] = $this->issue($tenant, $user);
        self::assertSame(403, $stale->getStatusCode());
        self::assertStringContainsString('two-factor-recent-required', (string) $json['type']);
        $this->secondFactor->allow(self::ROOT);

        [$self] = $this->api('POST', '/platform/administrators/' . self::ROOT . '/access-code', self::ROOT, null, []);
        self::assertSame(409, $self->getStatusCode(), 'jamais pour son propre compte');

        [$asClient] = $this->api('POST', '/platform/clients/' . $tenant . '/users/' . $user . '/access-code', 'boss', $tenant, []);
        self::assertSame(403, $asClient->getStatusCode());
    }

    public function testDureeReglableParLeSuperAdminEtCodeAccepteAuDelaDeCinqMinutes(): void
    {
        [$read, $settings] = $this->api('GET', '/platform/settings', self::ROOT);
        self::assertSame(200, $read->getStatusCode(), (string) $read->getBody());
        self::assertSame(1440, $settings['data']['access_code_ttl_minutes']['value'], '24 heures par défaut.');

        $this->secondFactor->revoke(self::ROOT);
        [$stale] = $this->api('PATCH', '/platform/settings', self::ROOT, null, ['access_code_ttl_minutes' => 60]);
        self::assertSame(403, $stale->getStatusCode(), '2FA récente exigée.');
        $this->secondFactor->allow(self::ROOT);
        [$bad] = $this->api('PATCH', '/platform/settings', self::ROOT, null, ['access_code_ttl_minutes' => 2]);
        self::assertSame(422, $bad->getStatusCode(), '5 minutes au moins.');
        [$ok, $updated] = $this->api('PATCH', '/platform/settings', self::ROOT, null, ['access_code_ttl_minutes' => 60]);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertSame(60, $updated['data']['access_code_ttl_minutes']['value']);

        ['tenant' => $tenant, 'user' => $user] = $this->clientWithAdministrator();
        [$response, $json] = $this->issue($tenant, $user);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(3600, $json['data']['expires_in']);

        // 30 minutes plus tard : la fenêtre de 5 minutes des codes demandés en libre-service ne s'applique pas.
        $this->clock->set(date('c', $this->clock->now() + 1800));
        [$verified] = $this->verify('paul@ipn.test', (string) $json['data']['code']);
        self::assertSame(200, $verified->getStatusCode(), (string) $verified->getBody());
    }

    public function testCodeExpireApresLaDureeReglee(): void
    {
        $this->api('PATCH', '/platform/settings', self::ROOT, null, ['access_code_ttl_minutes' => 10]);
        ['tenant' => $tenant, 'user' => $user] = $this->clientWithAdministrator();
        [, $json] = $this->issue($tenant, $user);
        $this->clock->set(date('c', $this->clock->now() + 11 * 60));
        [$expired] = $this->verify('paul@ipn.test', (string) $json['data']['code']);
        self::assertContains($expired->getStatusCode(), [410, 422, 400], (string) $expired->getBody());
    }

    public function testCloisonnementEntreClients(): void
    {
        ['user' => $user] = $this->clientWithAdministrator();
        ['tenant' => $other] = $this->clientWithAdministrator('Collège Voisin', 'awa@voisin.test', '+237 690 99 88 77');

        [$foreign] = $this->issue($other, $user);
        self::assertSame(404, $foreign->getStatusCode(), 'un utilisateur d\'un autre client est introuvable');
        [$asPlatform] = $this->api('POST', '/platform/administrators/' . $user . '/access-code', self::ROOT, null, []);
        self::assertSame(404, $asPlatform->getStatusCode(), 'un compte de client n\'est pas un administrateur de plateforme');
        [$platformUsers] = $this->api('GET', '/platform/clients/platform/users', self::ROOT);
        self::assertSame(404, $platformUsers->getStatusCode());
    }

    public function testLimiteDeFrequenceEtInvalidationDuCodePrecedent(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->clientWithAdministrator();
        [, $first] = $this->issue($tenant, $user);
        [, $second] = $this->issue($tenant, $user);
        $old = (string) $first['data']['code'];
        $new = (string) $second['data']['code'];

        if ($old !== $new) {
            [$rejected] = $this->verify('paul@ipn.test', $old);
            self::assertSame(422, $rejected->getStatusCode(), 'l\'ancien code est invalidé');
        }
        self::assertCount(1, $this->rows('SELECT id FROM one_time_codes WHERE user_id = ? AND consumed_at IS NULL', [$user]));

        $this->issue($tenant, $user);
        $this->issue($tenant, $user);
        [$fifth] = $this->issue($tenant, $user);
        self::assertSame(201, $fifth->getStatusCode());
        [$sixth] = $this->issue($tenant, $user);
        self::assertSame(429, $sixth->getStatusCode());
        self::assertNotSame('', $sixth->getHeaderLine('Retry-After'));
    }

    public function testEnvoiSmsEnEchecSignaleSansPerdreLeCode(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->clientWithAdministrator();
        $this->sms->failing = true;

        [$response, $json] = $this->issue($tenant, $user, 'sms');
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('failed', $json['data']['delivery']['status']);
        self::assertSame('sms', $json['data']['delivery']['channel']);
        self::assertStringStartsWith('+2376', (string) $json['data']['delivery']['masked_to']);

        [$verified] = $this->verify('+237690123456', (string) $json['data']['code']);
        self::assertSame(200, $verified->getStatusCode(), 'le code reste utilisable une fois transmis à la main');

        $this->sms->failing = false;
        [, $sent] = $this->issue($tenant, $user, 'sms');
        self::assertSame('sent', $sent['data']['delivery']['status']);
        self::assertSame((string) $sent['data']['code'], $this->sms->lastCode('+237690123456'));
    }
}

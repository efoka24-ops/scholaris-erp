<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Tests\Support\Platform\PlatformTestCase;

/** Contact et numéros de paiement de la plateforme : réglages du back-office, repli sur l'environnement, droits, 2FA et journal. */
final class ContactSettingsTest extends PlatformTestCase
{
    /** @var array{tenant: string, establishment: string} */
    private array $ipes;

    protected function setUp(): void
    {
        $this->bootPlatform();
        $this->ipes = $this->client('IPES de Bonabéri');
    }

    protected function tearDown(): void
    {
        foreach (['PLATFORM_CONTACT_NAME', 'PLATFORM_CONTACT_EMAIL', 'PLATFORM_CONTACT_PHONE', 'PLATFORM_PAY_ORANGE_PHONE', 'PLATFORM_PAY_ORANGE_HOLDER', 'PLATFORM_PAY_MTN_PHONE', 'PLATFORM_PAY_MTN_HOLDER'] as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    /** @return array<string, mixed> */
    private function mine(): array
    {
        return $this->api('GET', '/me/regularisation', 'boss', $this->ipes['tenant'])[1]['data'];
    }

    public function testSansReglageNiVariableLeNomParDefautEstTruGroup(): void
    {
        $contact = $this->api('GET', '/platform/settings/contact', self::ROOT)[1]['data'];
        self::assertSame('TRU GROUP', $contact['effective']['name']);
        self::assertNull($contact['effective']['email']);
        self::assertSame([], $contact['effective']['payments']);
        self::assertNull($contact['stored']['name']);
        $mine = $this->mine();
        self::assertSame('TRU GROUP', $mine['contact']['name']);
        self::assertSame([], $mine['payment_numbers']);
    }

    public function testRepliSurLEnvironnementChampParChamp(): void
    {
        $_ENV['PLATFORM_CONTACT_NAME'] = 'TRU GROUP SARL';
        $_ENV['PLATFORM_CONTACT_EMAIL'] = 'env@trugroup.test';
        $_ENV['PLATFORM_CONTACT_PHONE'] = '+237699000010';
        $_ENV['PLATFORM_PAY_MTN_PHONE'] = '+237670000011';
        $_ENV['PLATFORM_PAY_MTN_HOLDER'] = 'TRU GROUP';
        $mine = $this->mine();
        self::assertSame('env@trugroup.test', $mine['contact']['email']);
        self::assertSame('+237699000010', $mine['contact']['phone']);
        self::assertSame([['channel' => 'mtn_momo', 'phone' => '+237670000011', 'holder' => 'TRU GROUP']], $mine['payment_numbers']);

        // Le réglage du back-office l'emporte, champ par champ : l'e-mail reste celui de l'environnement.
        [$ok] = $this->api('PATCH', '/platform/settings/contact', self::ROOT, null, ['name' => 'TRU GROUP', 'phone' => '699 00 00 99']);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        $mine = $this->mine();
        self::assertSame('TRU GROUP', $mine['contact']['name']);
        self::assertSame('+237699000099', $mine['contact']['phone']);
        self::assertSame('env@trugroup.test', $mine['contact']['email']);
        self::assertCount(1, $mine['payment_numbers']);

        // Champ vidé : retour à l'environnement.
        $this->api('PATCH', '/platform/settings/contact', self::ROOT, null, ['name' => null, 'phone' => '']);
        self::assertSame('TRU GROUP SARL', $this->mine()['contact']['name']);
        self::assertSame('+237699000010', $this->mine()['contact']['phone']);
    }

    public function testEnregistrementNormaliseLesTelephonesEtLitLesDeuxNumerosDePaiement(): void
    {
        [$ok, $json] = $this->api('PATCH', '/platform/settings/contact', self::ROOT, null, [
            'name' => 'TRU GROUP', 'email' => 'Paiements@TruGroup.TEST', 'phone' => '00237 6 99 00 00 01',
            'payments' => [['channel' => 'orange_money', 'phone' => '699 00 00 02', 'holder' => 'TRU GROUP SARL'], ['channel' => 'mtn_momo', 'phone' => '237670000003', 'holder' => 'Emmanuel F.']],
        ]);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertSame('paiements@trugroup.test', $json['data']['stored']['email']);
        self::assertSame('+237699000001', $json['data']['stored']['phone']);
        self::assertSame([
            ['channel' => 'orange_money', 'phone' => '+237699000002', 'holder' => 'TRU GROUP SARL'],
            ['channel' => 'mtn_momo', 'phone' => '+237670000003', 'holder' => 'Emmanuel F.'],
        ], $json['data']['stored']['payments']);
        self::assertSame($json['data']['stored']['payments'], $this->mine()['payment_numbers']);
        self::assertNotNull($json['data']['updated_at']);
    }

    public function testValidationDuContactEtDesNumeros(): void
    {
        $bad = [
            ['email' => 'pas-un-email'], ['phone' => '12345'], ['phone' => '+33 6 12 34 56 78'], ['name' => 'X'], ['inconnu' => 1],
            ['payments' => [['channel' => 'orange_money', 'phone' => '699 00 00 02']]],
            ['payments' => [['channel' => 'orange_money', 'phone' => '12', 'holder' => 'TRU']]],
            ['payments' => [['channel' => 'cash', 'phone' => '699 00 00 02', 'holder' => 'TRU']]],
            ['payments' => [['channel' => 'mtn_momo', 'phone' => '670 00 00 02', 'holder' => 'TRU'], ['channel' => 'mtn_momo', 'phone' => '670 00 00 03', 'holder' => 'TRU']]],
            ['payments' => [['channel' => 'mtn_momo', 'phone' => '670 00 00 02', 'holder' => 'A'], ['channel' => 'orange_money', 'phone' => '690 00 00 03', 'holder' => 'B'], ['channel' => 'mtn_momo', 'phone' => '670 00 00 04', 'holder' => 'C']]],
        ];
        foreach ($bad as $body) {
            [$response, $json] = $this->api('PATCH', '/platform/settings/contact', self::ROOT, null, $body);
            self::assertSame(422, $response->getStatusCode(), json_encode($body) . ' ' . $response->getBody());
            self::assertNotEmpty($json['errors']);
        }
        self::assertNull($this->api('GET', '/platform/settings/contact', self::ROOT)[1]['data']['stored']['email']);
    }

    public function testPermissionConfirmationRecenteEtJournal(): void
    {
        $body = ['name' => 'TRU GROUP', 'phone' => '699 00 00 01', 'payments' => [['channel' => 'orange_money', 'phone' => '699 00 00 02', 'holder' => 'TRU GROUP SARL']]];
        self::assertSame(403, $this->api('PATCH', '/platform/settings/contact', 'boss', $this->ipes['tenant'], $body, $this->ipes['establishment'])[0]->getStatusCode(), 'Un client ne règle pas la plateforme.');
        self::assertSame(403, $this->api('GET', '/platform/settings/contact', 'boss', $this->ipes['tenant'], null, $this->ipes['establishment'])[0]->getStatusCode());

        $this->permissions->grant('assistant', 'platform', 'platform.access', 'platform.dashboard.read');
        self::assertSame(200, $this->api('GET', '/platform/settings/contact', 'assistant', 'platform')[0]->getStatusCode(), 'Lecture : tableau de bord.');
        self::assertSame(403, $this->api('PATCH', '/platform/settings/contact', 'assistant', 'platform', $body)[0]->getStatusCode(), 'Écriture : platform.settings.manage.');

        $this->secondFactor->revoke(self::ROOT);
        self::assertSame(403, $this->api('PATCH', '/platform/settings/contact', self::ROOT, null, $body)[0]->getStatusCode(), '2FA récente requise.');
        $this->secondFactor->allow(self::ROOT);
        self::assertSame(200, $this->api('PATCH', '/platform/settings/contact', self::ROOT, null, $body)[0]->getStatusCode());

        $audit = $this->connections()->pdo()->query("SELECT after_json FROM audit_log WHERE action = 'platform.contact_changed'")->fetchColumn();
        self::assertIsString($audit);
        self::assertStringContainsString('payments', $audit);
        self::assertStringNotContainsString('699000002', $audit, 'Le journal ne contient aucun numéro en clair.');
        self::assertStringNotContainsString('699000001', $audit);
    }

    public function testLesNouveauxReglagesNumeriquesSontBornes(): void
    {
        foreach ([['regularisation_request_expiry_days' => 0], ['regularisation_request_expiry_days' => 61], ['regularisation_amount_tolerance_xaf' => -1], ['regularisation_partial_min_xaf' => 99], ['regularisation_partial_mode' => 2]] as $bad) {
            self::assertSame(422, $this->api('PATCH', '/platform/settings', self::ROOT, null, $bad)[0]->getStatusCode(), json_encode($bad));
        }
        [$ok, $json] = $this->api('PATCH', '/platform/settings', self::ROOT, null, ['regularisation_request_expiry_days' => 14, 'regularisation_amount_tolerance_xaf' => 250]);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertSame(14, $json['data']['regularisation_request_expiry_days']['value']);
        self::assertSame(250, $json['data']['regularisation_amount_tolerance_xaf']['value']);
        self::assertSame(7, $json['data']['regularisation_request_expiry_days']['default']);
        self::assertSame(1440, $json['data']['access_code_ttl_minutes']['value'], 'Les autres réglages ne bougent pas.');
        self::assertSame(422, $this->api('PATCH', '/platform/settings', self::ROOT, null, [])[0]->getStatusCode(), 'Corps vide : refusé comme avant.');
    }
}

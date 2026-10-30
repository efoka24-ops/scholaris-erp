<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use Psr\Http\Message\ResponseInterface;
use Tests\Support\Exports\ReadsXlsx;
use Tests\Support\Messaging\ChatTestCase;

/** Exports CSV / Excel du module Messaging : modèles, annonces, messages, journal d'envoi, signalements. */
final class ExportTest extends ChatTestCase
{
    use ReadsXlsx;

    private string $messageId = '';

    protected function setUp(): void
    {
        $this->bootChat();
        $this->parent('g1', 'Marie', '+237670000001');
        $this->access->grant('reader', 'e1');
        $this->permissions->grant('reader', 'messaging.read');

        self::assertSame(201, $this->code('POST', '/message-templates', 'alice', 't1', 'e1', ['template_key' => 'welcome', 'locale' => 'fr', 'channel' => 'sms', 'body' => 'Bonjour :first_name, éléve inscrit']));
        self::assertSame(201, $this->code('POST', '/message-templates', 'alice', 't1', 'e1', ['template_key' => 'welcome', 'locale' => 'en', 'channel' => 'sms', 'body' => 'Hello :first_name']));
        self::assertSame(201, $this->code('POST', '/announcements', 'alice', 't1', 'e1', ['title' => 'Rentrée', 'body' => 'La rentrée est fixée au 7 septembre.']));
        self::assertSame(201, $this->code('POST', '/announcements', 'alice', 't1', 'e1', ['title' => '=Piège', 'body' => 'Formule']));

        [$created, $body] = $this->api('POST', '/messages', 'alice', 't1', 'e1', ['template_key' => 'family_payment_reference', 'variables' => ['payment_reference' => 'FAM-0587'], 'audience' => ['type' => 'person', 'id' => 'g1']]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $this->messageId = (string) $body['data']['id'];
        self::assertSame(200, $this->code('POST', '/messages/' . $this->messageId . '/send', 'alice', 't1', 'e1'));
    }

    /** @return array<string, string> */
    private function as(string $user, string $tenant = 't1', string $establishment = 'e1'): array
    {
        return ['Authorization' => 'Bearer ' . $user . ':' . $tenant, 'X-Establishment-Id' => $establishment];
    }

    private function get(string $path, string $user, string $tenant, string $establishment): ResponseInterface
    {
        return $this->call('GET', '/api/v1' . $path, $this->as($user, $tenant, $establishment));
    }

    private function httpStatus(string $path, string $user, string $tenant, string $establishment): int
    {
        return $this->get($path, $user, $tenant, $establishment)->getStatusCode();
    }
    /** @return list<list<string>> */
    private function csv(string $path, string $user = 'alice', string $tenant = 't1', string $establishment = 'e1'): array
    {
        $response = $this->get($path, $user, $tenant, $establishment);
        self::assertSame(200, $response->getStatusCode(), $path . ' ' . $response->getBody());
        $body = (string) $response->getBody();
        self::assertStringStartsWith("\xEF\xBB\xBF", $body);
        self::assertStringStartsWith('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));

        return array_map(static fn (string $line): array => str_getcsv($line, ';', '"', ''), explode("\n", trim(substr($body, 3))));
    }

    public function testExportDesModelesAvecFiltres(): void
    {
        $all = $this->csv('/message-templates/export');
        self::assertGreaterThanOrEqual(3, count($all));
        self::assertSame(['Identifiant', 'Clé', 'Langue', 'Canal'], array_slice($all[0], 0, 4));
        self::assertContains('Bonjour :first_name, éléve inscrit', array_column($all, 5));

        $welcome = $this->csv('/message-templates/export?filter[template_key]=welcome&filter[locale]=en');
        self::assertCount(2, $welcome);
        self::assertSame('Hello :first_name', $welcome[1][5]);
        self::assertSame(422, $this->httpStatus('/message-templates/export?filter[x]=1', 'alice', 't1', 'e1'));
    }

    public function testExportDesAnnoncesNeutraliseLesFormules(): void
    {
        $rows = $this->csv('/announcements/export');
        self::assertCount(3, $rows);
        self::assertContains("'=Piège", array_column($rows, 1));
        self::assertContains('Rentrée', array_column($rows, 1));
    }

    public function testExportDesMessagesEtDuJournalDEnvoi(): void
    {
        $messages = $this->csv('/messages/export');
        self::assertCount(2, $messages);
        self::assertSame($this->messageId, $messages[1][0]);
        self::assertCount(1, $this->csv('/messages/export?filter[status]=draft'));

        $deliveries = $this->csv('/messages/' . $this->messageId . '/deliveries/export');
        self::assertGreaterThanOrEqual(2, count($deliveries));
        self::assertSame(['Identifiant', 'Message', 'Destinataire', 'Canal', 'Statut'], array_slice($deliveries[0], 0, 5));
        self::assertSame('g1', $deliveries[1][2]);
        self::assertCount(1, $this->csv('/messages/' . $this->messageId . '/deliveries/export?filter[status]=blocked'));
        self::assertSame(404, $this->httpStatus('/messages/inconnu/deliveries/export', 'alice', 't1', 'e1'));
        self::assertSame(422, $this->httpStatus('/messages/' . $this->messageId . '/deliveries/export?filter[x]=1', 'alice', 't1', 'e1'));
    }

    public function testExportDesConversationsSignaleesReserveAuxModerateurs(): void
    {
        $conversation = $this->openConversation();
        [$sent, $body] = $this->api('POST', '/conversations/' . $conversation . '/messages', 'tom', 't1', 'e1', ['body' => 'Message déplacé']);
        self::assertSame(201, $sent->getStatusCode());
        self::assertSame(201, $this->code('POST', '/conversations/' . $conversation . '/messages/' . $body['data']['id'] . '/report', 'paula', 't1', 'e1', ['reason' => 'Propos inappropriés']));

        $rows = $this->csv('/conversation-reports/export', 'mona');
        self::assertCount(2, $rows);
        self::assertContains('Propos inappropriés', array_column($rows, 4));
        self::assertCount(2, $this->csv('/conversation-reports/export?filter[status]=open', 'mona'));
        self::assertCount(1, $this->csv('/conversation-reports/export?filter[status]=dismissed', 'mona'));
        self::assertSame(403, $this->httpStatus('/conversation-reports/export', 'paula', 't1', 'e1'));
        self::assertSame(403, $this->httpStatus('/conversation-reports/export', 'alice', 't1', 'e1'));
    }

    public function testLaLectureEstExigee(): void
    {
        $this->access->grant('nobody', 'e1');
        foreach (['/message-templates/export', '/announcements/export', '/messages/export', '/messages/' . $this->messageId . '/deliveries/export'] as $path) {
            self::assertSame(403, $this->httpStatus($path, 'nobody', 't1', 'e1'), $path);
            self::assertSame(200, $this->httpStatus($path, 'reader', 't1', 'e1'), $path . ' en lecture seule');
        }
    }

    public function testFormatExcel(): void
    {
        $paths = ['/message-templates/export' => 3, '/announcements/export' => 3, '/messages/export' => 2];
        foreach ($paths as $path => $minimum) {
            self::assertGreaterThanOrEqual($minimum, count($this->xlsxRows($path . '?format=xlsx', $this->as('alice'))), $path);
        }
        $deliveries = $this->xlsxRows('/messages/' . $this->messageId . '/deliveries/export?format=xlsx', $this->as('alice'));
        self::assertSame('Identifiant', $deliveries[0][0]);
        self::assertContains("'=Piège", array_column($this->xlsxRows('/announcements/export?format=xlsx', $this->as('alice')), 1));
        self::assertSame(422, $this->httpStatus('/messages/export?format=pdf', 'alice', 't1', 'e1'));
    }

    public function testIsolationEntreEtablissementsEtEntreClients(): void
    {
        foreach (['/announcements/export', '/messages/export'] as $path) {
            self::assertGreaterThan(1, count($this->csv($path)), $path);
            self::assertCount(1, $this->csv($path, 'alice', 't1', 'e2'), $path . ' : autre établissement');
            self::assertCount(1, $this->csv($path, 'bob', 't2', 'e3'), $path . ' : autre client');
        }
        self::assertSame(404, $this->httpStatus('/messages/' . $this->messageId . '/deliveries/export', 'bob', 't2', 'e3'));
        self::assertSame(404, $this->httpStatus('/messages/' . $this->messageId . '/deliveries/export', 'alice', 't1', 'e2'));
        $templates = $this->csv('/message-templates/export', 'bob', 't2', 'e3');
        self::assertNotContains('Hello :first_name', array_column($templates, 5), 'Les modèles d\'un autre client ne sortent pas.');
    }
}

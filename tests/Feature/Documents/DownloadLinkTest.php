<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Tests\Support\Billing\ArraySettings;
use Tests\Support\Documents\DocumentsTestCase;

/** SS14 : lien de téléchargement à durée limitée et à usage borné, journalisé ; aucune donnée sans jeton valide. */
final class DownloadLinkTest extends DocumentsTestCase
{
    private ArraySettings $settings;

    protected function setUp(): void
    {
        $this->settings = new ArraySettings();
        $this->bootDocuments([SettingsReader::class => $this->settings]);
    }

    /** @return array<string, mixed> */
    private function link(string $documentId): array
    {
        [$response, $json] = $this->api('POST', '/documents/' . $documentId . '/download-link', 'alice', 't1', 'e1');
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    private function download(string $token): \Psr\Http\Message\ResponseInterface
    {
        return $this->call('GET', '/api/v1/public/documents/' . $token);
    }

    public function testLienValideServiLePdfSansAuthentificationPuisExpireApres24h(): void
    {
        $doc = $this->issueViaApi();
        $link = $this->link((string) $doc['id']);

        self::assertStringStartsWith('https://api.scholaris.test/api/v1/public/documents/', $link['url']);
        self::assertSame('2026-10-09T09:00:00+01:00', $link['expires_at'], 'Durée par défaut : 24 h.');
        self::assertSame(5, $link['max_uses']);

        $ok = $this->download((string) $link['token']);
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('application/pdf', $ok->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $ok->getHeaderLine('Cache-Control'));

        $this->clock->set('2026-10-09T09:00:01+01:00');
        $expired = $this->download((string) $link['token']);
        self::assertSame(410, $expired->getStatusCode());
        $body = (string) $expired->getBody();
        self::assertStringContainsString('expiré', $body);
        foreach (['Fotso', 'Nadia', '3eA', (string) $doc['number']] as $leak) {
            self::assertStringNotContainsString($leak, $body, 'Aucune donnée sur un lien expiré.');
        }

        $outcomes = $this->connections()->pdo()->query('SELECT outcome FROM document_download_log ORDER BY accessed_at, outcome')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertEqualsCanonicalizing(['served', 'expired'], $outcomes, 'Chaque tentative est journalisée.');
        self::assertSame(0, (int) $this->connections()->pdo()->query("SELECT COUNT(*) FROM document_download_links WHERE token_hash = '" . $link['token'] . "'")->fetchColumn(), 'Le jeton n\'est pas stocké en clair.');
    }

    public function testDureeEtNombreDUtilisationsParametres(): void
    {
        $this->settings->values['documents.download_link_ttl_hours'] = 2;
        $this->settings->values['documents.download_link_max_uses'] = 2;
        $doc = $this->issueViaApi();
        $link = $this->link((string) $doc['id']);

        self::assertSame('2026-10-08T11:00:00+01:00', $link['expires_at']);
        self::assertSame(200, $this->download((string) $link['token'])->getStatusCode());
        self::assertSame(200, $this->download((string) $link['token'])->getStatusCode());
        self::assertSame(410, $this->download((string) $link['token'])->getStatusCode(), 'Usage borné.');
    }

    public function testJetonInconnuOuMalFormeDonne404(): void
    {
        self::assertSame(404, $this->download(str_repeat('a', 48))->getStatusCode());
        self::assertSame(404, $this->download('pas-un-jeton')->getStatusCode());
    }

    public function testDocumentRevoqueApresCreationDuLien(): void
    {
        $doc = $this->issueViaApi();
        $link = $this->link((string) $doc['id']);
        [$revoke] = $this->api('POST', '/documents/' . $doc['id'] . '/revoke', 'alice', 't1', 'e1', ['reason' => 'Erreur']);
        self::assertSame(200, $revoke->getStatusCode());

        self::assertSame(410, $this->download((string) $link['token'])->getStatusCode());
        [$conflict] = $this->api('POST', '/documents/' . $doc['id'] . '/download-link', 'alice', 't1', 'e1');
        self::assertSame(409, $conflict->getStatusCode(), 'Pas de lien pour un document révoqué.');
    }

    public function testAutreClientEtDroitDeLectureRequis(): void
    {
        $doc = $this->issueViaApi();

        [$other] = $this->api('POST', '/documents/' . $doc['id'] . '/download-link', 'bob', 't2', 'e3');
        self::assertSame(404, $other->getStatusCode());
        $this->access->grant('dan', 'e1');
        [$noRight] = $this->api('POST', '/documents/' . $doc['id'] . '/download-link', 'dan', 't1', 'e1');
        self::assertSame(403, $noRight->getStatusCode());
    }
}

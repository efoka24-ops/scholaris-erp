<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Scholaris\Modules\Socle\People\Domain\DocumentKind;
use Tests\Support\People\PeopleTestCase;

/** Dossier documentaire : type, taille, stockage privé, URL signée temporaire, complétude et expiration. */
final class DocumentTest extends PeopleTestCase
{
    /** @var array<string, mixed> */
    private array $person = [];

    protected function setUp(): void
    {
        $this->bootPeople();
        $this->person = $this->person('Nadia', 'Foka');
    }

    private function path(string $suffix = ''): string
    {
        return '/people/' . $this->person['id'] . '/documents' . $suffix;
    }

    public function testPdfAccepteStockeHorsRacineWebSousUnNomGenere(): void
    {
        [$response, $body] = $this->upload($this->path(), $this->pdfContent(), ['kind' => 'birth_certificate']);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/pdf', $body['data']['mime_type']);
        self::assertArrayNotHasKey('storage_path', $body['data']);
        $path = (string) $this->connections()->pdo()->query('SELECT storage_path FROM person_documents')->fetchColumn();
        self::assertStringStartsWith('t1/people/' . $this->person['id'] . '/', $path);
        self::assertStringEndsWith('.pdf', $path);
        self::assertStringNotContainsString('nom-client', $path, 'Le nom du fichier n\'est jamais pris du client.');
        self::assertFileExists($this->storage . '/' . $path);
        self::assertStringNotContainsString(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'public', (string) realpath($this->storage));
    }

    public function testTypesTailleEtContenusActifsRefuses(): void
    {
        self::assertSame(422, $this->upload($this->path(), 'MZ' . str_repeat('x', 200), ['kind' => 'birth_certificate'])[0]->getStatusCode(), 'Exécutable déguisé.');
        self::assertSame(422, $this->upload($this->path(), "<?php echo 'x';", ['kind' => 'birth_certificate'])[0]->getStatusCode());
        [$large, $body] = $this->upload($this->path(), '%PDF-' . str_repeat('x', 5 * 1024 * 1024), ['kind' => 'birth_certificate']);
        self::assertSame(422, $large->getStatusCode());
        self::assertStringContainsString('5 Mo', $body['errors']['file'][0]);
        self::assertSame(422, $this->upload($this->path(), $this->pdfContent('/JavaScript (app.alert(1))'), ['kind' => 'birth_certificate'])[0]->getStatusCode());
        self::assertSame(422, $this->upload($this->path(), $this->pdfContent(), ['kind' => 'inconnu'])[0]->getStatusCode());
        self::assertSame(422, $this->upload($this->path(), $this->pdfContent(), ['kind' => 'other', 'expires_on' => '2026-02-31'])[0]->getStatusCode());
        self::assertSame('0', (string) $this->connections()->pdo()->query('SELECT COUNT(*) FROM person_documents')->fetchColumn());
    }

    public function testCompletudeEtExpiration(): void
    {
        $this->upload($this->path(), $this->pdfContent(), ['kind' => 'birth_certificate']);
        $this->upload($this->path(), $this->pdfContent(), ['kind' => 'vaccination_card', 'expires_on' => '2026-11-15']);
        $this->upload($this->path(), $this->pdfContent(), ['kind' => 'medical_certificate', 'expires_on' => '2026-09-01']);
        $this->upload($this->path(), "\x89PNG\r\n\x1a\nxx", ['kind' => 'id_photo']);

        [, $body] = $this->api('GET', $this->path());
        $completeness = $body['meta']['completeness'];

        self::assertSame(7, $completeness['required']);
        self::assertSame(3, $completeness['received'], 'Une pièce expirée ne compte pas.');
        self::assertSame(['vaccination_card'], $completeness['expiring_soon']);
        self::assertSame(['medical_certificate'], $completeness['expired']);
        self::assertSame(['previous_report', 'medical_certificate', 'guardian_id', 'residence_proof'], $completeness['missing']);
        $statuses = array_column($body['data'], 'status', 'kind');
        self::assertSame('expiring_soon', $statuses['vaccination_card']);
        self::assertSame('expired', $statuses['medical_certificate']);

        $photo = $this->connections()->pdo()->query('SELECT photo_path FROM people')->fetchColumn();
        self::assertNotEmpty($photo, 'La photo d\'identité alimente la fiche.');
        self::assertSame(count(DocumentKind::required()), $completeness['required']);
    }

    public function testUrlSigneeTemporaire(): void
    {
        $document = $this->upload($this->path(), $this->pdfContent('contenu-secret'), ['kind' => 'birth_certificate'])[1]['data'];
        $link = $this->api('GET', $this->path('/' . $document['id'] . '/link'))[1]['data'];

        self::assertSame('2026-10-08T09:05:00+01:00', $link['expires_at']);
        $anonymous = $this->call('GET', $link['url']);
        self::assertSame(200, $anonymous->getStatusCode(), 'Le lien signé tient lieu d\'authentification.');
        self::assertStringContainsString('contenu-secret', (string) $anonymous->getBody());
        self::assertSame('application/pdf', $anonymous->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment', $anonymous->getHeaderLine('Content-Disposition'));
        self::assertStringNotContainsString('nom-client', $anonymous->getHeaderLine('Content-Disposition'));

        self::assertSame(403, $this->call('GET', preg_replace('/signature=[0-9a-f]{4}/', 'signature=0000', $link['url']) ?? '')->getStatusCode(), 'Signature falsifiée.');
        self::assertSame(403, $this->call('GET', (string) preg_replace('/expires=\d+/', 'expires=9999999999', $link['url']))->getStatusCode(), 'Expiration prolongée.');
        self::assertSame(403, $this->call('GET', strtok($link['url'], '?') ?: '')->getStatusCode(), 'Sans signature.');

        $this->clock->advance(301);
        self::assertSame(403, $this->call('GET', $link['url'])->getStatusCode(), 'Lien expiré.');
    }

    public function testLeLienNEstPasUtilisableSurUneAutrePiece(): void
    {
        $first = $this->upload($this->path(), $this->pdfContent(), ['kind' => 'birth_certificate'])[1]['data'];
        $other = $this->person('Paul', 'Ndi');
        $link = $this->api('GET', $this->path('/' . $first['id'] . '/link'))[1]['data']['url'];

        self::assertSame(403, $this->call('GET', str_replace((string) $this->person['id'], (string) $other['id'], $link))->getStatusCode());
    }

    public function testSuppressionEtDroits(): void
    {
        $document = $this->upload($this->path(), $this->pdfContent(), ['kind' => 'other'])[1]['data'];
        $this->permissions->grant('lecteur', 'people.read');
        $this->access->grant('lecteur', 'e1');

        self::assertSame(200, $this->code('GET', $this->path(), 'lecteur'));
        self::assertSame(403, $this->upload($this->path(), $this->pdfContent(), ['kind' => 'other'], 'lecteur')[0]->getStatusCode());
        self::assertSame(403, $this->code('DELETE', $this->path('/' . $document['id']), 'lecteur'));
        self::assertSame(204, $this->code('DELETE', $this->path('/' . $document['id'])));
        self::assertSame(404, $this->code('DELETE', $this->path('/' . $document['id'])));
    }
}

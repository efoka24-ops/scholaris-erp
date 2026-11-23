<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Schools;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Tests\Feature\PublicSchools\Pupils\PublicSchoolsTestCase;

/** EX-ECO-04 : photos géolocalisées prises lors des visites de l'inspection, lecture par lien signé. Données SYN-*. */
final class SchoolPhotoTest extends PublicSchoolsTestCase
{
    protected function setUp(): void
    {
        $this->bootPublic();
        $this->seedSchool('t1', 'e1', 'SYN-E1');
        $this->seedSchool('t2', 'e9', 'SYN-E9');
        $this->supervisor('insp', 't1', 'e1', 'A1', 'pub_district_inspector', 'public_schools.inspection.read', 'public_schools.inspection.write');
        $this->supervisor('insp9', 't2', 'e9', 'AB', 'pub_district_inspector', 'public_schools.inspection.read', 'public_schools.inspection.write');
        $this->director('dir1', 't1', 'e1', 'public_schools.inspection.read');
    }

    private function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return ['content_base64' => base64_encode($this->png()), 'latitude' => 9.3012, 'longitude' => 13.3921, 'taken_at' => '2026-10-20T10:15:00+01:00'];
    }

    public function testL_InspecteurAjouteUnePhotoGeolocaliseeEtLaLitParLienSigne(): void
    {
        [$created, $json] = $this->api('POST', '/public/schools/photos', 'insp', 't1', 'e1', $this->body());
        self::assertSame(201, $created->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));
        self::assertSame('9.301200', $json['data']['latitude']);
        self::assertSame('insp', $json['data']['taken_by']);
        self::assertStringContainsString('/api/v1/public/schools/photos/' . $json['data']['id'] . '/content?', $json['data']['url']);

        [$list] = $this->api('GET', '/public/schools/photos', 'dir1', 't1', 'e1');
        self::assertSame(200, $list->getStatusCode());

        $path = (string) parse_url($json['data']['url'], PHP_URL_PATH) . '?' . (string) parse_url($json['data']['url'], PHP_URL_QUERY);
        $content = $this->call('GET', $path, ['Authorization' => 'Bearer dir1:t1', 'X-Establishment-Id' => 'e1']);
        self::assertSame(200, $content->getStatusCode());
        self::assertSame('image/png', $content->getHeaderLine('Content-Type'));
        self::assertSame($this->png(), (string) $content->getBody());

        $forged = $this->call('GET', preg_replace('/signature=[^&]+/', 'signature=falsifie', $path) ?? '', ['Authorization' => 'Bearer dir1:t1', 'X-Establishment-Id' => 'e1']);
        self::assertSame(403, $forged->getStatusCode());
    }

    public function testEnvoiMultipart(): void
    {
        $request = (new ServerRequest('POST', '/api/v1/public/schools/photos', ['Authorization' => 'Bearer insp:t1', 'X-Establishment-Id' => 'e1']))
            ->withParsedBody(['latitude' => '9.30', 'longitude' => '13.39', 'taken_at' => '2026-10-20T10:15:00+01:00'])
            ->withUploadedFiles(['file' => new UploadedFile(Stream::create($this->png()), strlen($this->png()), UPLOAD_ERR_OK, 'visite.png', 'image/png')]);

        self::assertSame(201, $this->kernel->handle($request)->getStatusCode());
    }

    public function testPositionEtFichierSontObligatoires(): void
    {
        self::assertSame(422, $this->code('POST', '/public/schools/photos', 'insp', 't1', 'e1', ['latitude' => null] + $this->body()));
        self::assertSame(422, $this->code('POST', '/public/schools/photos', 'insp', 't1', 'e1', ['longitude' => 200] + $this->body()));
        self::assertSame(422, $this->code('POST', '/public/schools/photos', 'insp', 't1', 'e1', ['taken_at' => 'hier'] + $this->body()));
        self::assertSame(422, $this->code('POST', '/public/schools/photos', 'insp', 't1', 'e1', ['content_base64' => base64_encode('<?php echo 1;')] + $this->body()));
        self::assertSame(422, $this->code('POST', '/public/schools/photos', 'insp', 't1', 'e1', ['content_base64' => '***'] + $this->body()));
    }

    public function testLeDirecteurLitMaisNAjoutePas(): void
    {
        self::assertSame(403, $this->code('POST', '/public/schools/photos', 'dir1', 't1', 'e1', $this->body()), 'Le directeur n\'a pas la permission d\'inspection.');
    }

    public function testIsolationEntreClients(): void
    {
        [, $json] = $this->api('POST', '/public/schools/photos', 'insp', 't1', 'e1', $this->body());
        $path = (string) parse_url($json['data']['url'], PHP_URL_PATH) . '?' . (string) parse_url($json['data']['url'], PHP_URL_QUERY);

        self::assertSame([], $this->api('GET', '/public/schools/photos', 'insp9', 't2', 'e9')[1]['data']);
        $other = $this->call('GET', $path, ['Authorization' => 'Bearer insp9:t2', 'X-Establishment-Id' => 'e9']);
        self::assertSame(404, $other->getStatusCode(), 'Photo d\'un autre client introuvable.');
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM pub_school_photos WHERE tenant_id = ?', ['t2']));
    }
}

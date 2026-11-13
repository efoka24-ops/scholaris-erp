<?php

declare(strict_types=1);

namespace Tests\Feature\Organisation;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Modules\Socle\Identity\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Organisation\Application\EstablishmentLogoReader;
use Scholaris\Modules\Socle\Organisation\Application\LogoService;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentLogo;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Organisation\Domain\LogoProcessor;
use Tests\Support\Organisation\FixedClock;
use Tests\Support\Organisation\FixedRecentSecondFactor;
use Tests\Support\Organisation\OrganisationTestCase;
use Tests\Support\Organisation\StubPermissions;

/** Logo de l'établissement : téléversement vérifié par le contenu, remplacement, suppression, droits, isolation et contrat de lecture. */
final class EstablishmentLogoTest extends OrganisationTestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/scholaris-logo-' . bin2hex(random_bytes(6));
        $this->permissions = new StubPermissions();
        $this->clock = new FixedClock();
        $this->secondFactor = new FixedRecentSecondFactor();
        $this->boot([
            PermissionChecker::class => $this->permissions,
            Clock::class => $this->clock,
            RecentSecondFactor::class => $this->secondFactor,
        ], ['organisation' => ['storage_path' => $this->storage]]);
        $this->seedEstablishment('t1', 'e1', 'Lycée', 'secondary', 'alice', 'carol');
        $this->seedEstablishment('t1', 'e2', 'Collège', 'secondary', 'alice');
        $this->seedEstablishment('t2', 'e3', 'Étranger', 'higher', 'bob');
        $this->grantAll('alice');
        $this->grantAll('bob');
        $this->permissions->grant('carol', 'organisation.read');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storage . '/tenants/*/establishments/*/logo/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function png(int $width = 800, int $height = 400): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 20, 90, 160));
        imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), (int) ($width / 2), (int) ($height / 2), (int) imagecolorallocate($image, 250, 220, 0));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function jpeg(int $width = 500, int $height = 500): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    /** @return array{0: ResponseInterface, 1: array<string, mixed>} */
    private function upload(string $content, string $user = 'alice', string $tenant = 't1', string $establishment = 'e1', string $filename = 'logo.png'): array
    {
        $request = (new ServerRequest('POST', '/api/v1/establishments/' . $establishment . '/logo', ['Authorization' => 'Bearer ' . $user . ':' . $tenant]))
            ->withUploadedFiles(['file' => new UploadedFile(Stream::create($content), strlen($content), UPLOAD_ERR_OK, $filename, 'image/png')]);
        $response = $this->kernel->handle($request);
        $raw = (string) $response->getBody();

        return [$response, $raw === '' ? [] : (array) json_decode($raw, true)];
    }

    private function getLogo(string $establishment, string $user, string $tenant): ResponseInterface
    {
        return $this->call('GET', '/api/v1/establishments/' . $establishment . '/logo', ['Authorization' => 'Bearer ' . $user . ':' . $tenant]);
    }

    private function logoFiles(): int
    {
        return count(glob($this->storage . '/tenants/*/establishments/*/logo/*') ?: []);
    }

    public function testTeleversementRedimensionneA300PxEtLeLogoSeRelit(): void
    {
        [$response, $json] = $this->upload($this->png());

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('image/png', $json['data']['logo']['mime']);
        self::assertStringStartsWith('tenants/t1/establishments/e1/logo/', $json['data']['logo']['path']);
        self::assertStringEndsWith('.png', $json['data']['logo']['path']);

        $read = $this->call('GET', '/api/v1/establishments/e1/logo', ['Authorization' => 'Bearer alice:t1']);
        self::assertSame(200, $read->getStatusCode());
        self::assertSame('image/png', $read->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $read->getHeaderLine('X-Content-Type-Options'));
        [$width, $height] = getimagesizefromstring((string) $read->getBody());
        self::assertSame([300, 150], [$width, $height], 'Ramené à 300 px de large, ratio conservé.');
        self::assertSame($json['data']['logo']['size'], strlen((string) $read->getBody()));
    }

    public function testUnPetitLogoNEstPasAgrandiEtUnJpegResteUnJpeg(): void
    {
        [$response, $json] = $this->upload($this->jpeg(120, 80), filename: 'logo.jpg');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/jpeg', $json['data']['logo']['mime']);
        $read = $this->call('GET', '/api/v1/establishments/e1/logo', ['Authorization' => 'Bearer alice:t1']);
        [$width, $height] = getimagesizefromstring((string) $read->getBody());
        self::assertSame([120, 80], [$width, $height]);
    }

    public function testLeTypeEstVerifieParLeContenuEtNonParLeNom(): void
    {
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
        $cases = [
            'texte déguisé en png' => ['<?php echo 1;', 'logo.png'],
            'gif' => [(string) $gif, 'logo.png'],
            'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.png'],
            'png tronqué' => [substr($this->png(), 0, 60), 'logo.png'],
            'vide' => ['', 'logo.png'],
        ];
        foreach ($cases as $label => [$content, $name]) {
            [$response, $json] = $this->upload($content, filename: $name);
            self::assertSame(422, $response->getStatusCode(), $label);
            self::assertArrayHasKey('file', $json['errors'], $label);
        }
        self::assertSame(0, $this->logoFiles(), 'Rien n\'est stocké en cas de refus.');
    }

    public function testPlusDe1MoEstRefuse(): void
    {
        $noise = random_bytes(LogoProcessor::MAX_BYTES + 10);
        [$response] = $this->upload($noise);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testDonneesAnnexesRetireesParLeReEncodage(): void
    {
        $png = $this->png(100, 100) . '<?php system($_GET["c"]); ?>';
        [$response] = $this->upload($png);

        self::assertSame(200, $response->getStatusCode());
        $read = $this->call('GET', '/api/v1/establishments/e1/logo', ['Authorization' => 'Bearer alice:t1']);
        self::assertStringNotContainsString('<?php', (string) $read->getBody());
    }

    public function testRemplacementSupprimeAncienFichierEtSuppressionRetireLeLogo(): void
    {
        $this->upload($this->png());
        self::assertSame(1, $this->logoFiles());
        $first = $this->api('GET', '/establishments/e1/branding', 'alice', 't1')[1]['data']['logo']['path'];

        [, $json] = $this->upload($this->jpeg());
        self::assertSame(1, $this->logoFiles(), 'Un seul logo par établissement.');
        self::assertNotSame($first, $json['data']['logo']['path']);

        [$deleted, $body] = $this->api('DELETE', '/establishments/e1/logo', 'alice', 't1');
        self::assertSame(200, $deleted->getStatusCode());
        self::assertNull($body['data']['logo']);
        self::assertSame(0, $this->logoFiles());
        self::assertSame(404, $this->getLogo('e1', 'alice', 't1')->getStatusCode());
        self::assertSame(200, $this->code('DELETE', '/establishments/e1/logo', 'alice', 't1'), 'Supprimer sans logo est sans effet.');
    }

    public function testLesActionsSontJournalisees(): void
    {
        $this->upload($this->png());
        $this->api('DELETE', '/establishments/e1/logo', 'alice', 't1');

        $actions = $this->connections()->pdo()->query("SELECT action FROM audit_log WHERE tenant_id = 't1' AND action LIKE 'establishment.logo_%' ORDER BY seq")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['establishment.logo_updated', 'establishment.logo_removed'], $actions);
    }

    public function testDroits(): void
    {
        self::assertSame(403, $this->upload($this->png(), 'carol')[0]->getStatusCode(), 'organisation.read ne suffit pas pour téléverser.');
        self::assertSame(403, $this->code('DELETE', '/establishments/e1/logo', 'carol', 't1'));

        $this->upload($this->png());
        self::assertSame(200, $this->getLogo('e1', 'carol', 't1')->getStatusCode(), 'La lecture reste permise.');
        self::assertSame(401, $this->call('GET', '/api/v1/establishments/e1/logo')->getStatusCode());
        self::assertSame(1, $this->logoFiles());
    }

    public function testUnEtablissementDUnAutreClientDonne404(): void
    {
        $this->upload($this->png(), 'bob', 't2', 'e3');

        self::assertSame(404, $this->upload($this->png(), 'alice', 't1', 'e3')[0]->getStatusCode());
        self::assertSame(404, $this->getLogo('e3', 'alice', 't1')->getStatusCode());
        self::assertSame(404, $this->code('DELETE', '/establishments/e3/logo', 'alice', 't1'));
        self::assertSame(200, $this->getLogo('e3', 'bob', 't2')->getStatusCode());
    }

    public function testLeContratDeLectureDonneLeLogoPropreSinonCelduClientSinonRien(): void
    {
        /** @var EstablishmentLogo $contract */
        $contract = $this->kernel->container()->get(EstablishmentLogo::class);
        self::assertInstanceOf(EstablishmentLogoReader::class, $contract);
        self::assertNull($contract->find('t1', 'e1'));

        $this->upload($this->png(), establishment: 'e1');
        $logo = $contract->find('t1', 'e1');
        self::assertNotNull($logo);
        self::assertSame('image/png', $logo->mime);
        self::assertSame(300, $logo->width);
        self::assertStringStartsWith('data:image/png;base64,', $logo->dataUri());
        self::assertNull($contract->find('t1', 'e2'), 'Le logo de e1 ne sert pas à e2.');
        self::assertNull($contract->find('t2', 'e1'), 'Jamais un établissement d\'un autre client.');

        // Repli : logo du client (paramètre), sous le préfixe de son client seulement.
        $dir = $this->storage . '/tenants/t1/logo';
        mkdir($dir, 0750, true);
        file_put_contents($dir . '/client.png', $this->png(100, 50));
        $reader = fn (mixed $path): EstablishmentLogoReader => new EstablishmentLogoReader(
            $this->kernel->container()->get(LogoService::class),
            new class ($path) implements SettingsReader {
                public function __construct(private readonly mixed $path)
                {
                }

                public function value(string $tenantId, ?string $establishmentId, string $key, mixed $default = null): mixed
                {
                    return $key === EstablishmentLogoReader::CLIENT_LOGO_SETTING ? $this->path : $default;
                }
            },
        );

        $fallback = $reader('tenants/t1/logo/client.png')->find('t1', 'e2');
        self::assertNotNull($fallback);
        self::assertSame(100, $fallback->width);
        self::assertSame(300, $reader('tenants/t1/logo/client.png')->find('t1', 'e1')?->width, 'Le logo propre passe avant celui du client.');
        self::assertNull($reader('tenants/t1/logo/client.png')->find('t2', 'e9'), 'Chemin d\'un autre client : refusé.');
        foreach (['tenants/t1/logo/../establishments/e1/logo/x.png', '/etc/passwd', 'tenants/t2/logo/client.png', 42, null] as $bad) {
            self::assertNull($reader($bad)->find('t1', 'e2'), json_encode($bad));
        }

        unlink($dir . '/client.png');
        rmdir($dir);
    }
}

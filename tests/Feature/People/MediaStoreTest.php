<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\People\Contracts\AttachmentStore;
use Scholaris\Modules\Socle\People\Contracts\MediaContent;
use Scholaris\Modules\Socle\People\Contracts\MediaRef;
use Scholaris\Modules\Socle\People\Contracts\MediaStore;
use Scholaris\Modules\Socle\People\Contracts\StoredAttachment;
use Scholaris\Modules\Socle\People\Domain\UrlSigner;
use Scholaris\Modules\Socle\People\Infrastructure\FileMediaStore;
use Tests\Support\People\PeopleTestCase;

/** PM2 (T701) : dépôt de médias d'application par composition sur AttachmentStore ; URL signées à durée limitée. SYN-* uniquement. */
final class MediaStoreTest extends PeopleTestCase
{
    private const NS = 'public-nursery';
    private const OWNER = 'EST1';
    public const ULID = '01HZZZZZZZZZZZZZZZZZZZZZZZ';

    protected function setUp(): void
    {
        $this->bootPeople();
    }

    private function store(): MediaStore
    {
        return $this->kernel->container()->get(MediaStore::class);
    }

    private function jpeg(string $extra = '', int $pad = 64): string
    {
        return "\xFF\xD8\xFF\xE0" . str_repeat('J', $pad) . $extra;
    }

    private function png(string $extra = ''): string
    {
        return "\x89PNG\r\n\x1a\n" . str_repeat('P', 64) . $extra;
    }

    /** @return list<string> chemins relatifs des fichiers présents dans le stockage */
    private function files(): array
    {
        if (!is_dir($this->storage)) {
            return [];
        }
        $out = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $out[] = str_replace('\\', '/', substr($file->getPathname(), strlen($this->storage) + 1));
            }
        }
        sort($out);

        return $out;
    }

    /** @return array{tenant: string, ref: string, expires: int, signature: string} */
    private function link(string $url): array
    {
        $parts = parse_url($url);
        self::assertIsArray($parts);
        parse_str((string) ($parts['query'] ?? ''), $query);
        self::assertIsString($query['tenant'] ?? null);
        self::assertIsString($query['ref'] ?? null);
        self::assertIsString($query['expires'] ?? null);
        self::assertIsString($query['signature'] ?? null);

        return ['tenant' => $query['tenant'], 'ref' => $query['ref'], 'expires' => (int) $query['expires'], 'signature' => $query['signature']];
    }

    private function assertStatus(int $status, callable $call, string $label = ''): void
    {
        try {
            $call();
            self::fail($label . ' : HTTP ' . $status . ' attendu');
        } catch (HttpException $error) {
            self::assertSame($status, $error->status, $label);
        }
    }

    private function assertRefused(callable $call, string $label = ''): void
    {
        try {
            $call();
            self::fail($label . ' : refus attendu');
        } catch (InvalidArgumentException $error) {
            self::assertNotSame('', $error->getMessage(), $label);
        }
    }

    public function testLeContratEstLieEtComposeAttachmentStore(): void
    {
        self::assertInstanceOf(FileMediaStore::class, $this->store());

        $constructor = (new ReflectionClass(FileMediaStore::class))->getConstructor();
        self::assertNotNull($constructor);
        $types = array_map(static fn (\ReflectionParameter $p): string => (string) $p->getType(), $constructor->getParameters());
        self::assertContains(AttachmentStore::class, $types, 'composition sur AttachmentStore');
        self::assertNotContains('Scholaris\Modules\Socle\People\Contracts\FileScanner', $types, 'le contrôle du contenu n\'est pas réimplémenté');
        self::assertNotContains('Scholaris\Modules\Socle\People\Infrastructure\FileStorage', $types, 'le stockage n\'est pas réimplémenté');
    }

    public function testLeStoreDelegueAAttachmentStoreAvecUnDomaineImpose(): void
    {
        $spy = new class () implements AttachmentStore {
            /** @var list<array{string, string, string, int}> */
            public array $stored = [];

            public function store(string $tenantId, string $area, string $ownerId, string $content): StoredAttachment
            {
                $this->stored[] = [$tenantId, $area, $ownerId, strlen($content)];

                return new StoredAttachment($tenantId . '/' . $area . '/' . $ownerId . '/' . MediaStoreTest::ULID . '.jpg', 'image/jpeg', 'jpg', strlen($content));
            }

            public function read(string $tenantId, string $path): ?string
            {
                return null;
            }

            public function delete(string $tenantId, string $path): void
            {
            }
        };
        $store = new FileMediaStore($spy, $this->kernel->container()->get(UrlSigner::class), $this->clock, Config::fromArray([]));

        $ref = $store->put('t1', self::NS, self::OWNER, $this->jpeg());

        self::assertSame([['t1', 'media-public-nursery', 'EST1', 4 + 64]], $spy->stored);
        self::assertSame('public-nursery.EST1.' . self::ULID . '.jpg', $ref->value);
    }

    public function testPutRangeLeFichierSousUnEspaceDeNomsEtRendUneReferenceOpaque(): void
    {
        $ref = $this->store()->put('t1', self::NS, self::OWNER, $this->jpeg());

        $files = $this->files();
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('#^t1/media-public-nursery/EST1/[0-9A-Z]{26}\.jpg$#', $files[0]);
        self::assertMatchesRegularExpression('/^public-nursery\.EST1\.[0-9A-Z]{26}\.jpg$/', $ref->value);
        self::assertLessThanOrEqual(80, strlen($ref->value), 'tient dans photo_document_ref VARCHAR(80)');
        self::assertStringNotContainsString('t1', explode('.', $ref->value)[0], 'la référence ne contient pas le client');
        self::assertStringNotContainsString('/', $ref->value);
        self::assertSame($ref->value, (string) $ref);
    }

    public function testLEspaceDeNomsEstForceEtJamaisCelDesAutresDomaines(): void
    {
        $this->store()->put('t1', 'people', self::OWNER, $this->png());
        $this->store()->put('t1', 'higher-grades', self::OWNER, $this->png());

        $files = $this->files();
        self::assertCount(2, $files);
        foreach ($files as $file) {
            self::assertStringStartsWith('t1/media-', $file, 'tout média est rangé sous media-<espace> : jamais dans people/ ni dans le domaine d\'une autre application');
        }
        self::assertSame([], array_filter($files, static fn (string $f): bool => str_starts_with($f, 't1/people/') || str_starts_with($f, 't1/higher-grades/')));

        foreach (['../x', 'UP', 'a/b', '', 'x', 'media-tres-tres-long-espace-de-noms', 'avec espace', 'nom.point'] as $namespace) {
            $this->assertRefused(fn () => $this->store()->put('t1', $namespace, self::OWNER, $this->png()), 'espace « ' . $namespace . ' »');
        }
        foreach (['../x', 'a/b', '', 'a b', 'point.point', str_repeat('E', 27)] as $owner) {
            $this->assertRefused(fn () => $this->store()->put('t1', self::NS, $owner, $this->png()), 'propriétaire « ' . $owner . ' »');
        }
        self::assertCount(2, $this->files(), 'aucun fichier écrit pour un espace ou un propriétaire refusé');
    }

    public function testUnFichierActifOuDUnTypeNonAccepteEstRefuse(): void
    {
        $cases = [
            'jpeg avec script' => $this->jpeg('<script>alert(1)</script>'),
            'png avec php' => $this->png('<?php system($_GET[1]); ?>'),
            'pdf avec JavaScript' => $this->pdfContent('/JavaScript (app.alert(1))'),
            'pdf avec fichier embarqué' => $this->pdfContent('/EmbeddedFile'),
            'exécutable' => "MZ\x90\x00" . str_repeat('x', 64),
            'gif' => 'GIF89a' . str_repeat('g', 64),
            'texte' => 'bonjour',
            'vide' => '',
        ];
        foreach ($cases as $label => $content) {
            $this->assertRefused(fn () => $this->store()->put('t1', self::NS, self::OWNER, $content), $label);
        }

        self::assertSame([], $this->files(), 'rien n\'est stocké');
        $this->store()->put('t1', self::NS, self::OWNER, $this->pdfContent());
        self::assertCount(1, $this->files(), 'un PDF sans contenu actif reste accepté');
    }

    public function testTailleMaximaleParDefautDeDeuxMoEtPlafondDeCinqMo(): void
    {
        $limit = 2 * 1024 * 1024;
        $exact = $this->jpeg('', $limit - 4);
        self::assertSame($limit, strlen($exact));

        $this->store()->put('t1', self::NS, self::OWNER, $exact);
        $this->assertRefused(fn () => $this->store()->put('t1', self::NS, self::OWNER, $exact . 'x'), 'au-delà de 2 Mo');
        self::assertCount(1, $this->files());

        $custom = new FileMediaStore($this->kernel->container()->get(AttachmentStore::class), $this->kernel->container()->get(UrlSigner::class), $this->clock, Config::fromArray(['people' => ['media' => ['max_bytes' => 1000]]]));
        $custom->put('t1', self::NS, self::OWNER, $this->jpeg('', 996));
        $this->assertRefused(fn () => $custom->put('t1', self::NS, self::OWNER, $this->jpeg('', 997)), 'au-delà de people.media.max_bytes');

        $greedy = new FileMediaStore($this->kernel->container()->get(AttachmentStore::class), $this->kernel->container()->get(UrlSigner::class), $this->clock, Config::fromArray(['people' => ['media' => ['max_bytes' => 50 * 1024 * 1024]]]));
        $this->assertRefused(fn () => $greedy->put('t1', self::NS, self::OWNER, $this->jpeg('', AttachmentStore::MAX_BYTES)), 'le plafond de 5 Mo du socle reste imposé');
        self::assertCount(2, $this->files());
    }

    public function testUrlSigneeLisibleJusquAExpirationPuisRefusee(): void
    {
        $content = $this->jpeg('SYN-photo');
        $ref = $this->store()->put('t1', self::NS, self::OWNER, $content);

        $url = $this->store()->signedUrl('t1', $ref, 120, '/api/v1/public-nursery/media');
        $link = $this->link($url);
        self::assertStringStartsWith('/api/v1/public-nursery/media?', $url);
        self::assertSame(['t1', $ref->value, $this->clock->now() + 120], [$link['tenant'], $link['ref'], $link['expires']]);

        $media = $this->store()->readSigned('t1', $ref, $link['expires'], $link['signature']);
        self::assertInstanceOf(MediaContent::class, $media);
        self::assertSame([$content, 'image/jpeg', 'jpg', strlen($content)], [$media->content, $media->mime, $media->extension, $media->size]);

        $this->clock->advance(120);
        self::assertSame($content, $this->store()->readSigned('t1', $ref, $link['expires'], $link['signature'])->content, 'valable jusqu\'à l\'instant d\'expiration inclus');

        $this->clock->advance(1);
        $this->assertStatus(403, fn () => $this->store()->readSigned('t1', $ref, $link['expires'], $link['signature']), 'lien expiré');
    }

    public function testUrlFalsifieeOuDetourneeEstRefusee(): void
    {
        $one = $this->store()->put('t1', self::NS, self::OWNER, $this->jpeg('un'));
        $two = $this->store()->put('t1', self::NS, self::OWNER, $this->jpeg('deux'));
        $link = $this->link($this->store()->signedUrl('t1', $one, 60, '/m'));
        $forged = strrev($link['signature']);

        $this->assertStatus(403, fn () => $this->store()->readSigned('t1', $one, $link['expires'], $forged), 'signature falsifiée');
        $this->assertStatus(403, fn () => $this->store()->readSigned('t1', $one, $link['expires'], ''), 'signature vide');
        $this->assertStatus(403, fn () => $this->store()->readSigned('t1', $one, $link['expires'] + 3600, $link['signature']), 'expiration prolongée');
        $this->assertStatus(403, fn () => $this->store()->readSigned('t1', $two, $link['expires'], $link['signature']), 'signature d\'une autre pièce');
        $this->assertStatus(403, fn () => $this->store()->readSigned('t2', $one, $link['expires'], $link['signature']), 'signature d\'un autre client');

        $signer = $this->kernel->container()->get(UrlSigner::class);
        $personLink = $signer->sign('t1|' . $one->value, $link['expires']);
        $this->assertStatus(403, fn () => $this->store()->readSigned('t1', $one, $link['expires'], $personLink), 'signature fabriquée sans le sujet « media »');
        $this->assertStatus(403, fn () => $this->store()->readSigned('t1', $one, $link['expires'], $signer->sign($one->value . '|' . 'x', $link['expires'])), 'autre sujet');
    }

    public function testLaDureeDeLUrlEstBornee(): void
    {
        $ref = $this->store()->put('t1', self::NS, self::OWNER, $this->jpeg());

        $long = $this->link($this->store()->signedUrl('t1', $ref, 10_000_000, '/m'));
        $none = $this->link($this->store()->signedUrl('t1', $ref, 0, '/m'));
        $negative = $this->link($this->store()->signedUrl('t1', $ref, -5, '/m'));

        self::assertSame($this->clock->now() + MediaStore::MAX_TTL, $long['expires']);
        self::assertSame($this->clock->now() + 1, $none['expires']);
        self::assertSame($this->clock->now() + 1, $negative['expires']);
    }

    public function testLeChemindeLUrlEstValide(): void
    {
        $ref = $this->store()->put('t1', self::NS, self::OWNER, $this->jpeg());

        foreach (['', 'http://evil.test/m', '//evil.test/m', '/m?x=1', '/m#a', '/m b', "/m\n"] as $path) {
            $this->assertRefused(fn () => $this->store()->signedUrl('t1', $ref, 60, $path), 'chemin « ' . $path . ' »');
        }
    }

    public function testUnAutreClientNeTrouvePasLeMediaEtNePeutPasLeSupprimer(): void
    {
        $content = $this->jpeg('SYN-t1');
        $ref = $this->store()->put('t1', self::NS, self::OWNER, $content);

        $link = $this->link($this->store()->signedUrl('t2', $ref, 60, '/m'));
        $this->assertStatus(404, fn () => $this->store()->readSigned('t2', $ref, $link['expires'], $link['signature']), 'signature valide du client t2, média du client t1');

        $this->store()->delete('t2', $ref);
        self::assertCount(1, $this->files(), 'la suppression par un autre client n\'a aucun effet');
        $own = $this->link($this->store()->signedUrl('t1', $ref, 60, '/m'));
        self::assertSame($content, $this->store()->readSigned('t1', $ref, $own['expires'], $own['signature'])->content);
    }

    public function testSuppression(): void
    {
        $ref = $this->store()->put('t1', self::NS, self::OWNER, $this->png());
        $link = $this->link($this->store()->signedUrl('t1', $ref, 60, '/m'));

        $this->store()->delete('t1', $ref);
        $this->store()->delete('t1', $ref);

        self::assertSame([], $this->files());
        $this->assertStatus(404, fn () => $this->store()->readSigned('t1', $ref, $link['expires'], $link['signature']), 'média supprimé');
    }

    public function testUneReferenceMalFormeeOuUnCheminDeDossierEstRefuse(): void
    {
        self::assertSame(26, strlen(self::ULID));
        $valid = 'public-nursery.EST1.' . self::ULID . '.jpg';
        self::assertNotNull(MediaRef::tryFrom($valid));

        foreach (['', '../../etc/passwd', 'public-nursery.EST1.' . self::ULID . '.exe', 'Public.EST1.' . self::ULID . '.jpg', 'public-nursery.EST1/..%2f.' . self::ULID . '.jpg', 'public-nursery.EST1.short.jpg', 't1/media-public-nursery/EST1/' . self::ULID . '.jpg', $valid . "\n", ' ' . $valid] as $value) {
            self::assertNull(MediaRef::tryFrom($value), $value);
        }
        $this->expectException(InvalidArgumentException::class);
        new MediaRef('../../etc/passwd');
    }
}

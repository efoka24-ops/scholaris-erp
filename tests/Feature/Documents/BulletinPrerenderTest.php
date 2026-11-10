<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use Scholaris\Modules\Socle\Documents\Application\DocumentJobService;
use Scholaris\Modules\Socle\Documents\Console\ProcessDocumentJobsCommand;
use Scholaris\Modules\Socle\Documents\Infrastructure\PdfStore;
use Scholaris\Modules\Socle\Documents\Infrastructure\WorkerLauncher;
use Tests\Support\Documents\DocumentsTestCase;

/**
 * NF-S2 en production (C-118) : bulletins rendus en profil léger (documents.batch.light_profile, défaut vrai), PDF pré-rendus
 * par la file (documents:process-jobs) et servis depuis le stockage, plusieurs processus qui se partagent la file par verrou.
 */
final class BulletinPrerenderTest extends DocumentsTestCase
{
    /** Marque du profil léger dans la sortie du rendu simulé (feuille de style du profil, texte conservé par strip_tags). */
    private const LIGHT_MARK = 'body{font-family:Helvetica,sans-serif;font-size:11px';

    private string $store;
    private WorkerLauncher&\Countable $launcher;

    protected function setUp(): void
    {
        $this->boot2();
    }

    protected function tearDown(): void
    {
        self::rmdir($this->store);
    }

    /** @param array<string, mixed> $documents */
    private function boot2(array $documents = [], bool $launcherAvailable = true): void
    {
        $this->store = sys_get_temp_dir() . '/scholaris-pdf-' . bin2hex(random_bytes(4));
        $this->launcher = new class ($launcherAvailable) implements WorkerLauncher, \Countable {
            /** @var list<list<string>> */
            public array $calls = [];

            public function __construct(private readonly bool $available)
            {
            }

            public function launch(array $arguments): bool
            {
                if (!$this->available) {
                    return false;
                }
                $this->calls[] = $arguments;

                return true;
            }

            public function count(): int
            {
                return count($this->calls);
            }
        };
        $this->bootDocuments([WorkerLauncher::class => $this->launcher], ['documents' => array_replace_recursive(['pdf_store_path' => $this->store, 'worker_lock_path' => $this->store . '/locks'], $documents)]);
        $this->api('POST', '/document-types', 'alice', 't1', 'e1', ['code' => 'bulletin', 'label' => 'Bulletin', 'category' => 'bulletin', 'type_letter' => 'B', 'variables' => ['moyenne']]);
        $tpl = $this->api('POST', '/document-templates', 'alice', 't1', 'e1', ['type_code' => 'bulletin', 'locale' => 'fr', 'html' => '<p>{{holder_name}} {{moyenne}}</p>'])[1]['data'];
        $this->api('POST', '/document-templates/' . $tpl['id'] . '/validate', 'alice', 't1');
    }

    private function bulletin(string $subject, string $key): string
    {
        return (string) $this->issueViaApi($subject, 'bulletin', $key, ['moyenne' => '14,25'])['id'];
    }

    private function preview(string $id, string $query = ''): string
    {
        [$response] = $this->api('GET', '/documents/' . $id . '/preview' . $query, 'alice', 't1');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (string) $response->getBody();
    }

    private function jobs(): DocumentJobService
    {
        return $this->kernel->container()->get(DocumentJobService::class);
    }

    public function testLeBulletinEstRenduEnProfilLegerParDefautEtLAttestationEnStandard(): void
    {
        $bulletin = $this->bulletin('p1', 'cle-bulletin-0001');
        $attestation = (string) $this->issueViaApi('p2')['id'];

        self::assertStringContainsString(self::LIGHT_MARK, $this->preview($bulletin));
        self::assertStringNotContainsString(self::LIGHT_MARK, $this->preview($bulletin, '?profile=standard'), 'Profil explicite respecté.');
        self::assertStringNotContainsString(self::LIGHT_MARK, $this->preview($attestation));
        $pdf = $this->kernel->container()->get(\Scholaris\Modules\Socle\Documents\Contracts\DocumentPdfReader::class)->pdfOf('t1', 'e1', $bulletin);
        self::assertStringContainsString(self::LIGHT_MARK, (string) $pdf, 'Portail (pdfOf) : profil léger.');
    }

    public function testLeParametreDesactiveLeProfilLeger(): void
    {
        $this->boot2(['batch' => ['light_profile' => false]]);
        $bulletin = $this->bulletin('p1', 'cle-bulletin-0002');

        self::assertStringNotContainsString(self::LIGHT_MARK, $this->preview($bulletin));
    }

    public function testLaFilePreRendLesBulletinsPuisLesSertDepuisLeStockage(): void
    {
        $ids = [$this->bulletin('p1', 'cle-bulletin-0003'), $this->bulletin('p2', 'cle-bulletin-0004')];
        $attestation = (string) $this->issueViaApi('p3')['id'];

        $stats = $this->jobs()->drain(10, null, 60);

        self::assertSame(2, $stats['rendered']);
        $pdo = $this->connections()->pdo();
        self::assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM documents WHERE pdf_rendered_at IS NOT NULL AND pdf_profile = 'light'")->fetchColumn());
        self::assertNull($pdo->query("SELECT pdf_rendered_at FROM documents WHERE id = '" . $attestation . "'")->fetchColumn() ?: null, 'Seuls les bulletins sont pré-rendus.');
        $store = $this->kernel->container()->get(PdfStore::class);
        self::assertStringContainsString(self::LIGHT_MARK, (string) $store->get('t1', $ids[0], 'light'));
        // Le fichier stocké est servi tel quel (aucun nouveau rendu).
        $store->put('t1', $ids[0], 'light', "%PDF-1.4\nSTOCKE\n%%EOF\n");
        self::assertStringContainsString('STOCKE', $this->preview($ids[0]));
        self::assertSame(0, $this->jobs()->drain(10, null, 60)['rendered'], 'Rien à refaire.');
    }

    public function testDeuxProcessusNeRendentPasLeMemeBulletin(): void
    {
        $ids = [$this->bulletin('p1', 'cle-bulletin-0005'), $this->bulletin('p2', 'cle-bulletin-0006')];
        $pdo = $this->connections()->pdo();
        // Un autre processus vient de prendre le premier bulletin (verrou récent) : il n'est pas rendu une seconde fois.
        $pdo->exec("UPDATE documents SET pdf_claimed_at = '2999-01-01T00:00:00+01:00' WHERE id = '" . $ids[0] . "'");

        self::assertSame(1, $this->jobs()->drain(10, null, 60)['rendered']);
        self::assertNull($pdo->query("SELECT pdf_rendered_at FROM documents WHERE id = '" . $ids[0] . "'")->fetchColumn() ?: null);
        // Verrou abandonné (processus arrêté) : repris au passage suivant.
        $pdo->exec("UPDATE documents SET pdf_claimed_at = '2000-01-01T00:00:00+01:00' WHERE id = '" . $ids[0] . "'");
        self::assertSame(1, $this->jobs()->drain(10, null, 60)['rendered']);
    }

    public function testLaCommandeBoucleJusquAViderLaFileDansLaLimiteDuTemps(): void
    {
        foreach (['x1', 'x2'] as $p) {
            $this->people->add('t1', $p, 'Prénom', 'Nom');
            $this->api('POST', '/document-batches', 'alice', 't1', 'e1', ['type' => 'attestation_simple', 'items' => [['subject_person_id' => $p, 'data' => ['academic_year' => '2026-2027', 'class_name' => '3eA']]]]);
        }
        $command = $this->kernel->container()->get(ProcessDocumentJobsCommand::class);

        ob_start();
        $code = $command->run(['--max-jobs=1', '--max-seconds=60']);
        $out = (string) ob_get_clean();

        self::assertSame(0, $code, $out);
        self::assertSame(0, (int) $this->connections()->pdo()->query("SELECT COUNT(*) FROM document_jobs WHERE status <> 'done'")->fetchColumn(), $out);
    }

    public function testLaCommandeLanceNProcessusEnArrierePlan(): void
    {
        $command = $this->kernel->container()->get(ProcessDocumentJobsCommand::class);

        ob_start();
        $code = $command->run(['--workers=3', '--max-seconds=840']);
        $out = (string) ob_get_clean();

        self::assertSame(0, $code);
        self::assertSame([
            ['documents:process-jobs', '--slot=1', '--max-seconds=840'],
            ['documents:process-jobs', '--slot=2', '--max-seconds=840'],
            ['documents:process-jobs', '--slot=3', '--max-seconds=840'],
        ], $this->launcher->calls);
        self::assertStringContainsString('3 processus', $out);
    }

    public function testSansLancementPossibleLaCommandeTraiteElleMeme(): void
    {
        $this->boot2([], false);
        $this->bulletin('p1', 'cle-bulletin-0007');
        $command = $this->kernel->container()->get(ProcessDocumentJobsCommand::class);

        ob_start();
        $code = $command->run(['--workers=4', '--max-seconds=60']);
        $out = (string) ob_get_clean();

        self::assertSame(0, $code, $out);
        self::assertSame([], $this->launcher->calls);
        self::assertStringContainsString('1 bulletin(s) pré-rendu(s)', $out);
    }

    public function testUnCreneauOccupeNeLancePasDeSecondProcessus(): void
    {
        $command = $this->kernel->container()->get(ProcessDocumentJobsCommand::class);
        mkdir($this->store . '/locks', 0o777, true);
        $held = fopen($this->store . '/locks/documents-worker-2.lock', 'c');
        self::assertNotFalse($held);
        self::assertTrue(flock($held, LOCK_EX | LOCK_NB));

        ob_start();
        $code = $command->run(['--slot=2', '--max-seconds=60']);
        $out = (string) ob_get_clean();
        flock($held, LOCK_UN);
        fclose($held);

        self::assertSame(0, $code);
        self::assertStringContainsString('Créneau 2 déjà occupé', $out);
    }

    private static function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

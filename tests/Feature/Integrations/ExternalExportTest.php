<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use InvalidArgumentException;
use Scholaris\Core\Config\Config;
use Scholaris\Modules\Socle\Integrations\Application\CartescolaireFileExporter;
use Scholaris\Modules\Socle\Integrations\Application\ExportFileWriter;
use Scholaris\Modules\Socle\Integrations\Console\SungkuCheckCommand;
use Scholaris\Modules\Socle\Integrations\Contracts\CartescolaireExporter;
use Scholaris\Modules\Socle\Integrations\Contracts\TutelleExporter;
use Tests\Support\AppTestCase;

/** T138 : exports fichier génériques (cartescolaire.cm, tutelle) et contrôle de configuration Sungku. */
final class ExternalExportTest extends AppTestCase
{
    protected function setUp(): void
    {
        $this->boot([], ['integrations' => require dirname(__DIR__, 3) . '/config/integrations.php']);
        $pdo = $this->connections()->pdo();
        $now = '2026-10-08T09:00:00+01:00';
        foreach ([['t1', 'e1', 'Collège Beta'], ['t2', 'e9', 'Autre client']] as [$tenant, $id, $name]) {
            $pdo->prepare('INSERT INTO establishments (id, tenant_id, name, type, branding, modules_enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $tenant, $name, 'secondary', '{}', '[]', $now, $now]);
        }
        foreach ([['P1', 't1', 'Zoé', 'Abena', '=HYPERLINK("http://x")'], ['P2', 't1', 'Marc', 'Biya', null], ['P3', 't2', 'Intrus', 'Autre', null]] as [$id, $tenant, $first, $last, $phone]) {
            $pdo->prepare('INSERT INTO people (id, tenant_id, first_name, last_name, phone, created_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $tenant, $first, $last, $phone, $now]);
            $pdo->prepare('INSERT INTO enrollments (id, tenant_id, person_id, establishment_id, status, internal_number, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(['N' . $id, $tenant, $id, $tenant === 't1' ? 'e1' : 'e9', 'active', 'MAT-' . $id, $now, $now]);
        }
        $pdo->prepare('INSERT INTO staff_members (id, tenant_id, person_id, home_establishment_id, kind, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['S1', 't1', 'P2', 'e1', 'teacher', 'active', $now, $now]);
    }

    private function target(string $extension): string
    {
        return sys_get_temp_dir() . '/scholaris-ext-' . bin2hex(random_bytes(4)) . '.' . $extension;
    }

    public function testCartescolaireEcritUnCsvGeneriqueLimiteAuClient(): void
    {
        $path = $this->target('csv');
        $report = $this->kernel->container()->get(CartescolaireExporter::class)->export('t1', 'e1', $path);
        $csv = (string) file_get_contents($path);
        unlink($path);

        self::assertSame(2, $report->rows);
        self::assertSame('csv', $report->format);
        self::assertStringStartsWith("\xEF\xBB\xBFmatricule;nom;prenom;date_naissance;classe;annee;etablissement", $csv);
        self::assertStringContainsString('MAT-P1;Abena;Zoé', $csv);
        self::assertStringContainsString('Collège Beta', $csv);
        self::assertStringNotContainsString('Autre', $csv);
        self::assertStringNotContainsString('MAT-P3', $csv);
    }

    public function testTutelleDonneLesEffectifsEnJson(): void
    {
        $config = Config::fromArray(['integrations' => ['tutelle' => ['format' => 'json', 'columns' => ['school' => 'establishment_name', 'learners' => 'enrolled_learners', 'staff' => 'teachers']]]]);
        $exporter = new \Scholaris\Modules\Socle\Integrations\Application\TutelleFileExporter(
            $config,
            new ExportFileWriter(),
            $this->kernel->container()->get(\Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory::class),
            $this->kernel->container()->get(\Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory::class),
            $this->kernel->container()->get(\Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory::class),
        );
        $path = $this->target('json');
        $exporter->export('t1', 'e1', $path);
        $json = json_decode((string) file_get_contents($path), true);
        unlink($path);

        self::assertSame([['school' => 'Collège Beta', 'learners' => 2, 'staff' => 1]], $json);
        // Établissement d'un autre client : aucune ligne.
        $path = $this->target('json');
        self::assertSame(0, $exporter->export('t1', 'e9', $path)->rows);
        unlink($path);
        self::assertInstanceOf(TutelleExporter::class, $this->kernel->container()->get(TutelleExporter::class));
    }

    public function testLesFormulesSontNeutraliseesEtUnChampInconnuEstRefuse(): void
    {
        $writer = new ExportFileWriter();
        $path = $this->target('csv');
        $writer->write($path, 'csv', ';', ['tel' => 'phone'], [['phone' => '=1+1']], ['phone']);
        self::assertStringContainsString("'=1+1", (string) file_get_contents($path));
        unlink($path);

        $this->expectException(InvalidArgumentException::class);
        $writer->write($path, 'csv', ';', ['x' => 'password_hash'], [], CartescolaireFileExporter::FIELDS);
    }

    public function testSungkuCheckSignaleLesEcartsSansAfficherLesSecrets(): void
    {
        $incomplete = new SungkuCheckCommand(Config::fromArray(['sungku' => ['enabled' => false, 'base_url' => 'http://sungku.test', 'api_key' => '', 'webhook_secret' => 'court'], 'app' => ['url' => 'http://localhost:8000', 'env' => 'local']]));
        ob_start();
        $code = $incomplete->run();
        $out = (string) ob_get_clean();
        self::assertSame(1, $code);
        self::assertStringContainsString('ÉCART', $out);
        self::assertStringNotContainsString('court', str_replace('trop court', '', $out));

        $secret = str_repeat('s', 40);
        $complete = new SungkuCheckCommand(Config::fromArray(['sungku' => ['enabled' => false, 'base_url' => 'https://sungku.example', 'api_key' => 'cle-api-secrete', 'webhook_secret' => $secret, 'webhook_tolerance_seconds' => 600], 'app' => ['url' => 'https://scholaris.example.cm', 'env' => 'production']]));
        ob_start();
        $code = $complete->run();
        $out = (string) ob_get_clean();
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('https://scholaris.example.cm/api/v1/webhooks/payments/sungku', $out);
        self::assertStringNotContainsString($secret, $out);
        self::assertStringNotContainsString('cle-api-secrete', $out);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use Scholaris\Modules\Socle\Documents\Domain\TemplateGuard;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentLogo;
use Scholaris\Modules\Socle\Organisation\Contracts\LogoImage;
use Tests\Support\Documents\DocumentsTestCase;

/** Fragment sûr {{logo}} du moteur et nouvelles versions des modèles de la plateforme (migration 202611051101). */
final class LogoDocumentsTest extends DocumentsTestCase
{
    private const WITH_LOGO = [
        'pri_report_card', 'pri_annual_report', 'pp_doc08_unit_report', 'pp_doc09_trimester_report', 'pp_doc10_annual_booklet',
        'secondary_report_trimester', 'secondary_report_annual',
        'sec_doc01_preregistration', 'sec_doc02_enrolment_form', 'sec_doc03_school_certificate', 'sec_doc04_school_card',
        'sec_doc05_exeat', 'sec_doc06_class_list', 'sec_doc28_account_statement', 'sec_doc29_payment_attestation',
    ];

    private object $holder;

    protected function setUp(): void
    {
        $holder = new class () implements EstablishmentLogo {
            public ?LogoImage $image = null;

            public function find(string $tenantId, string $establishmentId): ?LogoImage
            {
                return $establishmentId === 'e1' ? $this->image : null;
            }
        };
        $this->bootDocuments([EstablishmentLogo::class => $holder]);
        $this->holder = $holder;
    }

    private function png(int $width, int $height): LogoImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 10, 100, 50));
        ob_start();
        imagepng($image);

        return new LogoImage('image/png', (string) ob_get_clean(), $width, $height);
    }

    private function template(): void
    {
        [, $json] = $this->api('POST', '/document-templates', 'alice', 't1', 'e1', [
            'type_code' => 'attestation_simple', 'locale' => 'fr',
            'html' => '<html><body><div align="center">{{logo}}</div><h1>{{establishment_name}}</h1><p>{{holder_name}} {{academic_year}} {{class_name}}</p></body></html>',
        ]);
        $this->api('POST', '/document-templates/' . $json['data']['id'] . '/validate', 'alice', 't1');
    }

    private function content(string $id): string
    {
        return (string) $this->connections()->pdo()->query("SELECT content FROM documents WHERE id = '" . $id . "'")->fetchColumn();
    }

    public function testLeLogoDeLEtablissementEstIntegreAuDocumentDansUnCadreDe120Sur70(): void
    {
        $this->template();
        $this->holder->image = $this->png(300, 100);

        $content = $this->content($this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p1', ['academic_year' => '2026-2027', 'class_name' => '3eA'], 'alice')->id);

        self::assertStringContainsString('<img class="logo" src="data:image/png;base64,', $content);
        self::assertStringContainsString('width="120" height="40"', $content, '300 x 100 inscrit dans 120 x 70 : ratio conservé.');
        self::assertStringNotContainsString('{{logo}}', $content);
    }

    public function testUnLogoHautEstLimiteEnHauteurEtUnPetitLogoNEstPasAgrandi(): void
    {
        $this->template();
        $this->holder->image = $this->png(100, 200);
        $tall = $this->content($this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p1', ['academic_year' => '2026-2027', 'class_name' => '3eA'], 'alice')->id);
        self::assertStringContainsString('width="35" height="70"', $tall);

        $this->holder->image = $this->png(60, 30);
        $small = $this->content($this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p2', ['academic_year' => '2026-2027', 'class_name' => '3eA'], 'alice')->id);
        self::assertStringContainsString('width="60" height="30"', $small);
    }

    public function testSansLogoLeFragmentEstVide(): void
    {
        $this->template();

        $content = $this->content($this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p1', ['academic_year' => '2026-2027', 'class_name' => '3eA'], 'alice')->id);
        self::assertStringNotContainsString('<img', $content);
        self::assertStringContainsString('<div align="center"></div><h1>Collège La Palmeraie</h1>', $content);

        $this->holder->image = $this->png(100, 50);
        $other = $this->content($this->issuer()->issue('t1', 'e2', 'attestation_simple', 'p1', ['academic_year' => '2026-2027', 'class_name' => '3eA'], 'alice')->id);
        self::assertStringNotContainsString('<img', $other, 'Le logo de e1 ne sert pas à e2.');
    }

    public function testLeDocumentEmisGardeSonLogoSiLEtablissementLeChangeEnsuite(): void
    {
        $this->template();
        $this->holder->image = $this->png(100, 50);
        $id = $this->issuer()->issue('t1', 'e1', 'attestation_simple', 'p1', ['academic_year' => '2026-2027', 'class_name' => '3eA'], 'alice')->id;
        $before = $this->content($id);

        $this->holder->image = null;

        self::assertSame($before, $this->content($id));
        self::assertStringContainsString('<img class="logo"', $this->content($id));
    }

    public function testLogoEstUneVariableReserveeDuMoteur(): void
    {
        [$response] = $this->api('POST', '/document-types', 'alice', 't1', 'e1', ['code' => 'essai_logo', 'label' => 'Essai', 'category' => 'other', 'type_letter' => 'E', 'variables' => ['logo']]);

        self::assertSame(422, $response->getStatusCode(), 'Un type ne peut pas déclarer la variable {{logo}} : elle est fournie par le moteur.');
        self::assertContains('logo', TemplateGuard::SYSTEM);
    }

    public function testLesModelesDeLaPlateformeOntUneNouvelleVersionAvecLeLogoAuCentreDeLEnTete(): void
    {
        $pdo = $this->connections()->pdo();
        $problems = [];
        foreach (self::WITH_LOGO as $type) {
            foreach (['fr', 'en'] as $locale) {
                $statement = $pdo->prepare("SELECT version, html FROM document_templates WHERE tenant_id = '*' AND type_code = ? AND locale = ? ORDER BY version DESC");
                $statement->execute([$type, $locale]);
                $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
                if (count($rows) < 2) {
                    $problems[] = $type . ' ' . $locale . ' : une seule version';
                    continue;
                }
                [$latest, $previous] = $rows;
                if (substr_count((string) $latest['html'], '{{logo}}') !== 1) {
                    $problems[] = $type . ' ' . $locale . ' : {{logo}} absent ou en double';
                }
                if (strpos((string) $latest['html'], '{{logo}}') > strpos((string) $latest['html'], '{{establishment_name}}')) {
                    $problems[] = $type . ' ' . $locale . ' : le logo doit précéder le nom de l\'établissement';
                }
                if (str_contains((string) $previous['html'], '{{logo}}') || (int) $latest['version'] !== (int) $previous['version'] + 1) {
                    $problems[] = $type . ' ' . $locale . ' : la version précédente doit être conservée telle quelle';
                }
                $declared = $pdo->prepare("SELECT variables FROM document_types WHERE tenant_id = '*' AND code = ?");
                $declared->execute([$type]);
                TemplateGuard::assertValid((string) $latest['html'], (array) json_decode((string) $declared->fetchColumn(), true));
            }
        }

        self::assertSame([], $problems);
    }

    public function testLeContenuDeLaNouvelleVersionNeChangeQueParLeLogo(): void
    {
        $pdo = $this->connections()->pdo();
        $rows = $pdo->query("SELECT type_code, locale, version, html FROM document_templates WHERE tenant_id = '*' AND type_code IN ('sec_doc03_school_certificate', 'pri_report_card') ORDER BY type_code, locale, version")->fetchAll(\PDO::FETCH_ASSOC);
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['type_code'] . $row['locale']][] = $row['html'];
        }
        foreach ($byKey as $versions) {
            [$previous, $latest] = [$versions[count($versions) - 2], $versions[count($versions) - 1]];
            self::assertSame($previous, str_replace(['<div align="center">{{logo}}</div>', '{{logo}}'], '', $latest));
        }
    }
}

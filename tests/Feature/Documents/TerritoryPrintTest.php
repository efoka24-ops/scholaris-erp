<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use Scholaris\Modules\Socle\Documents\Domain\LightPdfProfile;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryInfo;
use Tests\Support\Documents\DocumentsTestCase;

/** PP15 : lot d'impression multi-établissements d'un territoire (A : e1 et e2 ; V : vide) et profil PDF léger. */
final class TerritoryPrintTest extends DocumentsTestCase
{
    protected function setUp(): void
    {
        $this->bootDocuments([TerritoryDirectory::class => new class () implements TerritoryDirectory {
            private const MEMBERS = ['A' => ['e1', 'e2'], 'V' => []];

            public function find(string $tenantId, string $territoryId, string $at): ?TerritoryInfo
            {
                return $tenantId === 't1' && isset(self::MEMBERS[$territoryId])
                    ? new TerritoryInfo('t1', $territoryId, 'arrondissement', $territoryId, $territoryId, null, '2026-01-01', null, '1', $territoryId)
                    : null;
            }

            public function lineage(string $tenantId, string $territoryId, string $at): array
            {
                $info = $this->find($tenantId, $territoryId, $at);

                return $info === null ? [] : [$info];
            }

            public function descendantsOf(string $tenantId, string $territoryId, string $at): array
            {
                return $this->lineage($tenantId, $territoryId, $at);
            }

            public function territoryOf(string $tenantId, string $establishmentId, string $at): ?TerritoryInfo
            {
                return null;
            }

            public function establishmentsIn(string $tenantId, string $territoryId, string $at): array
            {
                return $this->find($tenantId, $territoryId, $at) === null ? [] : self::MEMBERS[$territoryId];
            }
        }]);
    }

    public function testLeLotReunitLesDocumentsValidesDesEcolesDuTerritoire(): void
    {
        $first = $this->issueViaApi('p1', key: 'cle-emission-0001');
        $this->issueViaApi('p2', key: 'cle-emission-0002');
        [$response] = $this->api('POST', '/documents', 'alice', 't1', 'e2', ['type' => 'attestation_simple', 'subject_person_id' => 'p3', 'data' => ['academic_year' => '2026-2027', 'class_name' => 'CM2']], ['Idempotency-Key' => 'cle-emission-0003']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->api('POST', '/documents/' . $first['id'] . '/revoke', 'alice', 't1', 'e1', ['reason' => 'Erreur de saisie']);

        [$response] = $this->api('GET', '/document-batches/territory-print?territory_id=A&type=attestation_simple', 'alice', 't1', null);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        self::assertSame('2', $response->getHeaderLine('X-Document-Count'), 'document révoqué exclu, deux écoles réunies');
        self::assertSame(1, (int) $this->connections()->pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'document.territory_printed'")->fetchColumn());
    }

    public function testPerimetreEtParametres(): void
    {
        $this->issueViaApi();

        $this->permissions->denyIn('alice', 'e2');
        self::assertSame(403, $this->api('GET', '/document-batches/territory-print?territory_id=A&type=attestation_simple', 'alice', 't1', null)[0]->getStatusCode(), 'une école du territoire hors périmètre');
        self::assertSame(403, $this->api('GET', '/document-batches/territory-print?territory_id=V&type=attestation_simple', 'bob', 't1', null)[0]->getStatusCode());
        self::assertSame(404, $this->api('GET', '/document-batches/territory-print?territory_id=A&type=attestation_simple', 'bob', 't2', null)[0]->getStatusCode(), 'territoire d\'un autre client');
        self::assertSame(422, $this->api('GET', '/document-batches/territory-print?territory_id=A', 'bob', 't1', null)[0]->getStatusCode());
        self::assertSame(422, $this->api('GET', '/document-batches/territory-print?territory_id=A&type=attestation_simple&profile=lourd', 'bob', 't1', null)[0]->getStatusCode());
        self::assertSame(422, $this->api('GET', '/documents/x/preview?profile=lourd', 'alice', 't1', 'e1')[0]->getStatusCode());
    }

    public function testLeProfilLegerRetireLesGrandesImagesEtLesPolicesEmbarquees(): void
    {
        $big = 'data:image/png;base64,' . str_repeat('A', 60000);
        $small = 'data:image/svg+xml;base64,' . str_repeat('B', 500);
        $html = '<html><head><link rel="stylesheet" href="x.css"><style>@font-face{font-family:X;src:url(x.ttf)}body{font-family:"DejaVu Sans",serif}</style></head>'
            . '<body><p style="font-family:\'DejaVu Sans\';color:red" class="a">Texte</p><img src="' . $big . '" alt="Logo"><img src="' . $small . '" alt="QR"></body></html>';

        $light = LightPdfProfile::apply($html);

        self::assertStringNotContainsString($big, $light);
        self::assertStringContainsString('<span class="img-alt">Logo</span>', $light);
        self::assertStringContainsString($small, $light, 'le code QR reste');
        self::assertStringNotContainsString('DejaVu', $light);
        self::assertStringNotContainsString('@font-face', $light);
        self::assertStringNotContainsString('<link', $light);
        self::assertStringContainsString('style="font-family:Helvetica,sans-serif;color:red" class="a"', $light);
    }

    public function testUnBulletinAuProfilLegerPeseMoinsDe200Ko(): void
    {
        $rows = '';
        for ($i = 1; $i <= 12; ++$i) {
            $rows .= '<tr><td>Compétence ' . $i . ' — lecture, écriture et calcul</td><td>Acquis</td><td>Très bien, continue ainsi.</td></tr>';
        }
        $logo = 'data:image/png;base64,' . base64_encode(random_bytes(150000));
        $html = '<html><body><img src="' . $logo . '" alt="Armoiries"><h1>République du Cameroun — École publique de Garoua</h1>'
            . '<h2>Bulletin du premier trimestre</h2><table>' . $rows . '</table><p>Appréciation : élève appliquée.</p></body></html>';
        $renderer = new \Scholaris\Modules\Socle\Documents\Infrastructure\DompdfRenderer(sys_get_temp_dir() . '/scholaris-dompdf-test');

        $pdf = $renderer->render(LightPdfProfile::apply(LightPdfProfile::STYLE . $html));

        self::assertStringStartsWith('%PDF', $pdf);
        self::assertLessThan(200 * 1024, strlen($pdf), 'SC-08 : PDF parent < 200 Ko');
    }
}

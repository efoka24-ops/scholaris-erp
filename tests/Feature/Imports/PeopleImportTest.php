<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Http\XlsxWriter;
use Scholaris\Modules\Socle\Identity\Application\TwoFactorPolicy;
use Tests\Support\Identity\IdentityTestCase;

/** T-001 : import en masse des acteurs (données fictives). */
final class PeopleImportTest extends IdentityTestCase
{
    private const HEAD = "nom;prénoms;e-mail;téléphone;langue;rôle;périmètre;code du périmètre;date de début;date de fin;note\n";
    private string $director = '';
    private string $unit = '';

    protected function setUp(): void
    {
        $this->boot([TwoFactorPolicy::class => new TwoFactorPolicy(Config::fromArray(['app' => ['two_factor_required' => false]]))]);
        $this->directory->add('t1', 'e1', 'Collège Alpha', 'secondary');
        $this->directory->add('t1', 'e2', 'Collège Beta', 'secondary');
        $this->directory->add('t2', 'f1', 'Lycée Gamma', 'secondary');
        $this->createUser('t1', 'Diane', '+237691000010', [['directeur', 'establishment', 'e1']], 'diane@ecole.test');
        $this->createUser('t1', 'Denis', '+237691000011', [['directeur', 'establishment', 'e2']], 'denis@ecole.test');
        $this->createUser('t1', 'Tina', '+237691000012', [['enseignant', 'establishment', 'e1']], 'tina@ecole.test');
        $this->createUser('t2', 'Gaston', '+237691000090', [['directeur', 'establishment', 'f1']], 'gaston@lycee.test');
        $this->director = $this->token('diane@ecole.test');
        $this->unit = '01JTESTUNIT00000000000001';
        $this->pdo()->prepare('INSERT INTO org_units (id, tenant_id, establishment_id, parent_id, type, name, created_at, updated_at) VALUES (?, ?, ?, NULL, ?, ?, ?, ?)')
            ->execute([$this->unit, 't1', 'e1', 'school', 'Section A', '2026-10-01T00:00:00+01:00', '2026-10-01T00:00:00+01:00']);
    }

    private function upload(string $content, ?string $token = null, string $est = 'e1'): ResponseInterface
    {
        $request = (new ServerRequest('POST', '/api/v1/imports/people', $this->auth($token ?? $this->director, $est)))
            ->withUploadedFiles(['file' => new UploadedFile(Stream::create($content), strlen($content), UPLOAD_ERR_OK, 'acteurs.csv', 'text/csv')]);

        return $this->kernel->handle($request);
    }

    /** @return array<string, mixed> */
    private function preview(string $csv): array
    {
        $response = $this->upload($csv);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    /** @return array<string, string> */
    private function h(string $token, string $est = 'e1'): array
    {
        return $this->auth($token, $est);
    }

    public function testLeModeleSeTelechargeEnCsvEtEnXlsxEtExigeLaPermission(): void
    {
        $csv = $this->call('GET', '/api/v1/imports/people/template', $this->h($this->director));
        self::assertSame(200, $csv->getStatusCode());
        self::assertStringContainsString('prénoms', (string) $csv->getBody());
        self::assertStringContainsString('code du périmètre', (string) $csv->getBody());
        $xlsx = $this->call('GET', '/api/v1/imports/people/template?format=xlsx', $this->h($this->director));
        self::assertStringStartsWith('PK', (string) $xlsx->getBody());
        self::assertSame(403, $this->call('GET', '/api/v1/imports/people/template', $this->h($this->token('tina@ecole.test')))->getStatusCode());
        self::assertSame(401, $this->call('GET', '/api/v1/imports/people/template', ['X-Establishment-Id' => 'e1'])->getStatusCode());
    }

    public function testLAnalyseNeCreeRienEtSignaleChaqueLigne(): void
    {
        $users = (int) $this->scalar('SELECT COUNT(*) FROM users');
        $csv = self::HEAD
            . "Mvondo;Alice;alice@ecole.test;691 00 00 21;fr;enseignant;;;2026-11-02;;\n"
            . "Bello;;bello@ecole.test;;fr;enseignant;;;;;\n"
            . "Etoa;Marc;marc@ecole.test;;fr;chef_inconnu;;;;;\n"
            . "Fouda;Luc;tina@ecole.test;;fr;enseignant;;;;;\n"
            . "Zang;Eve;alice@ecole.test;;fr;enseignant;;;;;\n"
            . "Nkoa;Paul;;;fr;enseignant;;;;;\n"
            . "Ondoa;Rose;rose@ecole.test;;fr;higher_scolarite;;;;;\n"
            . "Biya;Jean;jean@ecole.test;;fr;super_admin;;;;;\n"
            . 'Ewane;Anne;anne@ecole.test;;fr;comptable;unité;' . $this->unit . ";;;\n"
            . "Kamga;Ida;ida@ecole.test;;fr;comptable;unité;INCONNU;;;\n"
            . "Jury;Tom;tom@ecole.test;;fr;comptable;;;;;EXEMPLE : supprimez\n";
        $data = $this->preview($csv);

        self::assertSame($users, (int) $this->scalar('SELECT COUNT(*) FROM users'), 'Aucune écriture dans Identity.');
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM role_assignments WHERE role = ?', ['comptable']));
        $lines = array_column($data['data']['lines'], null, 'line');
        self::assertSame('valid', $lines[2]['status']);
        self::assertSame('+237691000021', $lines[2]['phone']);
        foreach ([3, 4, 7, 8, 9, 11, 12] as $line) {
            self::assertSame('error', $lines[$line]['status'], 'ligne ' . $line);
        }
        self::assertSame('duplicate', $lines[5]['status'], 'E-mail déjà utilisé par un compte.');
        self::assertSame('duplicate', $lines[6]['status'], 'Même e-mail que la ligne 2.');
        self::assertSame('valid', $lines[10]['status']);
        self::assertSame('org_unit', $lines[10]['scope_type']);
        self::assertSame(['total' => 11, 'valid' => 2, 'error' => 7, 'duplicate' => 2, 'applied' => 0, 'partial' => 0, 'failed' => 0], $data['data']['summary']);
        self::assertArrayHasKey('allowed_roles', $data['meta']);

        $id = $data['data']['id'];
        $errors = $this->call('GET', '/api/v1/imports/people/' . $id . '/errors.csv', $this->h($this->director));
        self::assertSame(200, $errors->getStatusCode());
        self::assertStringContainsString('Etoa', (string) $errors->getBody());
        self::assertStringNotContainsString('Mvondo', (string) $errors->getBody());
        $filtered = $this->json($this->call('GET', '/api/v1/imports/people/' . $id . '?status=duplicate', $this->h($this->director)));
        self::assertCount(2, $filtered['data']['lines']);
        self::assertSame(422, $this->call('GET', '/api/v1/imports/people/' . $id . '?status=zz', $this->h($this->director))->getStatusCode());
        $audit = (string) json_encode($this->rows('SELECT * FROM audit_log WHERE action = ?', ['imports.people.previewed']));
        self::assertStringContainsString('imports.people.previewed', $audit);
        self::assertStringNotContainsString('alice', $audit);
        self::assertStringNotContainsString('Mvondo', $audit);
    }

    public function testApplicationAvecMotsDePasseProvisoiresRemisUneSeuleFois(): void
    {
        $data = $this->preview(self::HEAD . "Mvondo;Alice;alice@ecole.test;;fr;comptable;;;;;\nEtoa;Marc;;691000022;en;enseignant;unité;" . $this->unit . ";;;\nBad;;x;;;;;;;;\n");
        $id = $data['data']['id'];
        $commit = $this->call('POST', '/api/v1/imports/people/' . $id . '/commit', $this->h($this->director));
        self::assertSame(200, $commit->getStatusCode(), (string) $commit->getBody());
        self::assertSame('no-store', $commit->getHeaderLine('Cache-Control'));
        $body = $this->json($commit);
        self::assertSame(2, $body['data']['summary']['applied']);
        self::assertSame(1, $body['data']['summary']['error']);
        $creds = $body['data']['provisional_credentials'];
        self::assertCount(2, $creds);
        $password = (string) $creds[0]['provisional_password'];
        self::assertSame(12, strlen($password));

        $login = $this->login('alice@ecole.test', $password);
        self::assertSame(200, $login->getStatusCode());
        self::assertTrue($this->json($login)['password_change_required']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM role_assignments WHERE role = ?', ['comptable']));
        self::assertSame($this->unit, (string) $this->scalar('SELECT scope_id FROM role_assignments WHERE role = ? AND scope_type = ?', ['enseignant', 'org_unit']));

        $replay = $this->call('POST', '/api/v1/imports/people/' . $id . '/commit', $this->h($this->director));
        self::assertSame(200, $replay->getStatusCode());
        self::assertTrue($this->json($replay)['meta']['replay']);
        self::assertArrayNotHasKey('provisional_credentials', $this->json($replay)['data']);
        $show = (string) $this->call('GET', '/api/v1/imports/people/' . $id, $this->h($this->director))->getBody();
        foreach ([$password, (string) $creds[1]['provisional_password']] as $secret) {
            self::assertStringNotContainsString($secret, $show);
            self::assertStringNotContainsString($secret, (string) json_encode($this->rows('SELECT * FROM import_people_lines')));
            self::assertStringNotContainsString($secret, (string) json_encode($this->rows('SELECT * FROM audit_log')));
        }
        self::assertStringNotContainsString('alice@ecole.test', $show, 'Données personnelles des lignes appliquées effacées.');
        self::assertSame(409, $this->call('POST', '/api/v1/imports/people/' . $id . '/commit?format=csv', $this->h($this->director))->getStatusCode());
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM audit_log WHERE action = ?', ['imports.people.committed']));
    }

    public function testFichierCsvDesIdentifiantsEtCodesDActivation(): void
    {
        $id = $this->preview(self::HEAD . "Mvondo;Alice;alice@ecole.test;;fr;comptable;;;;;\n")['data']['id'];
        $csv = $this->call('POST', '/api/v1/imports/people/' . $id . '/commit?format=csv', $this->h($this->director));
        self::assertSame(200, $csv->getStatusCode());
        self::assertStringStartsWith('text/csv', $csv->getHeaderLine('Content-Type'));
        self::assertStringContainsString('alice@ecole.test;', (string) $csv->getBody());

        $id2 = $this->preview(self::HEAD . "Etoa;Marc;marc@ecole.test;;fr;comptable;;;;;\n")['data']['id'];
        self::assertSame(422, $this->call('POST', '/api/v1/imports/people/' . $id2 . '/commit', $this->h($this->director), ['send_codes' => true])->getStatusCode());
        $commit = $this->call('POST', '/api/v1/imports/people/' . $id2 . '/commit', $this->h($this->director), ['credentials' => 'activation_code', 'send_codes' => true]);
        self::assertSame(200, $commit->getStatusCode(), (string) $commit->getBody());
        self::assertArrayNotHasKey('provisional_credentials', $this->json($commit)['data']);
        self::assertContains('marc@ecole.test', array_column($this->mail->sent, 'to'));
        self::assertSame('pending', (string) $this->scalar('SELECT status FROM users WHERE email = ?', ['marc@ecole.test']));
    }

    public function testRoleTemporaireExigeUneDateDeFin(): void
    {
        $this->directory->add('t1', 'h1', 'IPES', 'higher');
        $this->createUser('t1', 'Hugo', '+237691000013', [['higher_admin_fonct', 'establishment', 'h1']], 'hugo@ipes.test');
        $request = (new ServerRequest('POST', '/api/v1/imports/people', $this->auth($this->token('hugo@ipes.test'), 'h1')))
            ->withUploadedFiles(['file' => new UploadedFile(Stream::create(self::HEAD . "Mvondo;Alice;alice@ecole.test;;fr;higher_jury;;;;;\n"), 10, UPLOAD_ERR_OK, 'a.csv', 'text/csv')]);
        $response = $this->kernel->handle($request);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('error', $this->json($response)['data']['lines'][0]['status']);
    }

    public function testIsolationEntreClientsEtEtablissements(): void
    {
        $id = $this->preview(self::HEAD . "Mvondo;Alice;alice@ecole.test;;fr;comptable;;;;;\n")['data']['id'];
        $other = $this->token('denis@ecole.test');
        $foreign = $this->token('gaston@lycee.test');
        self::assertSame(404, $this->call('GET', '/api/v1/imports/people/' . $id, $this->h($other, 'e2'))->getStatusCode());
        self::assertSame(404, $this->call('POST', '/api/v1/imports/people/' . $id . '/commit', $this->h($other, 'e2'))->getStatusCode());
        self::assertSame(404, $this->call('GET', '/api/v1/imports/people/' . $id . '/errors.csv', $this->h($foreign, 'f1'))->getStatusCode());
        self::assertSame(404, $this->call('GET', '/api/v1/imports/people/' . $id, $this->h($foreign, 'f1'))->getStatusCode());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM users WHERE email = ?', ['alice@ecole.test']));
    }

    public function testLimitesEtValidationDuFichier(): void
    {
        self::assertSame(422, $this->call('POST', '/api/v1/imports/people', $this->h($this->director))->getStatusCode());
        self::assertSame(422, $this->upload('')->getStatusCode());
        self::assertSame(422, $this->upload("nom;prénoms\nA;B\n")->getStatusCode(), 'Colonne rôle absente.');
        self::assertSame(422, $this->upload(self::HEAD . str_repeat("A;B;;;;enseignant;;;;;\n", 1001))->getStatusCode());
        self::assertSame(422, $this->upload(str_repeat('x', 2097153))->getStatusCode());
        self::assertSame(403, $this->upload(self::HEAD, $this->token('tina@ecole.test'))->getStatusCode());
    }

    public function testLectureXlsx(): void
    {
        if (!XlsxWriter::available()) {
            self::markTestSkipped('Extension zip absente.');
        }
        $xlsx = (new XlsxWriter())->build(
            ['nom', 'prénoms', 'e-mail', 'téléphone', 'langue', 'rôle', 'périmètre', 'code du périmètre', 'date de début', 'date de fin', 'note'],
            [['Mvondo', 'Alice', 'alice@ecole.test', 691000021, 'fr', 'comptable', 'établissement', '', '2026-11-02', '', '']],
        );
        $data = $this->preview($xlsx);
        self::assertSame('xlsx', $data['data']['source_format']);
        self::assertSame('valid', $data['data']['lines'][0]['status']);
        self::assertSame('+237691000021', $data['data']['lines'][0]['phone']);
        self::assertSame('2026-11-02', $data['data']['lines'][0]['starts_on']);
    }
}

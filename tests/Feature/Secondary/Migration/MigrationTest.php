<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Migration;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Scholaris\Core\Http\XlsxWriter;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\NationalIds;
use Scholaris\Modules\Socle\Billing\Contracts\LearnerInvoices;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationData;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationRegistry;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * T977 (E4-C) : reprise de données d'un ancien logiciel par fichier joint (multipart, champ `file`, CSV ou XLSX) : classes, élèves
 * et inscriptions avec matricule, soldes d'ouverture. Simulation d'abord (rapport ligne par ligne, rien d'écrit), application
 * seulement sans erreur, idempotence, droits par jeu, isolation par client et établissement. Vrai socle (personnes, inscriptions,
 * facturation). Données fictives.
 */
final class MigrationTest extends SecondaryTestCase
{
    private const YEAR = 'annee-reprise-2026';
    private const BURSAR = 'intendant-reprise';
    private const DESK = 'scolarite-reprise';
    private const ADMIN_RIGHTS = ['secondary.profile.manage', 'secondary.settings.manage', 'secondary.enrol.manage', 'secondary.enrol.read', 'secondary.billing.manage', 'billing.manage', 'billing.read'];

    private string $school = '';

    protected function setUp(): void
    {
        $this->bootSecondary();
        $this->school = $this->factory->opened(self::TA, 'college_prive', 'private_secular');
        $this->grant(self::TA, self::ADMIN, $this->school, self::ADMIN_RIGHTS);
        $this->grant(self::TA, self::DESK, $this->school, ['secondary.enrol.manage']);
        $this->grant(self::TA, self::BURSAR, $this->school, ['secondary.billing.manage']);
        foreach ($this->ok('POST', '/secondary/setup/levels/examples', [], self::ADMIN, self::TA, $this->school) as $level) {
            $this->service(AuthorizationRegistry::class)->register(self::TA, $this->school, new AuthorizationData((string) $level['structure_id'], 'AUT-' . $level['code'], '2026-01-01', '2030-12-31', 'MINESEC (fictif)'), 'test');
        }
    }

    /**
     * Envoi multipart comme l'écran (FormData).
     *
     * @param array<string, string> $fields
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function upload(string $fileName, string $content, array $fields, string $userId = self::ADMIN, string $tenantId = self::TA, ?string $establishmentId = null): array
    {
        $request = (new ServerRequest('POST', '/api/v1/secondary/migration/batches', [
            'Authorization' => 'Bearer ' . $userId . ':' . $tenantId, 'X-Establishment-Id' => $establishmentId ?? $this->school,
        ]))->withParsedBody($fields + ['academic_year_id' => self::YEAR])
            ->withUploadedFiles(['file' => new UploadedFile(Stream::create($content), strlen($content), UPLOAD_ERR_OK, $fileName, 'application/octet-stream')]);
        $response = $this->kernel->handle($request);
        $json = json_decode((string) $response->getBody(), true);

        return [$response->getStatusCode(), is_array($json) ? $json : []];
    }

    /** @param list<list<string>> $rows */
    private static function csv(array $header, array $rows): string
    {
        return implode("\n", array_map(static fn (array $r): string => implode(';', $r), [$header, ...$rows])) . "\n";
    }

    /** @return array<string, mixed> lot simulé */
    private function simulated(string $fileName, string $content, array $fields): array
    {
        [$status, $json] = $this->upload($fileName, $content, $fields);
        self::assertSame(201, $status, (string) json_encode($json, JSON_UNESCAPED_UNICODE));

        return $json['data'];
    }

    /** @return array<int, array<string, mixed>> lignes du rapport par numéro */
    private static function lines(array $batch): array
    {
        return array_column($batch['lines'], null, 'line');
    }

    private function rowsOf(string $sql): int
    {
        return (int) $this->pdo()->query($sql)->fetchColumn();
    }

    private function classes(): void
    {
        $batch = $this->simulated('classes.csv', self::csv(['classe', 'niveau', 'serie', 'capacite'], [['6e A', '6E', '', '60'], ['5e A', '5e', '', ''], ['3e B', '3E', '', '45']]), ['kind' => 'classes']);
        self::assertSame(['total' => 3, 'valid' => 3, 'duplicate' => 0, 'error' => 0, 'applied' => 0], $batch['summary']);
        $this->ok('POST', '/secondary/migration/batches/' . $batch['id'] . '/apply', [], self::ADMIN, self::TA, $this->school);
    }

    public function testClassesSimulationRapportLigneParLigneApplicationSansErreurEtIdempotence(): void
    {
        $content = self::csv(['Classe', 'Niveau', 'Série', 'Capacité'], [['6e A', '6E', '', '60'], ['2nde C', '2NDE', 'C', ''], ['6e A', '6E', '', ''], ['EXEMPLE 6e A', '6E', '', ''], ['5e A', '5E', '', 'beaucoup']]);
        $batch = $this->simulated('classes.csv', $content, ['kind' => 'classes']);
        self::assertSame('simulated', $batch['status']);
        self::assertSame(['total' => 5, 'valid' => 1, 'duplicate' => 0, 'error' => 4, 'applied' => 0], $batch['summary']);
        $lines = self::lines($batch);
        self::assertSame('valid', $lines[2]['status']);
        self::assertStringContainsString('Niveau « 2NDE » inconnu', $lines[3]['messages'][0], 'Collège privé : 1er cycle seulement.');
        self::assertStringContainsString('doublon dans le fichier', $lines[4]['messages'][0]);
        self::assertStringContainsString('exemple', $lines[5]['messages'][0]);
        self::assertStringContainsString('Capacité', $lines[6]['messages'][0]);
        self::assertSame(0, $this->rowsOf('SELECT COUNT(*) FROM se_class_profiles'), 'La simulation n\'écrit rien.');

        [$status, $problem] = $this->call('POST', '/secondary/migration/batches/' . $batch['id'] . '/apply', [], self::ADMIN, self::TA, $this->school);
        self::assertSame(422, $status, 'Application refusée tant qu\'une ligne est en erreur.');
        self::assertStringContainsString('Rien n\'a été écrit', (string) $problem['detail']);
        self::assertSame(0, $this->rowsOf('SELECT COUNT(*) FROM se_class_profiles'));

        $fixed = self::csv(['classe', 'niveau', 'serie', 'capacite'], [['6e A', '6E', '', '60'], ['5e A', '5e', '', '']]);
        $batch = $this->simulated('classes.csv', $fixed, ['kind' => 'classes']);
        $applied = $this->ok('POST', '/secondary/migration/batches/' . $batch['id'] . '/apply', [], self::ADMIN, self::TA, $this->school);
        self::assertSame(['applied', 2], [$applied['status'], $applied['summary']['applied']]);
        self::assertSame(2, $this->rowsOf("SELECT COUNT(*) FROM se_class_profiles WHERE academic_year_id = '" . self::YEAR . "'"));
        self::assertSame(409, $this->call('POST', '/secondary/migration/batches/' . $batch['id'] . '/apply', [], self::ADMIN, self::TA, $this->school)[0], 'Un lot ne s\'applique qu\'une fois.');

        $again = $this->simulated('classes.csv', $fixed, ['kind' => 'classes']);
        self::assertSame(['total' => 2, 'valid' => 0, 'duplicate' => 2, 'error' => 0, 'applied' => 0], $again['summary'], 'Rejouer : que des doublons.');
        self::assertSame(0, $this->ok('POST', '/secondary/migration/batches/' . $again['id'] . '/apply', [], self::ADMIN, self::TA, $this->school)['summary']['applied']);
        self::assertSame(2, $this->rowsOf('SELECT COUNT(*) FROM se_class_profiles'));
        self::assertContains('secondary.migration.applied', $this->audit->actions());
    }

    public function testElevesParExcelInscriptionsMatriculesEtSoldesDOuverture(): void
    {
        $this->classes();
        $header = ['id_ancien', 'nom', 'prenoms', 'date_naissance', 'sexe', 'lieu_naissance', 'classe', 'matricule', 'redoublant', 'nom_parent', 'prenoms_parent', 'telephone_parent', 'lien_parent'];
        $rows = [
            ['OLD-001', 'NJOYA', 'Awa', '2014-02-11', 'F', 'Foumban', '6e A', 'CM2026-000901', 'non', 'NJOYA', 'Ibrahim', '+237600000101', 'parent'],
            ['OLD-002', 'TCHAMBA', 'Brice', '12/06/2013', 'M', 'Bafoussam', '5e A', '', 'oui', 'TCHAMBA', 'Rose', '+237600000102', 'guardian'],
            ['OLD-003', 'ESSOMBA', 'Carine', '2014-09-30', 'F', 'Yaoundé', '4e Z', '', 'non', 'ESSOMBA', 'Paul', '+237600000103', 'parent'],
        ];
        $xlsx = (new XlsxWriter())->build($header, $rows, 'Eleves');
        $batch = $this->simulated('eleves.xlsx', $xlsx, ['kind' => 'students', 'entry_date' => '2026-09-07']);
        self::assertSame('xlsx', $batch['format']);
        self::assertSame(['total' => 3, 'valid' => 2, 'duplicate' => 0, 'error' => 1, 'applied' => 0], $batch['summary'], (string) json_encode($batch['lines'], JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('Classe « 4e Z » inconnue', self::lines($batch)[4]['messages'][0]);
        self::assertSame(0, $this->rowsOf('SELECT COUNT(*) FROM se_enrolment_records'), 'Simulation : aucune inscription.');
        self::assertSame(422, $this->call('POST', '/secondary/migration/batches/' . $batch['id'] . '/apply', [], self::DESK, self::TA, $this->school)[0]);

        unset($rows[2]);
        $batch = $this->simulated('eleves.xlsx', (new XlsxWriter())->build($header, array_values($rows), 'Eleves'), ['kind' => 'students', 'entry_date' => '2026-09-07']);
        self::assertSame(403, $this->call('POST', '/secondary/migration/batches/' . $batch['id'] . '/apply', [], self::BURSAR, self::TA, $this->school)[0], 'L\'intendant ne reprend pas les élèves.');
        $applied = $this->ok('POST', '/secondary/migration/batches/' . $batch['id'] . '/apply', [], self::DESK, self::TA, $this->school);
        self::assertSame(['applied', 2], [$applied['status'], $applied['summary']['applied']]);
        $awa = (string) self::lines($applied)[2]['object_ref'];
        self::assertSame('CM2026-000901', $this->service(NationalIds::class)->of(self::TA, $this->school, $awa), 'Matricule enregistré.');
        self::assertCount(1, $this->service(EnrollmentDirectory::class)->forPerson(self::TA, $awa), 'Inscription du socle créée.');
        self::assertSame(2, $this->rowsOf("SELECT COUNT(*) FROM se_enrolment_records WHERE entry_kind = 'import'"));

        $replay = $this->simulated('eleves.xlsx', (new XlsxWriter())->build($header, array_values($rows), 'Eleves'), ['kind' => 'students', 'entry_date' => '2026-09-07']);
        self::assertSame(2, $replay['summary']['duplicate'], 'Même fichier : doublons (matricule, puis identifiant de l\'ancien logiciel).');

        $this->ok('POST', '/fee-items', ['kind' => 'tuition', 'name' => 'Pension', 'code' => 'pension', 'amount_xaf' => 150000], self::ADMIN, self::TA, $this->school);
        $balances = self::csv(['id_ancien', 'matricule', 'code_frais', 'montant', 'echeance', 'libelle'], [
            ['', 'CM2026-000901', 'PENSION', '25 000', '2026-11-30', 'Reste de pension 2025-2026'],
            ['OLD-002', '', 'PENSION', '40000', '30/11/2026', ''],
            ['OLD-002', '', 'INCONNU', '1000', '2026-11-30', ''],
            ['OLD-404', '', 'PENSION', '1000', '2026-11-30', ''],
        ]);
        [$status] = $this->upload('soldes.csv', $balances, ['kind' => 'balances'], self::DESK);
        self::assertSame(403, $status, 'La scolarité ne reprend pas les soldes.');
        $batch = $this->simulated('soldes.csv', $balances, ['kind' => 'balances']);
        $lines = self::lines($batch);
        self::assertSame(['valid', 'valid', 'error', 'error'], [$lines[2]['status'], $lines[3]['status'], $lines[4]['status'], $lines[5]['status']]);
        self::assertStringContainsString('Frais introuvable', $lines[4]['messages'][0], 'Refus de la facturation remonté par la simulation (transaction annulée).');
        self::assertStringContainsString('OLD-404', $lines[5]['messages'][0]);
        self::assertSame(0, $this->rowsOf("SELECT COUNT(*) FROM invoices WHERE establishment_id = '" . $this->school . "'"), 'Rien de facturé pendant la simulation.');

        $batch = $this->simulated('soldes.csv', self::csv(['id_ancien', 'matricule', 'code_frais', 'montant', 'echeance', 'libelle'], [
            ['', 'CM2026-000901', 'PENSION', '25 000', '2026-11-30', 'Reste de pension 2025-2026'], ['OLD-002', '', 'PENSION', '40000', '30/11/2026', ''],
        ]), ['kind' => 'balances']);
        $applied = $this->ok('POST', '/secondary/migration/batches/' . $batch['id'] . '/apply', [], self::BURSAR, self::TA, $this->school);
        self::assertSame(2, $applied['summary']['applied']);
        $invoices = $this->service(LearnerInvoices::class)->idsFor(self::TA, $this->school, $awa);
        self::assertCount(1, $invoices);
        self::assertSame(25000, (int) $this->pdo()->query("SELECT net_xaf FROM invoices WHERE id = '" . $invoices[0] . "'")->fetchColumn());
        $again = $this->simulated('soldes.csv', self::csv(['id_ancien', 'matricule', 'code_frais', 'montant', 'echeance', 'libelle'], [['', 'CM2026-000901', 'PENSION', '25000', '2026-11-30', '']]), ['kind' => 'balances']);
        self::assertSame(1, $again['summary']['duplicate'], 'Jamais facturé deux fois.');
    }

    public function testSimulationDesElevesSignaleLaClasseSansAutorisationDOuverture(): void
    {
        $bare = $this->factory->opened(self::TA, 'college_prive', 'private_secular', 'e-sans-autorisation');
        $this->grant(self::TA, self::ADMIN, $bare, self::ADMIN_RIGHTS);
        $this->ok('POST', '/secondary/setup/levels/examples', [], self::ADMIN, self::TA, $bare);
        [, $json] = $this->upload('classes.csv', self::csv(['classe', 'niveau'], [['6e A', '6E']]), ['kind' => 'classes'], self::ADMIN, self::TA, $bare);
        $this->ok('POST', '/secondary/migration/batches/' . $json['data']['id'] . '/apply', [], self::ADMIN, self::TA, $bare);

        $content = self::csv(['nom', 'prenoms', 'date_naissance', 'classe', 'nom_parent', 'telephone_parent'], [['ABENA', 'Lucie', '2014-05-02', '6e A', 'ABENA', '+237600000201']]);
        [$status, $json] = $this->upload('eleves.csv', $content, ['kind' => 'students', 'entry_date' => '2026-09-07'], self::ADMIN, self::TA, $bare);
        self::assertSame(201, $status);
        self::assertSame(1, $json['data']['summary']['error'], 'La simulation voit le refus que l\'inscription opposerait.');
        self::assertStringContainsString('sans autorisation d\'ouverture', $json['data']['lines'][0]['messages'][0]);
        self::assertSame(422, $this->call('POST', '/secondary/migration/batches/' . $json['data']['id'] . '/apply', [], self::ADMIN, self::TA, $bare)[0]);
    }

    public function testDroitsIsolationEtFichierInvalide(): void
    {
        $batch = $this->simulated('classes.csv', self::csv(['classe', 'niveau'], [['6e A', '6E']]), ['kind' => 'classes']);

        $this->grant(self::TB, self::ADMIN, $this->school, self::ADMIN_RIGHTS);
        self::assertSame(404, $this->call('GET', '/secondary/migration/batches/' . $batch['id'], null, self::ADMIN, self::TB, $this->school)[0], 'Autre client : introuvable.');
        $other = $this->factory->opened(self::TA, 'college_prive', 'private_secular', 'e-autre-college');
        $this->grant(self::TA, self::ADMIN, $other, self::ADMIN_RIGHTS);
        self::assertSame(404, $this->call('GET', '/secondary/migration/batches/' . $batch['id'], null, self::ADMIN, self::TA, $other)[0], 'Autre établissement : introuvable.');
        self::assertSame(404, $this->call('POST', '/secondary/migration/batches/' . $batch['id'] . '/apply', [], self::ADMIN, self::TA, $other)[0]);
        self::assertSame([], $this->ok('GET', '/secondary/migration/batches', null, self::ADMIN, self::TA, $other));
        self::assertCount(1, $this->ok('GET', '/secondary/migration/batches?kind=classes', null, self::ADMIN, self::TA, $this->school));

        $this->grant(self::TA, 'sans-droit', $this->school, ['secondary.enrol.read']);
        self::assertSame(403, $this->call('GET', '/secondary/migration/batches', null, 'sans-droit', self::TA, $this->school)[0]);
        [$status, $problem] = $this->upload('classes.csv', "nom;prenoms\nX;Y\n", ['kind' => 'classes']);
        self::assertSame(422, $status);
        self::assertStringContainsString('classe, niveau', (string) json_encode($problem, JSON_UNESCAPED_UNICODE));
        self::assertSame(422, $this->upload('eleves.csv', "nom\n", ['kind' => 'students'])[0], 'Date d\'entrée exigée pour les élèves.');
        self::assertSame(422, $this->call('POST', '/secondary/migration/batches', ['kind' => 'classes', 'academic_year_id' => self::YEAR], self::ADMIN, self::TA, $this->school)[0], 'Fichier exigé.');

        $kinds = array_column($this->ok('GET', '/secondary/migration/kinds', null, self::BURSAR, self::TA, $this->school), 'allowed', 'kind');
        self::assertSame(['classes' => false, 'students' => false, 'balances' => true], $kinds);
        $template = $this->raw('GET', '/secondary/migration/templates/students', null, self::ADMIN, self::TA, $this->school);
        self::assertSame(200, $template->getStatusCode());
        self::assertStringContainsString('id_ancien;nom;prenoms', (string) $template->getBody());

        $closed = $this->factory->establishment(self::TA, 'ces', 'e-ces-ferme');
        $this->grant(self::TA, self::ADMIN, $closed, self::ADMIN_RIGHTS);
        self::assertSame(409, $this->upload('classes.csv', self::csv(['classe', 'niveau'], [['6e A', '6E']]), ['kind' => 'classes'], self::ADMIN, self::TA, $closed)[0], 'Application non ouverte.');
    }
}

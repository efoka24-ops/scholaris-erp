<?php

declare(strict_types=1);

namespace Tests\Feature\Primary\ReportCards;

use Scholaris\Modules\Socle\Assessment\Contracts\ClassResults;
use Scholaris\Modules\Socle\Assessment\Contracts\CurriculumCatalog;
use Scholaris\Modules\Socle\Assessment\Contracts\CurriculumItem;
use Scholaris\Modules\Socle\Documents\Application\DocumentService;
use Scholaris\Modules\Socle\Documents\Domain\TemplateGuard;
use Scholaris\Modules\Socle\Documents\Domain\TemplateRenderer;
use Scholaris\Modules\Socle\Documents\Infrastructure\DompdfRenderer;
use Scholaris\Modules\Socle\Documents\Infrastructure\LibraryQrCodeGenerator;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDemographics;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Primary\FakeClassResults;
use Tests\Support\Primary\NumberedEnrollments;
use Tests\Support\Primary\PrimaryTestCase;

/**
 * C-104 : bulletin complet du primaire privé (modèle B). Notes SEQ1/SEQ2, MOY, COEF, TOTAL, RANG par matière, totaux par domaine, profil
 * de la classe, bloc Travail (seuils paramétrés « à confirmer »), date de naissance et matricule, à partir des seules données existantes ;
 * une donnée absente n'est pas affichée. Données fictives ; le modèle rendu tient sur une page A4.
 */
final class BulletinDetailTest extends PrimaryTestCase
{
    private FakeClassResults $results;
    private NumberedEnrollments $enrollments;
    /** @var array<int, array{id: string, starts_on: string, ends_on: string, sequences: list<array{id: string, starts_on: string}>}> */
    private array $terms;
    private string $class;
    private string $level;
    /** @var array<string, string> code de matière => identifiant */
    private array $subjects = [];

    protected function setUp(): void
    {
        $this->results = new FakeClassResults();
        $this->enrollments = new NumberedEnrollments();
        $people = new InMemoryPeople();
        $demographics = new class () implements PersonDemographics {
            public function ofPersons(string $tenantId, array $personIds): array
            {
                return array_fill_keys(array_intersect($personIds, ['e1', 'e2']), ['gender' => 'female', 'birth_date' => '2017-04-12']);
            }
        };
        $this->bootPrimary([
            EnrollmentDirectory::class => $this->enrollments, PersonDirectory::class => $people, ClassResults::class => $this->results, PersonDemographics::class => $demographics,
        ]);
        $this->terms = $this->seedYear(self::TA, self::EA);
        $this->ok('POST', '/primary/referentials/example/apply', ['academic_year_id' => self::YEAR]);
        $profiles = $this->call('GET', '/primary/level-profiles?sort=sort_order')[1]['data'];
        $this->level = $profiles[0]['level_structure_id'];
        $this->class = $this->service(OrgUnitTree::class)->structureDescendantsOf(self::TA, self::EA, $this->level)[1];
        foreach (['e1' => 'IPR-0001', 'e2' => null, 'e3' => null] as $id => $matricule) {
            $people->add(self::TA, $id, 'Prénom' . $id, 'Nom' . $id);
            $this->enrollments->add(self::TA, 'en' . $id, $id, self::EA, $this->class, self::YEAR, $matricule);
        }
        $pdo = $this->kernel->container()->get(\Scholaris\Core\Database\ConnectionFactory::class)->pdo();
        $statement = $pdo->prepare('SELECT subject_id, subject_code FROM pri_level_subjects WHERE tenant_id = ? AND level_structure_id = ?');
        $statement->execute([self::TA, $this->level]);
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $this->subjects[(string) $row['subject_code']] = (string) $row['subject_id'];
        }
        $catalog = $this->service(CurriculumCatalog::class);
        foreach (['LECT' => 2.0, 'CALC' => 3.0, 'EDUC' => 1.0] as $code => $coefficient) {
            $catalog->upsertSubject(self::TA, self::EA, new CurriculumItem('pri:' . $code, $code, 'Matière ' . $code, 'subject', $coefficient, 0));
        }
        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, self::EA, 'primary.council.require_locked_sheets', false, self::DIRECTOR);
    }

    /** @param array<string, float> $marks code => note */
    private function marks(string $periodId, string $person, array $marks): void
    {
        $rows = [];
        foreach ($marks as $code => $value) {
            $rows[] = ['subject_id' => $this->subjects[$code] ?? ('sub-' . $code), 'code' => $code, 'name' => 'Matière ' . $code, 'average' => $value];
        }
        $this->results->subjects[$periodId][$person] = $rows;
        $this->results->averages[$periodId][$person] = round(array_sum($marks) / count($marks), 2);
    }

    private function openCouncil(): string
    {
        $t1 = $this->terms[1];

        return (string) $this->ok('POST', '/primary/councils', ['class_id' => $this->class, 'period_id' => $t1['id'], 'starts_on' => $t1['starts_on'], 'period_label' => 'Trimestre 1', 'held_on' => '2026-11-26', 'sequences' => $t1['sequences']])['id'];
    }

    private function seedMarks(): void
    {
        $t1 = $this->terms[1];
        [$s1, $s2] = [$t1['sequences'][0]['id'], $t1['sequences'][1]['id']];
        $this->marks($s1, 'e1', ['LECT' => 15.0, 'CALC' => 11.0, 'EDUC' => 16.0]);
        $this->marks($s2, 'e1', ['LECT' => 13.0, 'CALC' => 13.0, 'EDUC' => 16.0]);
        $this->marks($t1['id'], 'e1', ['LECT' => 14.0, 'CALC' => 12.0, 'EDUC' => 16.0]);
        $this->marks($t1['id'], 'e2', ['LECT' => 12.0, 'CALC' => 12.0, 'EDUC' => 12.0]);
        $this->marks($t1['id'], 'e3', ['LECT' => 10.0, 'CALC' => 8.0, 'EDUC' => 12.0]);
        $this->results->roster[$this->class] = ['e1', 'e2', 'e3'];
    }

    /** @return array<string, mixed> */
    private function preview(string $council, string $person = 'e1'): array
    {
        return $this->ok('GET', "/primary/councils/$council/report-cards/preview?person_id=$person");
    }

    /** @return array<string, mixed> */
    private static function table(array $preview, string $variable): array
    {
        return json_decode((string) $preview['variables'][$variable], true);
    }

    public function testNotesParSequenceMoyenneCoefficientTotalEtRangParMatiere(): void
    {
        $this->seedMarks();
        $preview = $this->preview($this->openCouncil());
        $table = self::table($preview, 'subjects_detail_table');

        self::assertSame(['SEQ1', 'SEQ2', 'MOY', 'COEF', 'TOTAL'], array_slice(array_column($table['columns'], 'label'), 1, 5));
        self::assertSame(7, count($table['columns']), 'Matière, SEQ1, SEQ2, MOY, COEF, TOTAL, RANG.');
        $lecture = $table['rows'][0];
        self::assertSame(['Matière LECT', '15,00', '13,00', '14,00', '2', '28,00', '1'], $lecture, 'e1 est première en lecture (14 / 12 / 10).');
        $calcul = $table['rows'][1];
        self::assertSame(['Matière CALC', '11,00', '13,00', '12,00', '3', '36,00', '1'], array_map('strval', $calcul), 'e1 et e2 ex æquo à 12 : même rang.');
        $total = end($table['rows']);
        self::assertSame('total', $total['kind']);
        self::assertSame(['6', '80,00'], [$total['cells'][2], $total['cells'][3]], 'Somme des coefficients 2 + 3 + 1 et des totaux 28 + 36 + 16.');
    }

    public function testLesColonnesAbsentesNeSontPasInventees(): void
    {
        $t1 = $this->terms[1];
        $this->marks($t1['id'], 'e1', ['LECT' => 14.0, 'CALC' => 12.0]);
        $this->results->roster[$this->class] = ['e1', 'e2', 'e3'];
        $preview = $this->preview($this->openCouncil());
        $table = self::table($preview, 'subjects_detail_table');

        self::assertNotContains('SEQ1', array_column($table['columns'], 'label'), 'Aucune note de séquence : pas de colonne SEQ.');
        self::assertContains('MOY', array_column($table['columns'], 'label'));
        $snapshot = $preview['snapshot']['detail'];
        self::assertSame([], $snapshot['sequences']);
        self::assertNotContains('COMP', array_column($table['columns'], 'label'), 'Aucune source de composition : jamais de colonne COMP.');
    }

    public function testTotauxParDomaineEtProfilDeLaClasse(): void
    {
        $this->seedMarks();
        $preview = $this->preview($this->openCouncil());
        $domains = self::table($preview, 'domain_totals_table')['rows'];

        // LECT (2 x 14 = 28) en LANG ; CALC (3 x 12 = 36) en MATH ; EDUC (1 x 16 = 16) en SOC.
        self::assertSame([['2', '28,00', '14,00'], ['3', '36,00', '12,00'], ['1', '16,00', '16,00']], array_map(static fn (array $r): array => array_slice($r, 1), $domains));
        $profile = $preview['snapshot']['detail']['class_profile'];
        self::assertEquals(['size' => 3, 'above_pass' => 3, 'class_average' => 12.0, 'max' => 14.0, 'min' => 10.0], array_intersect_key($profile, array_flip(['size', 'above_pass', 'class_average', 'max', 'min'])));
        self::assertEquals(100.0, $profile['success_rate']);
        $rows = array_column(self::table($preview, 'class_profile_table')['rows'], 1);
        self::assertContains('3 / 3', $rows);
        self::assertContains('12,00', $rows);
        self::assertContains('100,0 %', $rows);
    }

    public function testBlocTravailSelonLesSeuilsParametresEtRienSansSeuil(): void
    {
        $this->seedMarks();
        $council = $this->openCouncil();
        $without = self::table($this->preview($council), 'work_table');
        self::assertSame('muted', $without['rows'][0]['kind'], 'Sans seuil paramétré, aucune distinction n\'est affichée.');

        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, self::EA, 'primary.report_card.distinctions', [
            'honour_roll_min' => 14, 'congratulations_min' => 15, 'encouragements_min' => 12, 'warning_max' => 10, 'blame_max' => 8,
        ], self::DIRECTOR);
        $work = self::table($this->preview($council, 'e1'), 'work_table')['rows'];
        $byLabel = [];
        foreach ($work as $row) {
            $byLabel[explode(' / ', (string) $row[0])[0]] = $row[1];
        }
        self::assertSame(['Tableau d\'honneur' => 'Oui', 'Félicitations' => 'Non', 'Encouragements' => 'Oui', 'Avertissement' => 'Non', 'Blâme' => 'Non'], $byLabel, 'Moyenne 14 : honneur et encouragements.');
        $e3 = [];
        foreach (self::table($this->preview($council, 'e3'), 'work_table')['rows'] as $row) {
            $e3[explode(' / ', (string) $row[0])[0]] = $row[1];
        }
        self::assertSame(['Non', 'Non'], [$e3['Avertissement'], $e3['Blâme']], 'Moyenne 10 : ni sous 10 ni sous 8.');
    }

    public function testDateDeNaissanceEtMatriculeSeulementQuandIlsExistent(): void
    {
        $this->seedMarks();
        $council = $this->openCouncil();

        self::assertSame('Né(e) le 12/04/2017 · Matricule IPR-0001', $this->preview($council, 'e1')['variables']['identity_line']);
        self::assertSame('Né(e) le 12/04/2017', $this->preview($council, 'e2')['variables']['identity_line'], 'Pas de matricule : non affiché.');
        self::assertSame('', $this->preview($council, 'e3')['variables']['identity_line'], 'Ni date ni matricule : rien.');
    }

    public function testBulletinEmisAvecLeDetailEtModeleSurUnePageA4(): void
    {
        $this->seedMarks();
        $council = $this->openCouncil();
        $this->ok('POST', "/primary/councils/$council/validate");
        $issued = $this->ok('POST', "/primary/councils/$council/report-cards/issue");
        self::assertSame(0, $issued['failed']);
        $card = array_values(array_filter($issued['items'], static fn (array $c): bool => $c['person_id'] === 'e1'))[0];
        $pdo = $this->kernel->container()->get(\Scholaris\Core\Database\ConnectionFactory::class)->pdo();
        $content = (string) $pdo->query("SELECT content FROM documents WHERE id = '" . $card['document_id'] . "'")->fetchColumn();
        foreach (['SEQ1', 'SEQ2', 'COEF', 'TOTAL', 'Matière LECT', 'IPR-0001', 'Profil de la classe'] as $needle) {
            self::assertStringContainsString($needle, $content, $needle);
        }
        self::assertStringNotContainsString('{{', $content);

        // Rendu PDF du modèle en vigueur avec dix matières : une seule page A4.
        $t1 = $this->terms[1];
        $ten = ['LECT' => 14.0, 'CALC' => 12.0, 'EDUC' => 16.0, 'DESS' => 15.0, 'MUSI' => 13.0, 'SPOR' => 17.0, 'HIST' => 11.0, 'GEOG' => 12.5, 'SCIE' => 14.5, 'INFO' => 16.5];
        foreach ([$t1['sequences'][0]['id'], $t1['sequences'][1]['id'], $t1['id']] as $period) {
            $this->marks($period, 'e1', $ten);
        }
        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, self::EA, 'primary.report_card.distinctions', ['honour_roll_min' => 14, 'blame_max' => 5], self::DIRECTOR);
        $variables = array_map(static fn (mixed $v): string => (string) $v, $this->preview($council)['variables']);
        $type = $pdo->query("SELECT variables FROM document_types WHERE tenant_id = '*' AND code = 'pri_report_card'")->fetchColumn();
        $html = (string) $pdo->query("SELECT html FROM document_templates WHERE tenant_id = '*' AND type_code = 'pri_report_card' AND locale = 'fr' ORDER BY version DESC LIMIT 1")->fetchColumn();
        TemplateGuard::assertValid($html, json_decode((string) $type, true));
        $page = TemplateRenderer::render($html, $variables + [
            'number' => 'ECP-26-B-000042', 'verification_url' => 'https://verif.scholaris.example/v/EXEMPLEFICTIF', 'holder_name' => 'MBARGA ESSOMBA Nadège (fictif)',
            'establishment_name' => 'École privée Les Flamboyants (fictive)', 'issued_on' => '31/10/2026', 'year' => '2026',
        ], ['qr' => (new LibraryQrCodeGenerator())->markup('https://verif.scholaris.example/v/EXEMPLEFICTIF'), 'signature' => '', 'stamp' => '', 'logo' => '']);
        $pdf = (new DompdfRenderer(sys_get_temp_dir() . '/scholaris-dompdf-test'))->render(DocumentService::withPrintStyle($page));
        self::assertSame(1, preg_match_all('#/Type\s*/Page[^s]#', $pdf), 'Une seule page A4 avec dix matières.');
        if (getenv('DOC_BUL_EXAMPLES') === '1') {
            $dir = dirname(__DIR__, 4) . '/specs/006-audit-primaire-maternelle/exemples-documents';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($dir . '/pri_report_card-fr-complet.pdf', $pdf);
            file_put_contents(sys_get_temp_dir() . '/doc-bul-pri_report_card-fr-complet.html', DocumentService::withPrintStyle($page));
        }
    }

    public function testLeBulletinSansMoyennesChiffreesNAffichePasLeDetail(): void
    {
        $this->seedMarks();
        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, self::EA, 'primary.report_card.numeric_averages', false, self::DIRECTOR);
        $preview = $this->preview($this->openCouncil());

        self::assertSame([], $preview['snapshot']['detail']['subjects']);
        self::assertSame('muted', self::table($preview, 'subjects_detail_table')['rows'][0]['kind']);
        self::assertSame('Né(e) le 12/04/2017 · Matricule IPR-0001', $preview['variables']['identity_line']);
    }
}

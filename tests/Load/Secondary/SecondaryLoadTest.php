<?php

declare(strict_types=1);

namespace Tests\Load\Secondary;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Assessment\Contracts\ActorDirectory;
use Scholaris\Modules\Socle\Documents\Domain\QrCodeGenerator;
use Scholaris\Modules\Socle\Documents\Infrastructure\DompdfRenderer;
use Scholaris\Modules\Socle\Documents\Infrastructure\LibraryQrCodeGenerator;
use Scholaris\Modules\Socle\Integrations\Contracts\PdfRenderer;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationData;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationRegistry;
use Tests\Acceptance\Secondary\ReferenceCalculationTest;
use Tests\Support\Assessment\FixedActors;
use Tests\Support\Assessment\RecordingMessenger;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * Mesures NF-S1 à NF-S5 du secondaire (T975, lot E4-C) sur la VRAIE pile en processus : routes, socle Évaluation (stratégie du
 * secondaire, conseils, rangs), Personnes et Inscriptions réelles, Documents avec Dompdf et QR réels. Données fictives.
 *
 * Jeu : un LEG public, 4 niveaux (6E à 3E), 10 matières à coefficients, N élèves (3 000 par défaut) répartis en classes de 50,
 * créés par la REPRISE DE DONNÉES (T977, fichiers CSV joints de 1 000 lignes) ; deux séquences (S1, S2) du trimestre T1, une
 * épreuve par matière et par séquence, notes saisies par feuille entière (POST /grades/batch) puis verrouillées.
 *
 *  - NF-S1 : moyennes et rangs de tout l'établissement (POST /class-councils de chaque classe, trimestre T1) < 60 s ;
 *  - NF-S2 : bulletins de tout l'établissement (validation, émission) + rendu PDF Dompdf d'un échantillon, projeté à N : < 15 min ;
 *  - NF-S3 : pic de saisie de 300 enseignants (une feuille de 50 notes chacun) : latence p50/p95 par feuille et débit d'UN
 *            processus PHP ; la concurrence HTTP réelle se mesure avec scripts/loadtest/k6-api.js sur la recette ;
 *  - NF-S4 : file hors ligne rejouée (POST /grades/sync) deux fois : aucune perte, aucun doublon, conflit 409 conservé ;
 *  - NF-S5 : 100 classes contre le calcul de référence indépendant (ReferenceCalculationTest, écart 0,00), chronométré.
 *
 *   php -d memory_limit=2G vendor/bin/phpunit tests/Load/Secondary                       (SQLite en mémoire)
 *   TEST_DB_DRIVER=mysql ... php -d memory_limit=2G vendor/bin/phpunit -c phpunit.mysql.xml tests/Load/Secondary   (MySQL 8)
 *   SECONDARY_LOAD_STUDENTS=600 SECONDARY_LOAD_PDF_SAMPLE=20 ... (jeu réduit)
 *
 * Résultats : tests/Load/.work/secondary-results.json (non versionné). Les seuils sont ceux du CDC, à confirmer au pilote (Q10).
 */
final class SecondaryLoadTest extends SecondaryTestCase
{
    private const YEAR = 'annee-charge-2026';
    private const LEVELS = ['6E', '5E', '4E', '3E'];
    private const CLASS_SIZE = 50;
    private const SUBJECTS = ['FRA' => 5, 'ANG' => 3, 'MAT' => 5, 'PHY' => 3, 'SVT' => 3, 'HIS' => 2, 'GEO' => 2, 'ECM' => 1, 'EPS' => 1, 'INF' => 2];
    private const RIGHTS = [
        'secondary.profile.manage', 'secondary.profile.read', 'secondary.settings.manage', 'secondary.enrol.manage', 'secondary.enrol.read',
        'assessment.read', 'assessment.enter', 'assessment.validate', 'assessment.council.manage', 'secondary.council.prepare', 'secondary.reportcard.read',
        'documents.read', 'documents.issue', 'documents.manage',
    ];

    private string $school = '';
    /** @var array<string, array{structure: string, level: string, people: list<string>}> libellé => classe */
    private array $classes = [];
    /** @var array<string, string> code => identifiant de la matière (référentiel d'évaluation) */
    private array $subjects = [];
    /** @var array<string, mixed> */
    private static array $results = [];

    protected function setUp(): void
    {
        $work = dirname(__DIR__) . '/.work';
        if (!is_dir($work . '/dompdf')) {
            mkdir($work . '/dompdf', 0o777, true);
        }
        $this->bootSecondary([
            ActorDirectory::class => new FixedActors(), Messenger::class => new RecordingMessenger(),
            PdfRenderer::class => new DompdfRenderer($work . '/dompdf', 'A4'), QrCodeGenerator::class => new LibraryQrCodeGenerator(),
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        $file = dirname(__DIR__) . '/.work/secondary-results.json';
        $previous = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
        file_put_contents($file, json_encode(self::$results + $previous, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fwrite(STDERR, "\n" . json_encode(self::$results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    }

    private static function students(): int
    {
        $n = (int) (getenv('SECONDARY_LOAD_STUDENTS') ?: 3000);

        return max(self::CLASS_SIZE * count(self::LEVELS), $n);
    }

    private static function driver(): string
    {
        return getenv('TEST_DB_DRIVER') === 'mysql' ? 'mysql' : 'sqlite';
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function upload(string $name, string $content, array $fields): array
    {
        $request = (new ServerRequest('POST', '/api/v1/secondary/migration/batches', ['Authorization' => 'Bearer ' . self::ADMIN . ':' . self::TA, 'X-Establishment-Id' => $this->school]))
            ->withParsedBody($fields + ['academic_year_id' => self::YEAR])
            ->withUploadedFiles(['file' => new UploadedFile(Stream::create($content), strlen($content), UPLOAD_ERR_OK, $name, 'text/csv')]);
        $response = $this->kernel->handle($request);
        $json = (array) json_decode((string) $response->getBody(), true);
        self::assertSame(201, $response->getStatusCode(), (string) json_encode($json, JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $json['data']['summary']['error'], (string) json_encode(array_slice($json['data']['lines'], 0, 5), JSON_UNESCAPED_UNICODE));

        return $json['data'];
    }

    /** @param list<list<string>> $rows */
    private static function csv(array $header, array $rows): string
    {
        return implode("\n", array_map(static fn (array $r): string => implode(';', $r), [$header, ...$rows])) . "\n";
    }

    /** Année fictive : Y > T1 (S1, S2), T2 (S3, S4), T3 (S5, S6). */
    private function periods(): void
    {
        $now = '2026-08-01T08:00:00+01:00';
        $this->pdo()->prepare('INSERT INTO academic_years (id, tenant_id, establishment_id, label, starts_on, ends_on, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([self::YEAR, self::TA, $this->school, '2026-2027', '2026-09-01', '2027-06-30', 'open', $now, $now]);
        $insert = $this->pdo()->prepare('INSERT INTO periods (id, tenant_id, establishment_id, academic_year_id, kind, ordinal, starts_on, ends_on, parent_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            ['Y', 'year', 1, '2026-09-01', '2027-06-30', null],
            ['T1', 'trimester', 1, '2026-09-01', '2026-12-20', 'Y'], ['S1', 'sequence', 1, '2026-09-01', '2026-10-19', 'T1'], ['S2', 'sequence', 2, '2026-10-20', '2026-12-20', 'T1'],
            ['T2', 'trimester', 2, '2027-01-04', '2027-03-28', 'Y'], ['S3', 'sequence', 3, '2027-01-04', '2027-02-14', 'T2'], ['S4', 'sequence', 4, '2027-02-15', '2027-03-28', 'T2'],
            ['T3', 'trimester', 3, '2027-04-06', '2027-06-30', 'Y'], ['S5', 'sequence', 5, '2027-04-06', '2027-05-10', 'T3'], ['S6', 'sequence', 6, '2027-05-11', '2027-06-30', 'T3'],
        ] as [$id, $kind, $ordinal, $from, $to, $parent]) {
            $insert->execute([$id, self::TA, $this->school, self::YEAR, $kind, $ordinal, $from, $to, $parent, $now, $now]);
        }
    }

    /** Établissement, classes et élèves par la reprise de données, matières, coefficients et règles de chaque niveau. */
    private function school(): void
    {
        $this->school = $this->factory->opened(self::TA, 'leg');
        $this->grant(self::TA, self::ADMIN, $this->school, self::RIGHTS);
        $this->periods();
        foreach ($this->ok('POST', '/secondary/setup/levels/examples', [], self::ADMIN, self::TA, $this->school) as $level) {
            $this->service(AuthorizationRegistry::class)->register(self::TA, $this->school, new AuthorizationData((string) $level['structure_id'], 'AUT-' . $level['code'], '2026-01-01', '2030-12-31', 'MINESEC (fictif)'), 'charge');
        }

        $perLevel = (int) ceil(self::students() / self::CLASS_SIZE / count(self::LEVELS));
        $rows = [];
        foreach (self::LEVELS as $level) {
            for ($i = 1; $i <= $perLevel; ++$i) {
                $rows[] = [sprintf('%s %02d', $level, $i), $level, '', '60'];
            }
        }
        $t0 = microtime(true);
        $batch = $this->upload('classes.csv', self::csv(['classe', 'niveau', 'serie', 'capacite'], $rows), ['kind' => 'classes']);
        $this->ok('POST', '/secondary/migration/batches/' . $batch['id'] . '/apply', [], self::ADMIN, self::TA, $this->school);
        foreach ($this->pdo()->query("SELECT label, structure_id, level_code FROM se_class_profiles WHERE academic_year_id = '" . self::YEAR . "'")->fetchAll() as $row) {
            $this->classes[(string) $row['label']] = ['structure' => (string) $row['structure_id'], 'level' => (string) $row['level_code'], 'people' => []];
        }
        self::assertCount(count($rows), $this->classes);

        $labels = array_keys($this->classes);
        $students = [];
        for ($n = 0; $n < self::students(); ++$n) {
            $students[] = [sprintf('OLD-%05d', $n), 'ELEVE' . $n, 'Fictif', sprintf('201%d-%02d-%02d', 1 + $n % 4, 1 + $n % 12, 1 + $n % 28), $n % 2 === 0 ? 'F' : 'M', 'Garoua',
                $labels[intdiv($n, self::CLASS_SIZE) % count($labels)], '', 'non', 'PARENT' . $n, 'Fictif', sprintf('+2376%08d', 10000000 + $n), 'parent'];
        }
        $t1 = microtime(true);
        $header = ['id_ancien', 'nom', 'prenoms', 'date_naissance', 'sexe', 'lieu_naissance', 'classe', 'matricule', 'redoublant', 'nom_parent', 'prenoms_parent', 'telephone_parent', 'lien_parent'];
        foreach (array_chunk($students, 1000) as $index => $chunk) {
            $batch = $this->upload('eleves-' . $index . '.csv', self::csv($header, $chunk), ['kind' => 'students', 'entry_date' => '2026-09-07']);
            $applied = $this->ok('POST', '/secondary/migration/batches/' . $batch['id'] . '/apply', [], self::ADMIN, self::TA, $this->school);
            self::assertSame(count($chunk), $applied['summary']['applied'], (string) json_encode([$applied['summary'], array_slice($applied['lines'], 0, 3)], JSON_UNESCAPED_UNICODE));
        }
        $structures = array_column(array_map(static fn (string $l, array $c): array => [$c['structure'], $l], array_keys($this->classes), $this->classes), 1, 0);
        foreach ($this->pdo()->query("SELECT person_id, class_structure_id FROM se_enrolment_records WHERE establishment_id = '" . $this->school . "' AND academic_year_id = '" . self::YEAR . "' ORDER BY person_id")->fetchAll() as $row) {
            $this->classes[$structures[(string) $row['class_structure_id']]]['people'][] = (string) $row['person_id'];
        }
        self::assertSame(self::students(), array_sum(array_map(static fn (array $c): int => count($c['people']), $this->classes)), 'Toutes les inscriptions reprises.');
        self::$results['T977_reprise'] = ['classes' => count($rows), 'eleves' => self::students(), 'classes_s' => round($t1 - $t0, 2), 'eleves_s' => round(microtime(true) - $t1, 2)];

        foreach (self::SUBJECTS as $code => $coefficient) {
            $subject = $this->ok('POST', '/secondary/setup/subjects', ['code' => $code, 'label' => 'Matière ' . $code . ' (fictive)'], self::ADMIN, self::TA, $this->school);
            foreach (self::LEVELS as $level) {
                $this->ok('POST', '/secondary/setup/coefficients', [
                    'academic_year_id' => self::YEAR, 'level_code' => $level, 'subject_ref' => $subject['id'], 'coefficient' => $coefficient, 'weekly_hours' => 2,
                    'subject_role' => 'official', 'origin' => 'example', 'valid_from' => '2026-09-01', 'reason' => 'Valeur d\'exemple fictive',
                ], self::ADMIN, self::TA, $this->school);
            }
        }
        foreach (self::LEVELS as $level) {
            $first = array_values(array_filter($this->classes, static fn (array $c): bool => $c['level'] === $level))[0];
            $draft = $this->ok('GET', '/secondary/councils/rule-draft?class_id=' . $first['structure'] . '&scope=level', null, self::ADMIN, self::TA, $this->school);
            foreach ($draft['coefficients'] as $line) {
                $this->subjects[strtoupper((string) $line['code'])] = (string) $line['subject_id'];
            }
            $this->ok('POST', '/calculation-rules', ['academic_year_id' => self::YEAR, 'label' => (string) $draft['label'], 'definition' => $draft['definition'], 'structure_id' => $draft['structure_id']], self::ADMIN, self::TA, $this->school);
        }
        self::assertCount(count(self::SUBJECTS), $this->subjects);
    }

    /**
     * Une feuille : épreuve, notes de toute la classe par POST /grades/batch, verrouillage. Rend la durée de la saisie (s).
     *
     * @param list<string> $people
     */
    private function sheet(string $structure, string $subject, string $period, array $people, int $seed): float
    {
        $assessment = $this->ok('POST', '/assessments', [
            'subject_id' => $subject, 'structure_id' => $structure, 'period_id' => $period, 'academic_year_id' => self::YEAR, 'kind' => 'devoir',
            'title' => 'Devoir ' . $period, 'weight' => 1.0, 'max_score' => 20.0,
        ], self::ADMIN, self::TA, $this->school);
        $entries = [];
        foreach ($people as $i => $person) {
            $entries[] = ($i + $seed) % 37 === 0 ? ['person_id' => $person, 'absence' => 'justified'] : ['person_id' => $person, 'value' => (($i * 7 + $seed * 3) % 81) / 4];
        }
        $t0 = microtime(true);
        $this->ok('POST', '/grades/batch', ['assessment_id' => $assessment['id'], 'entries' => $entries], self::ADMIN, self::TA, $this->school);
        $elapsed = microtime(true) - $t0;
        foreach (['entry', 'locked'] as $state) {
            $this->ok('POST', '/assessments/' . $assessment['id'] . '/transition', ['to' => $state], self::ADMIN, self::TA, $this->school);
        }

        return $elapsed;
    }

    /** @param list<float> $values */
    private static function percentile(array $values, float $p): float
    {
        sort($values);

        return $values[max(0, (int) ceil($p * count($values)) - 1)];
    }

    public function testNfS1aNfS4MoyennesRangsBulletinsPicDeSaisieEtHorsLigne(): void
    {
        $tSetup = microtime(true);
        $this->school();

        // Saisie des notes (S1, S2) : 2 séquences x 10 matières x classes ; les 300 premières feuilles font le pic NF-S3.
        $latencies = [];
        $seed = 0;
        $t0 = microtime(true);
        foreach (['S1', 'S2'] as $period) {
            foreach ($this->classes as $class) {
                foreach ($this->subjects as $subject) {
                    $latencies[] = $this->sheet($class['structure'], $subject, $period, $class['people'], ++$seed);
                }
            }
        }
        $entrySeconds = microtime(true) - $t0;
        $peak = array_slice($latencies, 0, 300);
        self::$results['NF-S3_pic_de_saisie'] = [
            'feuilles' => count($peak), 'notes_par_feuille' => self::CLASS_SIZE, 'p50_ms' => round(1000 * self::percentile($peak, 0.5), 1), 'p95_ms' => round(1000 * self::percentile($peak, 0.95), 1),
            'max_ms' => round(1000 * max($peak), 1), 'feuilles_par_seconde_un_processus' => round(count($peak) / array_sum($peak), 1),
            'toutes_feuilles' => count($latencies), 'saisie_totale_s' => round($entrySeconds, 1), 'mode' => 'en processus, séquentiel (concurrence HTTP : k6 sur la recette)',
        ];
        self::$results['mise_en_place_s'] = round($t0 - $tSetup, 1);
        self::assertLessThan(1.0, self::percentile($peak, 0.95), 'NF-S3 : une feuille de 50 notes enregistrée en moins d\'une seconde (p95).');

        // NF-S1 : moyennes et rangs de tout l'établissement (conseil T1 de chaque classe).
        $councils = [];
        $lines = 0;
        $t0 = microtime(true);
        foreach ($this->classes as $label => $class) {
            $council = $this->ok('POST', '/class-councils', ['structure_id' => $class['structure'], 'period_id' => 'T1', 'period_label' => 'Trimestre 1'], self::ADMIN, self::TA, $this->school);
            $councils[$label] = (string) $council['id'];
            $lines += count($council['lines']);
        }
        $nfs1 = microtime(true) - $t0;
        self::assertSame(self::students(), $lines, 'Une ligne de conseil par élève.');
        self::$results['NF-S1_moyennes_et_rangs'] = ['eleves' => $lines, 'classes' => count($councils), 'secondes' => round($nfs1, 2), 'seuil_s' => 60, 'ok' => $nfs1 < 60];
        self::assertLessThan(60.0, $nfs1, 'NF-S1 : moyennes et rangs de ' . $lines . ' élèves en moins de 60 s.');

        // NF-S2 : bulletins (validation et émission) de tout l'établissement, rendu PDF Dompdf d'un échantillon projeté.
        self::assertTrue($this->ok('POST', '/secondary/councils/report-card-settings/preset', [], self::ADMIN, self::TA, $this->school)['applied']);
        $t0 = microtime(true);
        $documents = [];
        foreach ($councils as $id) {
            $this->ok('POST', '/class-councils/' . $id . '/validate', [], self::ADMIN, self::TA, $this->school);
            foreach ($this->ok('POST', '/class-councils/' . $id . '/report-cards', [], self::ADMIN, self::TA, $this->school) as $card) {
                self::assertSame('issued', $card['status'], (string) json_encode($card, JSON_UNESCAPED_UNICODE));
                $documents[] = (string) $card['document_id'];
            }
        }
        $issue = microtime(true) - $t0;
        self::assertCount(self::students(), $documents);
        $sample = array_slice($documents, 0, max(1, (int) (getenv('SECONDARY_LOAD_PDF_SAMPLE') ?: 100)));
        $bytes = 0;
        $times = [];
        foreach ($sample as $document) {
            $t0 = microtime(true);
            $pdf = $this->raw('GET', '/documents/' . $document . '/preview', null, self::ADMIN, self::TA, $this->school);
            $times[] = microtime(true) - $t0;
            self::assertSame(200, $pdf->getStatusCode(), (string) $pdf->getBody());
            $body = (string) $pdf->getBody();
            self::assertStringStartsWith('%PDF', $body);
            $bytes += strlen($body);
        }
        // Le premier rendu construit le cache des polices de Dompdf : il est exclu de la moyenne s'il y a d'autres rendus.
        $steady = count($times) > 1 ? array_slice($times, 1) : $times;
        $render = array_sum($steady) / count($steady);
        $projected = $issue + $render * count($documents);
        self::$results['NF-S2_bulletins'] = [
            'bulletins_emis' => count($documents), 'emission_s' => round($issue, 1), 'emission_ms_par_bulletin' => round(1000 * $issue / count($documents), 1),
            'pdf_echantillon' => count($sample), 'pdf_premier_ms' => round(1000 * $times[0], 1), 'pdf_ms_par_bulletin' => round(1000 * $render, 1),
            'pdf_p95_ms' => round(1000 * self::percentile($steady, 0.95), 1), 'pdf_kio_moyen' => (int) round($bytes / 1024 / count($sample)),
            'projection_totale_min' => round($projected / 60, 1), 'seuil_min' => 15, 'ok' => $projected < 900,
            'processus_paralleles_requis' => (int) ceil($projected / 900), 'memoire_pointe_mio' => round(memory_get_peak_usage(true) / 1048576),
        ];
        // Mesure rapportée ; bloquante seulement avec SECONDARY_LOAD_STRICT=1 (le rendu Dompdf du poste de mesure n'est pas celui du serveur).
        if (getenv('SECONDARY_LOAD_STRICT') === '1') {
            self::assertLessThan(900.0, $projected, 'NF-S2 : ' . count($documents) . ' bulletins PDF en moins de 15 minutes (projection, un processus).');
        }

        $this->offline(array_values($this->classes)[0]);
        self::$results['base'] = self::driver();
        self::$results['mesure_le'] = date('c');
    }

    /**
     * NF-S4 : la file hors ligne d'un enseignant (une feuille de séquence S3) est envoyée deux fois (reprise après coupure) ;
     * une note modifiée entre-temps par un collègue produit un 409 qui conserve la version du serveur.
     *
     * @param array{structure: string, level: string, people: list<string>} $class
     */
    private function offline(array $class): void
    {
        $subject = array_values($this->subjects)[0];
        $assessment = $this->ok('POST', '/assessments', [
            'subject_id' => $subject, 'structure_id' => $class['structure'], 'period_id' => 'S3', 'academic_year_id' => self::YEAR, 'kind' => 'devoir',
            'title' => 'Devoir hors ligne', 'weight' => 1.0, 'max_score' => 20.0,
        ], self::ADMIN, self::TA, $this->school);
        $queue = [];
        foreach ($class['people'] as $i => $person) {
            $queue[] = ['client_uuid' => 'hl-' . $i, 'assessment_id' => $assessment['id'], 'person_id' => $person, 'base_version' => 0, 'value' => ($i % 20) + 0.5];
        }
        $t0 = microtime(true);
        $first = $this->ok('POST', '/grades/sync', ['entries' => $queue], self::ADMIN, self::TA, $this->school);
        $replay = $this->ok('POST', '/grades/sync', ['entries' => $queue], self::ADMIN, self::TA, $this->school);
        $elapsed = microtime(true) - $t0;
        $count = fn (): int => (int) $this->pdo()->query("SELECT COUNT(*) FROM grades WHERE assessment_id = '" . $assessment['id'] . "'")->fetchColumn();
        self::assertSame(count($queue), $count(), 'Aucune perte, aucun doublon après rejeu.');
        $statuses = array_count_values(array_column($first['results'], 'status'));
        $replayed = array_count_values(array_column($replay['results'], 'status'));

        // Un collègue corrige la note du premier élève ; la file périmée (base_version 1) arrive ensuite : 409, serveur conservé.
        $this->ok('POST', '/grades/batch', ['assessment_id' => $assessment['id'], 'entries' => [['person_id' => $class['people'][0], 'value' => 19.0]]], self::ADMIN, self::TA, $this->school);
        [$status, $problem] = $this->call('POST', '/grades/sync', ['entries' => [['client_uuid' => 'hl-late', 'assessment_id' => $assessment['id'], 'person_id' => $class['people'][0], 'base_version' => 1, 'value' => 3.0]]], self::ADMIN, self::TA, $this->school);
        self::assertSame(409, $status, (string) json_encode($problem, JSON_UNESCAPED_UNICODE));
        $kept = (float) $this->pdo()->query("SELECT score FROM grades WHERE assessment_id = '" . $assessment['id'] . "' AND person_id = '" . $class['people'][0] . "'")->fetchColumn();
        self::assertSame(19.0, $kept, 'Le conflit ne remplace jamais la note du serveur.');
        self::$results['NF-S4_hors_ligne'] = [
            'notes_en_file' => count($queue), 'premier_envoi' => $statuses, 'rejeu' => $replayed, 'notes_en_base' => $count(), 'perte' => 0, 'conflit_409_conserve' => true,
            'deux_envois_ms' => round(1000 * $elapsed, 1), 'note' => 'La file du navigateur (appel, notes, cahier de textes) est couverte par Playwright (T976).',
        ];
    }

    public function testNfS5CentClassesContreLeCalculDeReference(): void
    {
        $t0 = microtime(true);
        (new ReferenceCalculationTest('testCentClassesSansAucunEcartAvecLeCalculDeReference'))->testCentClassesSansAucunEcartAvecLeCalculDeReference();
        self::$results['NF-S5_exactitude'] = ['classes' => 100, 'ecart' => '0,00 (moyennes au centième et rangs identiques)', 'secondes' => round(microtime(true) - $t0, 2)];
        self::assertTrue(true);
    }

    /** Base réellement utilisée (le bootstrap MySQL remplace la fabrique de connexions). */
    public function testBaseUtilisee(): void
    {
        $driver = (string) $this->service(ConnectionFactory::class)->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        self::assertSame(self::driver(), $driver);
        self::$results['pilote_pdo'] = $driver;
    }
}

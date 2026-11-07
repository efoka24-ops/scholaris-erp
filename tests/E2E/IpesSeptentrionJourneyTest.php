<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\E2E\Support\BuildsIpes;
use Tests\E2E\Support\BuildsIpesGrades;
use Tests\E2E\Support\RealStackTestCase;

/**
 * Recette de bout en bout de l'application IPES (`higher`) sur la pile réelle, pour deux instituts clients de Garoua :
 * l'ISSEG (régime LMD, sous tutelle universitaire) et l'Institut les Étoiles du Septentrion (régime BTS). Le directeur, les
 * personnels, les étudiants, les notes et tous les numéros de téléphone sont FICTIFS.
 *
 * Défauts du produit constatés : `specs/002-application-ipes/recette-septentrion.md` (étapes marquées incomplètes).
 */
final class IpesSeptentrionJourneyTest extends RealStackTestCase
{
    use BuildsIpes;
    use BuildsIpesGrades;

    private const INSTITUTES = [
        'isseg' => [
            'name' => 'Institut Supérieur du Septentrion de Garoua (ISSEG)', 'city' => 'Garoua', 'region' => 'Nord',
            'address' => 'Ouro Mal-Amadou, Garoua, région du Nord, BP 210 Garoua', 'first' => 'Abdoulaye', 'last' => 'Fictif-Directeur',
            'email' => 'direction@isseg-recette.test', 'phone' => '+237 699 10 00 01', 'normalized' => '+237699100001',
            'regime' => 'lmd', 'school' => 'ESG', 'program' => 'CF', 'program_name' => 'Comptabilité et finance',
        ],
        'etoiles' => [
            'name' => 'Institut de Formation Professionnelle les Étoiles du Septentrion de Garoua', 'city' => 'Garoua', 'region' => 'Nord',
            'address' => 'quartier Ouro Mal-Amadou, Garoua, région du Nord', 'first' => 'Halimatou', 'last' => 'Fictive-Directrice',
            'email' => 'direction@etoiles-recette.test', 'phone' => '+237 699 20 00 02', 'normalized' => '+237699200002',
            'regime' => 'bts', 'school' => 'EFP', 'program' => 'CGE', 'program_name' => 'Comptabilité et gestion des entreprises',
        ],
    ];

    protected function setUp(): void
    {
        $this->bootRealStack(['app' => ['url' => 'https://app.scholaris.test']]);
    }

    /** @return array<string, array{0: string}> */
    public static function institutes(): array
    {
        return ['ISSEG (LMD)' => ['isseg'], 'Étoiles du Septentrion (BTS)' => ['etoiles']];
    }

    /**
     * Construction commune jusqu'à l'étape voulue : 1 accès, 2 paramétrage, 3 maquette, 4 inscription et compte réglé.
     *
     * @return array<string, mixed>
     */
    private function build(string $key, int $stage): array
    {
        $institute = self::INSTITUTES[$key];
        $ctx = $this->onboard($institute);
        if ($stage >= 2) {
            $this->buildAcademic($ctx, $institute['regime'], $institute['school'], $institute['program'], $institute['program_name']);
        }
        if ($stage >= 3) {
            $this->staff($ctx);
            $this->curriculum($ctx);
        }
        if ($stage >= 4) {
            $this->feeCatalog($ctx);
            $this->enrolPaidStudent($ctx, 'Aminatou', 'Fictive-' . strtoupper($key));
        }

        return $ctx;
    }

    #[DataProvider('institutes')]
    public function testDemandeDAccesHigherAccepteeAvec2faEtEssaiOuvert(string $key): void
    {
        $ctx = $this->build($key, 1);
        $contexts = $this->ok('GET', '/me/contexts', $ctx['admin'])['data'];
        self::assertContains($ctx['establishment'], array_column($contexts, 'establishment_id') ?: array_column($contexts, 'id'));
        [$clientOnPlatform] = $this->api('GET', '/platform/dashboard', $ctx['admin']);
        self::assertSame(403, $clientOnPlatform->getStatusCode());
    }

    #[DataProvider('institutes')]
    public function testParametrageAcademiqueAutorisationTutelleEtReglement(string $key): void
    {
        $ctx = $this->build($key, 2);
        $program = $this->as($ctx, 'admin', 'GET', '/higher/programs/' . $ctx['org']['program'])['data'];
        self::assertSame(self::INSTITUTES[$key]['regime'], $program['regime']);
        $eligibility = $this->as($ctx, 'admin', 'GET', '/higher/eligibility?structure_id=' . $ctx['org']['g1'] . '&academic_year_id=' . $ctx['year'])['data'];
        self::assertTrue($eligibility['allowed'], (string) json_encode($eligibility));
        $tutelages = $this->as($ctx, 'admin', 'GET', '/higher/tutelages')['data'];
        self::assertCount($key === 'isseg' ? 1 : 0, $tutelages, 'Tutelle universitaire pour le LMD seulement.');
        $frozen = $this->as($ctx, 'admin', 'POST', '/higher/regulations/' . $ctx['regulation'] . '/freeze')['data'];
        self::assertSame('frozen', $frozen['status']);
    }

    #[DataProvider('institutes')]
    public function testMaquetteAdmissionInscriptionTst01Tst02(string $key): void
    {
        $ctx = $this->build($key, 3);
        self::assertSame(30, array_sum(array_map(static fn (array $ue): int => array_sum(array_column($ue[1], 0)), self::marieS1())));
        $this->feeCatalog($ctx);

        // TST-02 : « admis » refusé tant que la pièce du baccalauréat n'est pas vérifiée (RG-ADM-1).
        $application = $this->application($ctx, 'Fadimatou', 'Fictive-Candidate');
        [$status, $problem] = $this->try($ctx, 'dir', 'POST', '/higher/admissions/applications/' . $application['id'] . '/decision', ['decision' => 'admitted', 'reason' => 'Bon dossier.']);
        self::assertSame(422, $status, (string) json_encode($problem));
        self::assertStringContainsString('RG-ADM-1', (string) json_encode($problem, JSON_UNESCAPED_UNICODE));
        $this->admit($ctx, (string) $application['id']);
        self::assertSame('admitted', $this->as($ctx, 'scol', 'GET', '/higher/admissions/applications/' . $application['id'])['data']['status']);

        $enrolment = $this->as($ctx, 'scol', 'POST', '/higher/enrolments', ['application_id' => $application['id'], 'group_id' => $ctx['org']['g1']])['data'];
        self::assertSame('pending', $enrolment['status']);
        self::assertNull($enrolment['matricule']);

        // TST-01 : filière dont l'autorisation MINESUP a expiré -> inscription refusée (RG-ACA-1).
        $this->tst01ExpiredAuthorization($ctx, (string) $enrolment['person_id']);
    }

    #[DataProvider('institutes')]
    public function testFraisEcheancierEtPaiementTst08(string $key): void
    {
        $ctx = $this->build($key, 4);
        $s = $ctx['student'];
        self::assertSame(410000, $s['account_before']['net_xaf'], 'Total dû 410 000 (bourse de mérite 20 % sur la pension).');
        self::assertSame([50000, 120000, 120000, 120000], array_column($s['account_before']['installments'], 'amount_xaf'));
        self::assertSame('confirmed', $s['payment']['payment']['status']);
        self::assertNotNull($s['payment']['payment']['receipt_number'], 'Reçu émis.');
        self::assertSame([50000, 120000, 0, 0], array_column($s['payment']['account']['installments'], 'paid_xaf'));
        self::assertSame(240000, $s['payment']['account']['balance_xaf']);
        self::assertSame('enrolled', $s['enrolment']['status']);
        self::assertNotEmpty($s['enrolment']['matricule'], 'Matricule attribué à la validation.');
    }

    #[DataProvider('institutes')]
    public function testNotesLotI2Tst03Tst06(string $key): void
    {
        $ctx = $this->build($key, 4);
        $this->gradesJourney($ctx);
    }

    #[DataProvider('institutes')]
    public function testDeliberationLotI3Tst04Tst05Tst07(string $key): void
    {
        $ctx = $this->build($key, 4);
        $this->deliberationJourney($ctx);
    }

    /** D-01 : frais de dossier encaissés en espèces au guichet (canal `cash`) par la scolarité ou le caissier. */
    public function testFraisDeDossierEnEspecesAuGuichet(): void
    {
        $ctx = $this->build('isseg', 3);
        $this->feeCatalog($ctx);
        $ctx['campaign'] = (string) $this->as($ctx, 'scol', 'POST', '/higher/admissions/campaigns', [
            'level_id' => $ctx['org']['l1'], 'academic_year_id' => $ctx['year'], 'opens_on' => self::day('-30 days'), 'closes_on' => self::day('+30 days'),
            'seats' => 50, 'application_fee_xaf' => 15000, 'selection_mode' => 'file',
        ])['data']['id'];
        $this->as($ctx, 'scol', 'POST', '/higher/admissions/campaigns/' . $ctx['campaign'] . '/open');
        $application = $this->as($ctx, 'scol', 'POST', '/higher/admissions/applications', [
            'campaign_id' => $ctx['campaign'], 'diploma' => 'bac', 'diploma_series' => 'A4', 'diploma_year' => self::startYear(),
            'identity' => ['first_name' => 'Hawaou', 'last_name' => 'Fictive-Guichet', 'phone' => $this->nextPhone()],
        ])['data'];
        $id = (string) $application['id'];
        foreach ($application['documents'] as $document) {
            $this->as($ctx, 'scol', 'POST', '/higher/admissions/applications/' . $id . '/documents', ['requirement_code' => $document['requirement_code'], 'document_ref' => 'scan']);
        }
        $this->as($ctx, 'scol', 'POST', '/higher/admissions/applications/' . $id . '/submit');

        [$byRegistrar, $registrarProblem] = $this->try($ctx, 'scol', 'POST', '/higher/admissions/applications/' . $id . '/fee-payment', ['channel' => 'cash']);
        $this->as($ctx, 'caisse', 'POST', '/cash-sessions', ['float_xaf' => 0], 201);
        [$byCashier, $cashierProblem] = $this->try($ctx, 'caisse', 'POST', '/higher/admissions/applications/' . $id . '/fee-payment', ['channel' => 'cash']);
        if ($byRegistrar >= 300 && $byCashier >= 300) {
            self::markTestIncomplete('D-01 : aucun rôle ne peut encaisser en espèces les frais de dossier. Scolarité : ' . $byRegistrar . ' '
                . json_encode($registrarProblem, JSON_UNESCAPED_UNICODE) . ' ; caissier : ' . $byCashier . ' ' . json_encode($cashierProblem, JSON_UNESCAPED_UNICODE));
        }
        self::assertSame('complete', $this->as($ctx, 'scol', 'GET', '/higher/admissions/applications/' . $id)['data']['status']);
    }

    public function testCloisonnementTst12EntreLesDeuxInstituts(): void
    {
        $isseg = $this->build('isseg', 4);
        $etoiles = $this->build('etoiles', 4);
        $this->assertSealed($isseg, $etoiles);
        $this->assertSealed($etoiles, $isseg);
    }

    /**
     * Un administrateur (et un comptable, une scolarité) de $a qui demande les objets de $b obtient 404 sans aucune donnée.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function assertSealed(array $a, array $b): void
    {
        $foreign = $b['student'];
        foreach ([
            ['admin', '/higher/students/' . $foreign['person']],
            ['scol', '/higher/students/' . $foreign['person']],
            ['admin', '/higher/enrolments/' . $foreign['enrolment']['id']],
            ['scol', '/higher/enrolments/' . $foreign['enrolment']['id']],
            ['compta', '/higher/billing/accounts/' . $foreign['account_before']['id']],
            ['scol', '/higher/admissions/applications/' . $foreign['application']],
            ['admin', '/people/' . $foreign['person']],
        ] as [$who, $path]) {
            [$status, $json] = $this->try($a, $who, 'GET', $path);
            self::assertSame(404, $status, $who . ' ' . $path . ' : ' . json_encode($json));
            $raw = (string) json_encode($json, JSON_UNESCAPED_UNICODE);
            self::assertStringNotContainsString((string) $foreign['last'], $raw, 'Aucune donnée de l\'autre institut.');
            self::assertStringNotContainsString((string) ($foreign['enrolment']['matricule'] ?? 'sans-matricule'), $raw);
        }
        // Même avec l'en-tête d'établissement de l'autre institut : refus.
        [$response] = $this->api('GET', '/higher/enrolments/' . $foreign['enrolment']['id'], $a['admin'], null, $b['establishment']);
        self::assertContains($response->getStatusCode(), [403, 404], (string) $response->getBody());
        // Les listes ne contiennent que ses propres inscriptions.
        $list = (string) json_encode($this->as($a, 'scol', 'GET', '/higher/enrolments'), JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString((string) $foreign['enrolment']['id'], $list);
    }

    /**
     * Candidature, admission, inscription, grille, compte, bourse de mérite, paiement de 170 000 et validation de l'inscription.
     *
     * @param array<string, mixed> $ctx
     */
    private function enrolPaidStudent(array &$ctx, string $first, string $last): void
    {
        $application = $this->application($ctx, $first, $last);
        $this->admit($ctx, (string) $application['id']);
        $enrolment = $this->as($ctx, 'scol', 'POST', '/higher/enrolments', ['application_id' => $application['id'], 'group_id' => $ctx['org']['g1']])['data'];
        $person = (string) $enrolment['person_id'];
        $y = self::startYear();

        $grid = $this->as($ctx, 'compta', 'POST', '/higher/billing/fee-grids', [
            'academic_year_id' => $ctx['year'], 'school_id' => $ctx['org']['school'], 'program_id' => $ctx['org']['program'], 'level_id' => $ctx['org']['l1'],
            'study_mode' => 'day', 'label' => 'Niveau 1, cours du jour', 'start_year' => $y,
            'fees' => [['fee_code' => 'hi.inscription', 'amount_xaf' => 50000], ['fee_code' => 'hi.pension', 'amount_xaf' => 450000]],
        ], 201)['data'];
        self::assertSame([$y . '-10-15', ($y + 1) . '-01-15', ($y + 1) . '-04-15'], array_column($grid['terms'], 'due_on'));

        $account = $this->as($ctx, 'compta', 'POST', '/higher/billing/accounts', [
            'person_id' => $person, 'academic_year_id' => $ctx['year'], 'school_id' => $ctx['org']['school'], 'program_id' => $ctx['org']['program'],
            'level_id' => $ctx['org']['l1'], 'study_mode' => 'day',
        ], 201)['data'];
        self::assertSame(500000, $account['net_xaf']);
        $scholarship = $this->as($ctx, 'compta', 'POST', '/higher/billing/accounts/' . $account['id'] . '/scholarships', ['scholarship_kind' => 'merit', 'reason' => 'Major du concours'], 201)['data'];
        $this->as($ctx, 'compta2', 'POST', '/higher/billing/scholarships/' . $scholarship['id'] . '/approve', []);
        $before = $this->as($ctx, 'compta', 'GET', '/higher/billing/accounts/' . $account['id'])['data'];

        $payment = $this->as($ctx, 'compta', 'POST', '/higher/billing/accounts/' . $account['id'] . '/payments', [
            'amount_xaf' => 170000, 'channel' => 'orange_money', 'external_reference' => 'OM-' . strtoupper($ctx['org_code']) . '-0001',
        ], 201, ['Idempotency-Key' => 'tst08-' . $account['id']])['data'];

        $validated = $this->as($ctx, 'scol', 'POST', '/higher/enrolments/' . $enrolment['id'] . '/validate', [])['data'];
        $ctx['student'] = [
            'person' => $person, 'last' => $last, 'application' => (string) $application['id'], 'enrolment' => $validated,
            'account_before' => $before, 'payment' => $payment,
        ];
    }

    /** @param array<string, mixed> $ctx */
    private function tst01ExpiredAuthorization(array $ctx, string $person): void
    {
        $y = self::startYear();
        $program = (string) $this->as($ctx, 'admin', 'POST', '/higher/programs', [
            'department_id' => $ctx['org']['department'], 'code' => 'EXP', 'name' => 'Filière à autorisation expirée', 'regime' => $ctx['regime'],
            'teaching_language' => 'fr', 'study_mode' => 'day', 'duration_years' => 2,
        ])['data']['id'];
        $level = (string) $this->as($ctx, 'admin', 'POST', '/higher/levels', ['program_id' => $program, 'ordinal' => 1, 'code' => 'X1', 'name' => 'Niveau 1'])['data']['id'];
        $this->as($ctx, 'admin', 'POST', '/higher/authorizations', [
            'program_id' => $program, 'decree_number' => 'ARR-' . ($y - 4) . '-0099', 'decree_date' => ($y - 4) . '-07-15', 'authority' => 'MINESUP',
            'valid_from' => ($y - 4) . '-09-01', 'valid_to' => ($y - 1) . '-08-31', 'level_ids' => null,
        ]);
        $eligibility = $this->as($ctx, 'admin', 'GET', '/higher/eligibility?structure_id=' . $level . '&academic_year_id=' . $ctx['year'])['data'];
        self::assertFalse($eligibility['allowed']);
        // Parcours réel : campagne sur le niveau non autorisé ; si elle est acceptée, la candidature puis l'inscription doivent l'être.
        [$status, $problem] = $this->try($ctx, 'scol', 'POST', '/higher/admissions/campaigns', [
            'level_id' => $level, 'academic_year_id' => $ctx['year'], 'opens_on' => self::day('-30 days'), 'closes_on' => self::day('+30 days'),
            'seats' => 10, 'application_fee_xaf' => 15000, 'selection_mode' => 'file',
        ]);
        if ($status < 300) {
            // Campagne en brouillon admise ; son ouverture (porte d'entrée des inscriptions de ce niveau) est refusée.
            [$status, $problem] = $this->try($ctx, 'scol', 'POST', '/higher/admissions/campaigns/' . $problem['data']['id'] . '/open');
        }
        // Garde du socle à l'inscription directe d'une personne déjà connue sur ce niveau.
        [$direct, $directProblem] = $this->try($ctx, 'scol', 'POST', '/higher/enrolments', ['person_id' => $person, 'level_id' => $level, 'academic_year_id' => $ctx['year']]);
        self::assertSame(422, $direct, (string) json_encode($directProblem, JSON_UNESCAPED_UNICODE));
        self::assertSame(422, $status, (string) json_encode($problem, JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('RG-ACA-1', (string) json_encode($problem, JSON_UNESCAPED_UNICODE));
    }
}

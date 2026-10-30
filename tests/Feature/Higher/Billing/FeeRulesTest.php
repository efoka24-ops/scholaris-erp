<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Billing;

use Tests\Support\Higher\Billing\HigherFinanceTestCase;

/**
 * Règles FIN de l'IPES (T243, T245-T251) : remises sur la pension seulement et plafonnées (RG-FIN-1), grille la plus précise
 * (école, filière, niveau, mode), échéancier négocié validé par un autre comptable, tiers payeur (S13), blocages (RG-FIN-5),
 * caisse et permissions, types de frais déclarés au socle (S10).
 */
final class FeeRulesTest extends HigherFinanceTestCase
{
    protected function setUp(): void
    {
        $this->bootFinance();
        $this->seedFeeCatalog();
    }

    public function testLesTypesDeFraisDeLIpesSontDeclaresAuSocle(): void
    {
        [$response, $json] = $this->api('GET', '/higher/billing/fee-kinds', 'comptable', self::T, self::E);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['hi.dossier', 'hi.inscription', 'hi.pension', 'hi.examen_national', 'hi.soutenance', 'hi.stage', 'hi.carte', 'hi.assurance', 'hi.reversement_tutelle'],
            array_column($json['data'], 'code'),
        );
        foreach (['defense', 'internship', 'student_card', 'insurance', 'tutelage_fee'] as $kind) {
            [$created] = $this->api('POST', '/fee-items', 'comptable', self::T, self::E, ['kind' => $kind, 'name' => 'Frais ' . $kind, 'amount_xaf' => 1000]);
            self::assertSame(201, $created->getStatusCode(), $kind . ' doit être un type de frais accepté par le socle');
        }
    }

    public function testRemiseSurLInscriptionRefuseeEtPlafondDesBourses(): void
    {
        $this->cdcGrid();
        $account = $this->openAccount();
        $path = '/higher/billing/accounts/' . $account['id'] . '/scholarships';

        [$registration] = $this->api('POST', $path, 'comptable', self::T, self::E, ['scholarship_kind' => 'merit', 'applies_to' => 'registration', 'rate_value' => 10, 'reason' => 'Essai']);
        self::assertSame(422, $registration->getStatusCode(), 'RG-FIN-1 : remise sur l\'inscription refusée');

        [$staff] = $this->api('POST', $path, 'comptable', self::T, self::E, ['scholarship_kind' => 'staff', 'rate_value' => 40, 'reason' => 'Enfant du personnel']);
        self::assertSame(201, $staff->getStatusCode());
        [$over, $json] = $this->api('POST', $path, 'comptable', self::T, self::E, ['scholarship_kind' => 'merit', 'reason' => 'Major']);
        self::assertSame(422, $over->getStatusCode(), 'Plafond par défaut 50 % : 40 % + 20 % refusé');
        self::assertStringContainsString('Plafond', (string) json_encode($json, JSON_UNESCAPED_UNICODE));

        // Plafond de l'école EC abaissé à 30 % (surcharge par école, RG-ORG-5).
        $this->settings->values['billing.discount_cap_percent@EC'] = 30;
        [$capped] = $this->api('POST', $path, 'comptable', self::T, self::E, ['scholarship_kind' => 'sibling', 'calc_mode' => 'fixed', 'rate_value' => 1000, 'reason' => 'Fratrie']);
        self::assertSame(422, $capped->getStatusCode());

        [$partner] = $this->api('POST', '/higher/billing/accounts/' . $this->openAccount('yann')['id'] . '/scholarships', 'comptable', self::T, self::E, ['scholarship_kind' => 'partnership', 'rate_value' => 25, 'reason' => 'Convention Orange']);
        self::assertSame(201, $partner->getStatusCode());
        [$noRate] = $this->api('POST', $path, 'comptable', self::T, self::E, ['scholarship_kind' => 'sibling', 'reason' => 'Fratrie']);
        self::assertSame(422, $noRate->getStatusCode(), 'Aucun taux de fratrie par défaut (decisions.md R24)');
    }

    public function testLaGrilleLaPlusPreciseEstAppliquee(): void
    {
        $this->cdcGrid();
        [$school] = $this->api('POST', '/higher/billing/fee-grids', 'comptable', self::T, self::E, [
            'academic_year_id' => self::YEAR, 'school_id' => 'EC', 'label' => 'Toutes filières EC',
            'fees' => [['fee_code' => 'hi.inscription', 'amount_xaf' => 40000], ['fee_code' => 'hi.pension', 'amount_xaf' => 300000]], 'start_year' => 2026,
        ]);
        self::assertSame(201, $school->getStatusCode());
        [$duplicate] = $this->api('POST', '/higher/billing/fee-grids', 'comptable', self::T, self::E, [
            'academic_year_id' => self::YEAR, 'school_id' => 'EC', 'label' => 'Doublon', 'fees' => [['fee_code' => 'hi.inscription', 'amount_xaf' => 1]], 'start_year' => 2026,
        ]);
        self::assertSame(409, $duplicate->getStatusCode());
        [$badFee] = $this->api('POST', '/higher/billing/fee-grids', 'comptable', self::T, self::E, [
            'academic_year_id' => self::YEAR, 'school_id' => 'IT', 'label' => 'X', 'fees' => [['fee_code' => 'inconnu', 'amount_xaf' => 1]], 'start_year' => 2026,
        ]);
        self::assertSame(422, $badFee->getStatusCode());

        self::assertSame(500000, $this->openAccount()['net_xaf'], 'Filière, niveau et mode du jour : grille CDC');
        $evening = $this->openAccount('yann', ['study_mode' => 'evening']);
        self::assertSame(340000, $evening['net_xaf'], 'Cours du soir : grille de l\'école');
        [$again] = $this->api('POST', '/higher/billing/accounts', 'comptable', self::T, self::E, [
            'person_id' => 'marie', 'academic_year_id' => self::YEAR, 'school_id' => 'EC', 'program_id' => 'CF', 'level_id' => 'CF-L1',
        ]);
        self::assertSame(409, $again->getStatusCode(), 'Un compte par étudiant, année et filière');
    }

    public function testEcheancierNegocieValideParUnAutreComptable(): void
    {
        $this->cdcGrid();
        [$created, $plan] = $this->api('POST', '/higher/billing/payment-plans', 'comptable', self::T, self::E, [
            'person_id' => 'marie', 'academic_year_id' => self::YEAR, 'school_id' => 'EC', 'program_id' => 'CF',
            'terms' => [['due_on' => '2026-11-30', 'weight' => 1], ['due_on' => '2027-03-31', 'weight' => 2]], 'reason' => 'Revenus saisonniers du garant',
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        [$pending] = $this->api('POST', '/higher/billing/accounts', 'comptable', self::T, self::E, [
            'person_id' => 'marie', 'academic_year_id' => self::YEAR, 'school_id' => 'EC', 'program_id' => 'CF', 'level_id' => 'CF-L1', 'study_mode' => 'day',
            'plan_id' => $plan['data']['id'],
        ]);
        self::assertSame(422, $pending->getStatusCode(), 'Échéancier non validé');
        [$self] = $this->api('POST', '/higher/billing/payment-plans/' . $plan['data']['id'] . '/approve', 'comptable', self::T, self::E, []);
        self::assertSame(403, $self->getStatusCode());
        [$ok] = $this->api('POST', '/higher/billing/payment-plans/' . $plan['data']['id'] . '/approve', 'comptable2', self::T, self::E, []);
        self::assertSame(200, $ok->getStatusCode());

        $account = $this->openAccount('marie', ['plan_id' => $plan['data']['id']]);
        self::assertSame([50000, 150000, 300000], array_column($account['installments'], 'amount_xaf'));
        self::assertSame(['2026-11-30', '2026-11-30', '2027-03-31'], array_column($account['installments'], 'due_on'));

        [$invalid] = $this->api('POST', '/higher/billing/payment-plans', 'comptable', self::T, self::E, [
            'person_id' => 'yann', 'academic_year_id' => self::YEAR, 'school_id' => 'EC', 'terms' => [['due_on' => '2027-03-31'], ['due_on' => '2026-11-30']], 'reason' => 'x',
        ]);
        self::assertSame(422, $invalid->getStatusCode(), 'Dates non croissantes');
    }

    public function testPriseEnChargeParUnTiersAvecFactureAuTiers(): void
    {
        $this->cdcGrid();
        [$created, $sponsor] = $this->api('POST', '/higher/billing/sponsors', 'comptable', self::T, self::E, [
            'sponsor_kind' => 'organisation', 'name' => 'Brasseries du Cameroun', 'reference' => 'CONV-2026-12', 'contact' => 'rh@example.cm',
        ]);
        self::assertSame(201, $created->getStatusCode());
        $account = $this->openAccount('marie', ['sponsor_id' => $sponsor['data']['id']]);
        self::assertSame('Brasseries du Cameroun', $account['payer_name']);
        $this->openAccount('yann');

        [, $statement] = $this->api('GET', '/higher/billing/sponsors/' . $sponsor['data']['id'], 'comptable', self::T, self::E);
        self::assertCount(1, $statement['data']['accounts']);
        self::assertSame(500000, $statement['data']['balance_xaf']);
    }

    public function testCaisseEtPermissions(): void
    {
        $this->cdcGrid();
        $account = $this->openAccount();
        [$closed, $json] = $this->pay($account['id'], 'caissier', 10000, 'cash', 'cash-closed-0001');
        self::assertSame(409, $closed, json_encode($json, JSON_THROW_ON_ERROR));
        [$noCash] = $this->pay($account['id'], 'comptable', 10000, 'cash', 'cash-noright-001');
        self::assertSame(403, $noCash, 'Les espèces exigent billing.cash');
        [$noRight] = $this->api('POST', '/higher/billing/fee-grids', 'caissier', self::T, self::E, ['academic_year_id' => self::YEAR, 'school_id' => 'IT', 'label' => 'X', 'fees' => [['fee_code' => 'hi.pension', 'amount_xaf' => 1]], 'start_year' => 2026]);
        self::assertSame(403, $noRight->getStatusCode());
        [$student] = $this->api('GET', '/higher/billing/accounts', 'marie', self::T, self::E);
        self::assertSame(403, $student->getStatusCode(), 'L\'étudiant passe par son portail');
    }

    public function testLesBlocagesSontDesactivesParDefautEtJamaisSurCoursNiComposition(): void
    {
        $this->cdcGrid();
        $this->openAccount();
        $this->clock->set('2027-02-20T09:00:00+01:00');
        [, $holds] = $this->api('GET', '/higher/billing/holds/marie', 'comptable', self::T, self::E);
        self::assertSame(['results' => false, 'documents' => false, 'reinscription' => false, 'courses' => false, 'exams' => false], $holds['data']['blocks']);
        self::assertSame(500000, $holds['data']['balance_xaf']);

        $this->settings->values['billing.blocks'] = ['results' => ['enabled' => true], 'courses' => ['enabled' => true], 'exams' => ['enabled' => true]];
        [, $holds] = $this->api('GET', '/higher/billing/holds/marie', 'comptable', self::T, self::E);
        self::assertTrue($holds['data']['blocks']['results']);
        self::assertFalse($holds['data']['blocks']['courses']);
        self::assertFalse($holds['data']['blocks']['exams']);
    }
}

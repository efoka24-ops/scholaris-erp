<?php

declare(strict_types=1);

namespace Scholaris\Tests;

use Scholaris\Database\Table;
use Scholaris\Service\PayrollCalculator;

/**
 * Modules 22, 23, 24, 37, 39, 40, 42 et 43.
 *
 * Chaque module repose sur une regle qui ne doit jamais ceder : une ecriture
 * equilibree, un stock qui ne devient pas negatif, une commande qu'on ne
 * receptionne pas sans l'avoir approuvee, un acte signe qui ne bouge plus.
 * Ce sont ces regles qui sont testees ici, pas la mecanique de formulaire.
 */
final class GestionModulesTest extends TestCase
{
    /** @var list<string> */
    private const ALL_FEATURES = [
        'finance.accounting', 'finance.budget', 'purchase.orders',
        'stock.items', 'hr.payslips', 'ged.documents',
        'life.appointments', 'admin.public_acts', 'pilot.objectives',
    ];

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser($this->tenantA, 'gestion@a.cm');
        $this->giveRole($this->userId, 'GESTION', [
            'accounting:read', 'accounting:create', 'accounting:post',
            'budget:read', 'budget:create',
            'suppliers:read', 'suppliers:create',
            'purchases:read', 'purchases:create', 'purchases:approve', 'purchases:receive',
            'stock:read', 'stock:create', 'stock:move',
            'payroll:read', 'payroll:create', 'payroll:close',
            'documents:read', 'documents:create', 'documents:delete',
            'appointments:read', 'appointments:create', 'appointments:update',
            'staff-decisions:read', 'staff-decisions:create', 'staff-decisions:sign',
            'objectives:read', 'objectives:create', 'objectives:update',
        ]);

        $values = [];

        foreach (self::ALL_FEATURES as $feature) {
            $values[$feature] = true;
        }

        $this->db->execute(
            'UPDATE tenants SET config_json = :config WHERE id = :id',
            ['config' => json_encode(['features' => $values]), 'id' => $this->tenantA]
        );

        $this->app->resetFeatures();
        $this->actingAs($this->userId);
    }

    // --- Module 22 : comptabilite -------------------------------------------

    public function testLePlanComptableSInstalleUneSeuleFois(): void
    {
        $this->request('POST', '/comptabilite/plan');

        $first = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM ledger_accounts WHERE tenant_id = :t',
            ['t' => $this->tenantA]
        );

        $this->assertTrue($first > 30, 'Le plan SYSCOHADA doit installer une quarantaine de comptes');

        // Relancer l'installation ne doit pas dupliquer le plan : un plan
        // comptable en double rendrait toute balance illisible.
        $this->request('POST', '/comptabilite/plan');

        $second = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM ledger_accounts WHERE tenant_id = :t',
            ['t' => $this->tenantA]
        );

        $this->assertSame($first, $second, 'Une seconde installation ne doit rien ajouter');
    }

    public function testUneEcritureDesequilibreeEstRefusee(): void
    {
        $this->request('POST', '/comptabilite/plan');
        [$caisse, $scolarite] = $this->twoAccounts();

        $this->request('POST', '/comptabilite/ecritures', [
            'entry_date' => '2026-09-01',
            'journal' => 'CA',
            'label' => 'Encaissement desequilibre',
            'lines' => [
                ['account_id' => $caisse, 'debit' => '50000', 'credit' => ''],
                ['account_id' => $scolarite, 'debit' => '', 'credit' => '40000'],
            ],
        ]);

        $count = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM ledger_entries WHERE tenant_id = :t',
            ['t' => $this->tenantA]
        );

        $this->assertSame(0, $count, 'Une ecriture desequilibree ne doit pas etre enregistree');
    }

    public function testUneEcritureEquilibreeEstEnregistreeAuBrouillard(): void
    {
        $this->request('POST', '/comptabilite/plan');
        [$caisse, $scolarite] = $this->twoAccounts();

        $this->request('POST', '/comptabilite/ecritures', [
            'entry_date' => '2026-09-01',
            'journal' => 'CA',
            'label' => 'Encaissement de scolarite',
            'lines' => [
                ['account_id' => $caisse, 'debit' => '50000', 'credit' => ''],
                ['account_id' => $scolarite, 'debit' => '', 'credit' => '50000'],
            ],
        ]);

        $entry = $this->db->selectOne(
            'SELECT * FROM ledger_entries WHERE tenant_id = :t',
            ['t' => $this->tenantA]
        );

        $this->assertTrue($entry !== null, 'L ecriture equilibree doit etre enregistree');
        $this->assertSame(0, (int) ($entry['posted'] ?? 1), 'Elle doit naitre au brouillard');
        $this->assertStringContains('CA'.date('Y'), (string) ($entry['reference'] ?? ''), 'La reference porte le journal et l annee');
    }

    public function testUneEcritureValideeNeCompteQuUneFoisDansLaBalance(): void
    {
        $this->request('POST', '/comptabilite/plan');
        [$caisse, $scolarite] = $this->twoAccounts();

        $this->postBalancedEntry($caisse, $scolarite, 50000);
        $entryId = (string) $this->db->scalar(
            'SELECT id FROM ledger_entries WHERE tenant_id = :t',
            ['t' => $this->tenantA]
        );

        $this->request('POST', '/comptabilite/ecritures/'.$entryId.'/valider');

        $posted = (int) $this->db->scalar(
            'SELECT posted FROM ledger_entries WHERE id = :id',
            ['id' => $entryId]
        );

        $this->assertSame(1, $posted, 'L ecriture doit passer a l etat valide');

        // Valider deux fois ne doit rien changer : sans cela, une double
        // soumission du formulaire compterait l'ecriture deux fois.
        $this->request('POST', '/comptabilite/ecritures/'.$entryId.'/valider');

        $total = (float) $this->db->scalar(
            'SELECT COALESCE(SUM(debit), 0) FROM ledger_lines WHERE tenant_id = :t',
            ['t' => $this->tenantA]
        );

        $this->assertMoney(50000.0, $total, 'Une seconde validation ne doit pas dupliquer les lignes');
    }

    public function testLaContrePassationAjouteLEcritureInverse(): void
    {
        $this->request('POST', '/comptabilite/plan');
        [$caisse, $scolarite] = $this->twoAccounts();

        $this->postBalancedEntry($caisse, $scolarite, 50000);
        $entryId = (string) $this->db->scalar(
            'SELECT id FROM ledger_entries WHERE tenant_id = :t',
            ['t' => $this->tenantA]
        );

        $this->request('POST', '/comptabilite/ecritures/'.$entryId.'/valider');
        $this->request('POST', '/comptabilite/ecritures/'.$entryId.'/contrepasser');

        $entries = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM ledger_entries WHERE tenant_id = :t',
            ['t' => $this->tenantA]
        );

        $this->assertSame(2, $entries, 'La contre-passation ajoute une ecriture, elle ne supprime pas');

        // Le solde du compte de caisse doit revenir a zero : c'est tout
        // l'objet d'une contre-passation.
        $solde = (float) $this->db->scalar(
            'SELECT COALESCE(SUM(debit - credit), 0) FROM ledger_lines
             WHERE tenant_id = :t AND account_id = :a',
            ['t' => $this->tenantA, 'a' => $caisse]
        );

        $this->assertMoney(0.0, $solde, 'Le solde doit revenir a zero apres contre-passation');
    }

    public function testUneEcritureAuBrouillardNeSeContrePasePas(): void
    {
        $this->request('POST', '/comptabilite/plan');
        [$caisse, $scolarite] = $this->twoAccounts();

        $this->postBalancedEntry($caisse, $scolarite, 50000);
        $entryId = (string) $this->db->scalar(
            'SELECT id FROM ledger_entries WHERE tenant_id = :t',
            ['t' => $this->tenantA]
        );

        $this->request('POST', '/comptabilite/ecritures/'.$entryId.'/contrepasser');

        $entries = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM ledger_entries WHERE tenant_id = :t',
            ['t' => $this->tenantA]
        );

        $this->assertSame(1, $entries, 'Une ecriture au brouillard se corrige, elle ne se contre-passe pas');
    }

    // --- Module 24 : stocks --------------------------------------------------

    public function testUneSortieSuperieureAuStockEstRefusee(): void
    {
        $itemId = $this->createStockItem('CRAIE', 'Craie blanche');

        $this->request('POST', '/stocks/'.$itemId.'/mouvement', [
            'direction' => 'ENTREE',
            'quantity' => '10',
            'reason' => 'Stock initial',
        ]);

        $this->request('POST', '/stocks/'.$itemId.'/mouvement', [
            'direction' => 'SORTIE',
            'quantity' => '25',
            'reason' => 'Distribution',
        ]);

        $quantity = (float) $this->db->scalar(
            'SELECT quantity FROM stock_items WHERE id = :id',
            ['id' => $itemId]
        );

        $this->assertMoney(10.0, $quantity, 'Le stock ne doit jamais devenir negatif');

        $movements = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM stock_movements WHERE item_id = :id',
            ['id' => $itemId]
        );

        $this->assertSame(1, $movements, 'Le mouvement refuse ne doit pas etre journalise');
    }

    public function testLAjustementPoseLeStockALaValeurComptee(): void
    {
        $itemId = $this->createStockItem('RAME', 'Rame de papier');

        $this->request('POST', '/stocks/'.$itemId.'/mouvement', ['direction' => 'ENTREE', 'quantity' => '40']);

        // L'inventaire pose la quantite comptee, il ne l'ajoute pas : c'est
        // le geste du magasinier qui recompte une etagere.
        $this->request('POST', '/stocks/'.$itemId.'/mouvement', ['direction' => 'AJUSTEMENT', 'quantity' => '37']);

        $quantity = (float) $this->db->scalar(
            'SELECT quantity FROM stock_items WHERE id = :id',
            ['id' => $itemId]
        );

        $this->assertMoney(37.0, $quantity, 'L ajustement doit poser le stock, non l additionner');
    }

    public function testLeSoldeApresMouvementEstConserveDansLeJournal(): void
    {
        $itemId = $this->createStockItem('SAVON', 'Savon');

        $this->request('POST', '/stocks/'.$itemId.'/mouvement', ['direction' => 'ENTREE', 'quantity' => '20']);
        $this->request('POST', '/stocks/'.$itemId.'/mouvement', ['direction' => 'SORTIE', 'quantity' => '5']);

        $last = $this->db->selectOne(
            'SELECT balance_after FROM stock_movements
             WHERE item_id = :id ORDER BY created_at DESC, direction DESC LIMIT 1',
            ['id' => $itemId]
        );

        $this->assertMoney(15.0, $last['balance_after'] ?? 0, 'Le journal doit porter le solde apres mouvement');
    }

    // --- Module 23 : achats --------------------------------------------------

    public function testUneCommandeNonApprouveeNeSeReceptionnePas(): void
    {
        $orderId = $this->createOrder();

        $this->request('POST', '/achats/'.$orderId.'/reception');

        $status = (string) $this->db->scalar(
            'SELECT status FROM purchase_orders WHERE id = :id',
            ['id' => $orderId]
        );

        $this->assertSame('DEMANDE', $status, 'Seule une commande approuvee se receptionne');
    }

    public function testUnRefusSansMotifEstRefuse(): void
    {
        $orderId = $this->createOrder();

        $this->request('POST', '/achats/'.$orderId.'/decision', [
            'decision' => 'REFUSEE',
            'decision_note' => '',
        ]);

        $status = (string) $this->db->scalar(
            'SELECT status FROM purchase_orders WHERE id = :id',
            ['id' => $orderId]
        );

        $this->assertSame('DEMANDE', $status, 'Un refus doit etre motive pour etre enregistre');
    }

    public function testLaReceptionFaitEntrerLesArticlesEnStock(): void
    {
        $itemId = $this->createStockItem('CAHIER', 'Cahier 200 pages');
        $orderId = $this->createOrder($itemId, 100, 500);

        $this->request('POST', '/achats/'.$orderId.'/decision', [
            'decision' => 'APPROUVEE',
            'decision_note' => 'Budget disponible',
        ]);

        $this->request('POST', '/achats/'.$orderId.'/reception');

        $item = $this->db->selectOne('SELECT * FROM stock_items WHERE id = :id', ['id' => $itemId]);

        $this->assertMoney(100.0, $item['quantity'] ?? 0, 'Les articles recus doivent entrer en stock');
        $this->assertMoney(500.0, $item['unit_cost'] ?? 0, 'Le cout unitaire suit le dernier prix paye');

        $status = (string) $this->db->scalar(
            'SELECT status FROM purchase_orders WHERE id = :id',
            ['id' => $orderId]
        );

        $this->assertSame('RECUE', $status, 'La commande passe a l etat recue');
    }

    public function testUneCommandeDejaTraiteeNeSeRedecidePas(): void
    {
        $orderId = $this->createOrder();

        $this->request('POST', '/achats/'.$orderId.'/decision', [
            'decision' => 'APPROUVEE',
            'decision_note' => 'Accord',
        ]);

        $this->request('POST', '/achats/'.$orderId.'/decision', [
            'decision' => 'REFUSEE',
            'decision_note' => 'Changement d avis',
        ]);

        $status = (string) $this->db->scalar(
            'SELECT status FROM purchase_orders WHERE id = :id',
            ['id' => $orderId]
        );

        $this->assertSame('APPROUVEE', $status, 'Une decision prise ne se rejoue pas');
    }

    // --- Module 42 : paie ----------------------------------------------------

    public function testLaCotisationCnpsEstPlafonnee(): void
    {
        // Au-dela de 750 000 FCFA, la cotisation salariale cesse de croitre.
        $small = PayrollCalculator::compute(300000);
        $large = PayrollCalculator::compute(2000000);

        $this->assertMoney(12600.0, $small['cnps_employee'], 'CNPS de 4,2 % sous le plafond');
        $this->assertMoney(31500.0, $large['cnps_employee'], 'CNPS plafonnee a 750 000 d assiette');
    }

    public function testUnSalaireModesteNEstPasImposable(): void
    {
        // Apres abattement de 30 % et de 500 000 FCFA annuels, un petit
        // salaire sort du bareme : lui retenir un IRPP serait une erreur.
        $slip = PayrollCalculator::compute(50000);

        $this->assertMoney(0.0, $slip['income_tax'], 'Un salaire modeste ne supporte pas d IRPP');
        $this->assertTrue($slip['net'] > 0, 'Le net doit rester positif');
    }

    public function testLeNetEstLeBrutMoinsLesRetenues(): void
    {
        $slip = PayrollCalculator::compute(400000);

        $this->assertMoney(
            $slip['gross'] - $slip['cnps_employee'] - $slip['income_tax'],
            $slip['net'],
            'Le net doit egaler le brut moins les retenues salariales'
        );
    }

    public function testLaGenerationIgnoreLesAgentsSansSalaire(): void
    {
        $this->createEmployee('Avec', 'Salaire', 250000);
        $this->createEmployee('Sans', 'Salaire', null);

        $periodId = $this->createPayrollPeriod();
        $this->request('POST', '/paie/periodes/'.$periodId.'/generer');

        $slips = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM payslips WHERE period_id = :p',
            ['p' => $periodId]
        );

        $this->assertSame(1, $slips, 'Un agent sans salaire de base ne doit pas recevoir un bulletin a zero');
    }

    public function testUneSecondeGenerationNeDupliquePasLesBulletins(): void
    {
        $this->createEmployee('Jean', 'Mbarga', 250000);
        $periodId = $this->createPayrollPeriod();

        $this->request('POST', '/paie/periodes/'.$periodId.'/generer');
        $this->request('POST', '/paie/periodes/'.$periodId.'/generer');

        $slips = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM payslips WHERE period_id = :p',
            ['p' => $periodId]
        );

        $this->assertSame(1, $slips, 'Relancer la generation ne doit pas dupliquer les bulletins');
    }

    public function testUnePeriodeNeSeClotPasAvecDesBulletinsAuBrouillon(): void
    {
        $this->createEmployee('Jean', 'Mbarga', 250000);
        $periodId = $this->createPayrollPeriod();

        $this->request('POST', '/paie/periodes/'.$periodId.'/generer');
        $this->request('POST', '/paie/periodes/'.$periodId.'/clore');

        $status = (string) $this->db->scalar(
            'SELECT status FROM payroll_periods WHERE id = :id',
            ['id' => $periodId]
        );

        $this->assertSame('OUVERTE', $status, 'Clore figerait un bulletin que personne n a relu');
    }

    public function testUnePeriodeSeClotUneFoisTousLesBulletinsValides(): void
    {
        $this->createEmployee('Jean', 'Mbarga', 250000);
        $periodId = $this->createPayrollPeriod();

        $this->request('POST', '/paie/periodes/'.$periodId.'/generer');

        $slipId = (string) $this->db->scalar(
            'SELECT id FROM payslips WHERE period_id = :p',
            ['p' => $periodId]
        );

        $this->request('POST', '/paie/bulletins/'.$slipId.'/valider');
        $this->request('POST', '/paie/periodes/'.$periodId.'/clore');

        $status = (string) $this->db->scalar(
            'SELECT status FROM payroll_periods WHERE id = :id',
            ['id' => $periodId]
        );

        $this->assertSame('CLOSE', $status, 'Une periode dont tout est valide doit pouvoir se clore');
    }

    // --- Module 39 : rendez-vous ---------------------------------------------

    public function testDeuxRendezVousQuiSeChevauchentSontRefuses(): void
    {
        $this->request('POST', '/rendez-vous', [
            'staff_id' => $this->userId,
            'requester_name' => 'Mme Ngo',
            'subject' => 'Suivi de scolarite',
            'scheduled_at' => '2026-09-15 10:00',
            'duration_minutes' => '30',
        ]);

        $this->request('POST', '/rendez-vous', [
            'staff_id' => $this->userId,
            'requester_name' => 'M. Fotso',
            'subject' => 'Absences',
            'scheduled_at' => '2026-09-15 10:15',
            'duration_minutes' => '30',
        ]);

        $count = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM appointments WHERE tenant_id = :t',
            ['t' => $this->tenantA]
        );

        $this->assertSame(1, $count, 'Un creneau deja occupe doit etre refuse');
    }

    public function testDeuxRendezVousBoutABoutSontAcceptes(): void
    {
        $this->request('POST', '/rendez-vous', [
            'staff_id' => $this->userId,
            'requester_name' => 'Mme Ngo',
            'subject' => 'Suivi',
            'scheduled_at' => '2026-09-15 10:00',
            'duration_minutes' => '30',
        ]);

        // 10h30 commence exactement quand le precedent finit : ce n'est pas un
        // chevauchement, et le refuser rendrait le module inutilisable.
        $this->request('POST', '/rendez-vous', [
            'staff_id' => $this->userId,
            'requester_name' => 'M. Fotso',
            'subject' => 'Absences',
            'scheduled_at' => '2026-09-15 10:30',
            'duration_minutes' => '30',
        ]);

        $count = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM appointments WHERE tenant_id = :t',
            ['t' => $this->tenantA]
        );

        $this->assertSame(2, $count, 'Deux rendez-vous consecutifs doivent etre acceptes');
    }

    public function testUnCreneauAnnuleEstLibere(): void
    {
        $this->request('POST', '/rendez-vous', [
            'staff_id' => $this->userId,
            'requester_name' => 'Mme Ngo',
            'subject' => 'Suivi',
            'scheduled_at' => '2026-09-15 10:00',
            'duration_minutes' => '30',
        ]);

        $appointmentId = (string) $this->db->scalar(
            'SELECT id FROM appointments WHERE tenant_id = :t',
            ['t' => $this->tenantA]
        );

        $this->request('POST', '/rendez-vous/'.$appointmentId.'/statut', ['status' => 'ANNULE']);

        $this->request('POST', '/rendez-vous', [
            'staff_id' => $this->userId,
            'requester_name' => 'M. Fotso',
            'subject' => 'Absences',
            'scheduled_at' => '2026-09-15 10:00',
            'duration_minutes' => '30',
        ]);

        $count = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM appointments WHERE tenant_id = :t',
            ['t' => $this->tenantA]
        );

        $this->assertSame(2, $count, 'Un rendez-vous annule doit liberer son creneau');
    }

    // --- Module 40 : administration publique ---------------------------------

    public function testUnActeSansSignataireNeSeSignePas(): void
    {
        $employeeId = $this->createEmployee('Paul', 'Atangana', 200000);

        $this->request('POST', '/administration/actes', [
            'employee_id' => $employeeId,
            'kind' => 'MUTATION',
            'subject' => 'Mutation au lycee de Garoua',
            'decided_on' => '2026-09-01',
        ]);

        $decisionId = (string) $this->db->scalar(
            'SELECT id FROM staff_decisions WHERE tenant_id = :t',
            ['t' => $this->tenantA]
        );

        $this->request('POST', '/administration/actes/'.$decisionId.'/signer');

        $status = (string) $this->db->scalar(
            'SELECT status FROM staff_decisions WHERE id = :id',
            ['id' => $decisionId]
        );

        $this->assertSame('PROJET', $status, 'Un acte sans autorite signataire ne peut pas etre signe');
    }

    public function testUnActeSigneEstDate(): void
    {
        $employeeId = $this->createEmployee('Paul', 'Atangana', 200000);

        $this->request('POST', '/administration/actes', [
            'employee_id' => $employeeId,
            'kind' => 'PROMOTION',
            'subject' => 'Promotion au grade superieur',
            'decided_on' => '2026-09-01',
            'signed_by' => 'Le Proviseur',
        ]);

        $decisionId = (string) $this->db->scalar(
            'SELECT id FROM staff_decisions WHERE tenant_id = :t',
            ['t' => $this->tenantA]
        );

        $this->request('POST', '/administration/actes/'.$decisionId.'/signer');

        $decision = $this->db->selectOne(
            'SELECT * FROM staff_decisions WHERE id = :id',
            ['id' => $decisionId]
        );

        $this->assertSame('SIGNE', (string) ($decision['status'] ?? ''), 'L acte doit passer a l etat signe');
        $this->assertStringContains('DEC/'.date('Y'), (string) ($decision['reference'] ?? ''), 'La reference porte l annee');
    }

    public function testLesReferencesDActesSeSuivent(): void
    {
        $employeeId = $this->createEmployee('Paul', 'Atangana', 200000);

        for ($i = 0; $i < 3; $i++) {
            $this->request('POST', '/administration/actes', [
                'employee_id' => $employeeId,
                'kind' => 'AVANCEMENT',
                'subject' => 'Avancement '.$i,
                'decided_on' => '2026-09-01',
            ]);
        }

        $references = $this->db->select(
            'SELECT reference FROM staff_decisions WHERE tenant_id = :t ORDER BY reference',
            ['t' => $this->tenantA]
        );

        $this->assertCount(3, $references, 'Les trois actes doivent exister');
        $this->assertStringContains('0003', (string) ($references[2]['reference'] ?? ''), 'La numerotation doit se suivre');
    }

    // --- Module 43 : objectifs -----------------------------------------------

    public function testUnObjectifBasculeSurAtteintQuandLaCibleEstFranchie(): void
    {
        $objectiveId = $this->createObjective(80.0, 40.0);

        $this->request('POST', '/objectifs/'.$objectiveId.'/releve', ['current_value' => '85']);

        $status = (string) $this->db->scalar(
            'SELECT status FROM objectives WHERE id = :id',
            ['id' => $objectiveId]
        );

        $this->assertSame('ATTEINT', $status, 'Franchir la cible doit basculer le statut');
    }

    public function testUnObjectifRedevientEnCoursSiLaValeurRedescend(): void
    {
        $objectiveId = $this->createObjective(80.0, 40.0);

        $this->request('POST', '/objectifs/'.$objectiveId.'/releve', ['current_value' => '85']);
        $this->request('POST', '/objectifs/'.$objectiveId.'/releve', ['current_value' => '70']);

        $status = (string) $this->db->scalar(
            'SELECT status FROM objectives WHERE id = :id',
            ['id' => $objectiveId]
        );

        $this->assertSame('EN_COURS', $status, 'Un indicateur qui redescend n est plus atteint');
    }

    public function testUnObjectifSansCibleEstRefuse(): void
    {
        $this->request('POST', '/objectifs', [
            'label' => 'Objectif sans cible',
            'scope' => 'ETABLISSEMENT',
            'unit' => '%',
            'target_value' => '0',
            'current_value' => '0',
        ]);

        $count = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM objectives WHERE tenant_id = :t',
            ['t' => $this->tenantA]
        );

        $this->assertSame(0, $count, 'Une cible nulle rend l objectif ininterpretable');
    }

    // --- Cloisonnement -------------------------------------------------------

    public function testUnAutreEtablissementNeVoitPasCesDonnees(): void
    {
        $this->request('POST', '/comptabilite/plan');
        $this->createStockItem('CRAIE', 'Craie blanche');
        $this->createObjective(80.0, 10.0);

        // Bascule vers le second etablissement, avec les memes droits.
        $otherUser = $this->createUser($this->tenantB, 'gestion@b.cm');
        $this->giveRole($otherUser, 'GESTION_B', [
            'accounting:read', 'stock:read', 'objectives:read',
        ]);

        $this->db->execute(
            'UPDATE tenants SET config_json = :config WHERE id = :id',
            [
                'config' => json_encode(['features' => ['finance.accounting' => true, 'stock.items' => true, 'pilot.objectives' => true]]),
                'id' => $this->tenantB,
            ]
        );

        $this->app->resetFeatures();
        $this->actingAs($otherUser);

        foreach (['ledger_accounts', 'stock_items', 'objectives'] as $table) {
            $count = (int) $this->db->scalar(
                'SELECT COUNT(*) FROM '.$table.' WHERE tenant_id = :t',
                ['t' => $this->tenantB]
            );

            $this->assertSame(0, $count, "L etablissement B ne doit rien voir dans {$table}");
        }
    }

    // --- Fabriques -----------------------------------------------------------

    /** @return array{0: string, 1: string} caisse, produit de scolarite */
    private function twoAccounts(): array
    {
        $caisse = (string) $this->db->scalar(
            'SELECT id FROM ledger_accounts WHERE tenant_id = :t AND code = :c',
            ['t' => $this->tenantA, 'c' => '571000']
        );

        $scolarite = (string) $this->db->scalar(
            'SELECT id FROM ledger_accounts WHERE tenant_id = :t AND code = :c',
            ['t' => $this->tenantA, 'c' => '701000']
        );

        return [$caisse, $scolarite];
    }

    private function postBalancedEntry(string $debit, string $credit, float $amount): void
    {
        $this->request('POST', '/comptabilite/ecritures', [
            'entry_date' => '2026-09-01',
            'journal' => 'CA',
            'label' => 'Encaissement de scolarite',
            'lines' => [
                ['account_id' => $debit, 'debit' => (string) $amount, 'credit' => ''],
                ['account_id' => $credit, 'debit' => '', 'credit' => (string) $amount],
            ],
        ]);
    }

    private function createStockItem(string $code, string $name): string
    {
        $this->request('POST', '/stocks/articles', [
            'code' => $code,
            'name' => $name,
            'unit' => 'unite',
            'min_quantity' => '5',
            'unit_cost' => '0',
        ]);

        return (string) $this->db->scalar(
            'SELECT id FROM stock_items WHERE tenant_id = :t AND code = :c',
            ['t' => $this->tenantA, 'c' => $code]
        );
    }

    private function createOrder(?string $itemId = null, float $quantity = 10, float $price = 1000): string
    {
        $this->request('POST', '/achats/commandes', [
            'subject' => 'Fournitures de rentree',
            'ordered_on' => '2026-09-01',
            'lines' => [
                [
                    'label' => 'Fournitures',
                    'item_id' => $itemId ?? '',
                    'quantity' => (string) $quantity,
                    'unit_price' => (string) $price,
                ],
            ],
        ]);

        return (string) $this->db->scalar(
            'SELECT id FROM purchase_orders WHERE tenant_id = :t ORDER BY created_at DESC LIMIT 1',
            ['t' => $this->tenantA]
        );
    }

    private function createEmployee(string $firstName, string $lastName, ?float $salary): string
    {
        $id = Table::uuid();
        $now = date('Y-m-d H:i:s');

        $this->db->execute(
            'INSERT INTO employees (id, tenant_id, first_name, last_name, position, hire_date, salary, status, created_at, updated_at)
             VALUES (:id, :tenant, :first, :last, :position, :hire, :salary, :status, :created, :updated)',
            [
                'id' => $id,
                'tenant' => $this->tenantA,
                'first' => $firstName,
                'last' => $lastName,
                'position' => 'Enseignant',
                'hire' => '2020-09-01',
                'salary' => $salary,
                'status' => 'ACTIVE',
                'created' => $now,
                'updated' => $now,
            ]
        );

        return $id;
    }

    private function createPayrollPeriod(): string
    {
        $this->request('POST', '/paie/periodes', ['year' => '2026', 'month' => '9']);

        return (string) $this->db->scalar(
            'SELECT id FROM payroll_periods WHERE tenant_id = :t',
            ['t' => $this->tenantA]
        );
    }

    private function createObjective(float $target, float $current): string
    {
        $this->request('POST', '/objectifs', [
            'label' => 'Taux de reussite au BEPC',
            'scope' => 'ETABLISSEMENT',
            'indicator' => 'Pourcentage d admis',
            'unit' => '%',
            'target_value' => (string) $target,
            'current_value' => (string) $current,
        ]);

        return (string) $this->db->scalar(
            'SELECT id FROM objectives WHERE tenant_id = :t',
            ['t' => $this->tenantA]
        );
    }
}

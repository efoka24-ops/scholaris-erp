<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Services;

use Scholaris\Modules\Apps\Secondary\Services\Contracts\PeExemptions;
use Scholaris\Modules\Apps\Secondary\Services\Infrastructure\HealthCipher;
use Scholaris\Modules\Apps\Secondary\Setup\Application\ModuleSwitch;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Primary\RecordingInvoices;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * T972, T973 : cantine et transport (offres, arrêts, capacité, un abonnement en cours par service, facture libre du socle), internat
 * gardé par son module, infirmerie réservée à l'infirmier (403 pour tout autre rôle, chef compris), chaque consultation journalisée,
 * données de santé chiffrées au repos, dispenses d'EPS lisibles sans motif. Données fictives.
 */
final class ServicesTest extends SecondaryTestCase
{
    private const MANAGER = 'resp-services-a';
    private const NURSE = 'infirmier-a';
    private const HEAD = 'chef-a';
    private const TEACHER = 'prof-a';

    private InMemoryEnrollments $enrollments;
    private RecordingInvoices $invoices;
    private string $school;

    protected function setUp(): void
    {
        $this->enrollments = new InMemoryEnrollments();
        $this->invoices = new RecordingInvoices();
        $this->bootSecondary([
            EnrollmentDirectory::class => $this->enrollments,
            InvoiceIssuer::class => $this->invoices,
            HealthCipher::class => new HealthCipher('cle-de-test-fictive'),
        ]);
        $this->school = $this->factory->opened(self::TA, 'lycee_prive');
        $switch = $this->service(ModuleSwitch::class);
        $switch->enable(self::TA, $this->school, 'canteen_transport', 'Ouverture de la cantine', 'admin');
        $switch->enable(self::TA, $this->school, 'infirmary', 'Ouverture de l\'infirmerie', 'admin');
        $this->enrollments->add(self::TA, 'ins-1', 'eleve-1', $this->school, 'classe-1');
        $this->enrollments->add(self::TA, 'ins-2', 'eleve-2', $this->school, 'classe-1');
        $this->enrollments->add(self::TA, 'ins-3', 'eleve-3', $this->school, 'classe-1', 'ay1', 'withdrawn');
        $this->grant(self::TA, self::MANAGER, $this->school, ['secondary.services.manage']);
        $this->grant(self::TA, self::NURSE, $this->school, ['secondary.infirmary.manage']);
        $this->grant(self::TA, self::HEAD, $this->school, ['secondary.settings.manage']);
        $this->grant(self::TA, self::TEACHER, $this->school, ['secondary.grades.enter']);
    }

    public function testLigneDeTransportArretsCapaciteEtFacture(): void
    {
        $base = '/secondary/services/canteen-transport';
        [$badStops] = $this->call('POST', $base . '/offers', ['service_kind' => 'canteen', 'label' => 'Demi-pension', 'stops' => ['A'], 'amount_xaf' => 15000], self::MANAGER, self::TA, $this->school);
        self::assertSame(422, $badStops, 'Arrêts réservés au transport.');
        [$boarding] = $this->call('POST', $base . '/offers', ['service_kind' => 'boarding', 'label' => 'Dortoir', 'amount_xaf' => 1], self::MANAGER, self::TA, $this->school);
        self::assertSame(422, $boarding, 'Nature d\'un autre module.');

        $line = $this->ok('POST', $base . '/offers', ['service_kind' => 'transport', 'label' => 'Ligne 1', 'stops' => ['Carrefour fictif', 'Marché fictif'], 'capacity' => 1, 'fee_code' => 'TRANSPORT-T1', 'amount_xaf' => 25000], self::MANAGER, self::TA, $this->school);
        self::assertSame(['Carrefour fictif', 'Marché fictif'], $line['stops']);
        [$unknownStop] = $this->call('POST', $base . '/subscriptions', ['offer_id' => $line['id'], 'person_id' => 'eleve-1', 'starts_on' => '2026-10-26', 'stop_label' => 'Ailleurs'], self::MANAGER, self::TA, $this->school);
        self::assertSame(422, $unknownStop);
        [$withdrawn] = $this->call('POST', $base . '/subscriptions', ['offer_id' => $line['id'], 'person_id' => 'eleve-3', 'starts_on' => '2026-10-26'], self::MANAGER, self::TA, $this->school);
        self::assertSame(422, $withdrawn, 'Inscription close : pas d\'abonnement.');

        $sub = $this->ok('POST', $base . '/subscriptions', ['offer_id' => $line['id'], 'person_id' => 'eleve-1', 'starts_on' => '2026-10-26', 'stop_label' => 'Marché fictif', 'invoice' => true], self::MANAGER, self::TA, $this->school);
        self::assertSame('active', $sub['status']);
        self::assertNotNull($sub['invoice_id']);
        $charge = $this->invoices->charges['sec-svc:' . $sub['id']] ?? null;
        self::assertNotNull($charge, 'Facture libre du socle.');
        self::assertSame(['TRANSPORT-T1', 25000, 'eleve-1', 'classe-1'], [$charge->lines[0]->feeCode, $charge->lines[0]->amountXaf, $charge->subjectId, $charge->structureId]);

        [$full] = $this->call('POST', $base . '/subscriptions', ['offer_id' => $line['id'], 'person_id' => 'eleve-2', 'starts_on' => '2026-10-26'], self::MANAGER, self::TA, $this->school);
        self::assertSame(409, $full, 'Capacité atteinte.');
        $canteen = $this->ok('POST', $base . '/offers', ['service_kind' => 'canteen', 'label' => 'Demi-pension', 'amount_xaf' => 15000], self::HEAD, self::TA, $this->school);
        [$noFee] = $this->call('POST', $base . '/subscriptions', ['offer_id' => $canteen['id'], 'person_id' => 'eleve-1', 'starts_on' => '2026-10-26', 'invoice' => true], self::MANAGER, self::TA, $this->school);
        self::assertSame(422, $noFee, 'Facture sans code de frais.');
        $this->ok('POST', $base . '/subscriptions', ['offer_id' => $canteen['id'], 'person_id' => 'eleve-1', 'starts_on' => '2026-10-26'], self::MANAGER, self::TA, $this->school);
        [$twice] = $this->call('POST', $base . '/subscriptions', ['offer_id' => $canteen['id'], 'person_id' => 'eleve-1', 'starts_on' => '2026-10-27'], self::MANAGER, self::TA, $this->school);
        self::assertSame(409, $twice, 'Un seul abonnement en cours par service.');

        $ended = $this->ok('POST', $base . '/subscriptions/' . $sub['id'] . '/end', ['ends_on' => '2026-12-18', 'reason' => 'Déménagement'], self::MANAGER, self::TA, $this->school);
        self::assertSame(['ended', '2026-12-18'], [$ended['status'], $ended['ends_on']]);
        $this->ok('POST', $base . '/subscriptions', ['offer_id' => $line['id'], 'person_id' => 'eleve-2', 'starts_on' => '2026-12-19'], self::MANAGER, self::TA, $this->school);
        $list = $this->call('GET', $base . '/subscriptions?filter[status]=active&per_page=10', null, self::HEAD, self::TA, $this->school)[1];
        self::assertCount(2, $list['data']);
        self::assertArrayHasKey('next_cursor', $list['meta']);
        [$teacher] = $this->call('GET', $base . '/subscriptions', null, self::TEACHER, self::TA, $this->school);
        self::assertSame(403, $teacher);
        self::assertContains('secondary.service.subscribed', $this->audit->actions());

        [$boardingRoute, $json] = $this->call('GET', '/secondary/services/boarding/offers', null, self::MANAGER, self::TA, $this->school);
        self::assertSame([409, 'module_not_enabled'], [$boardingRoute, $json['code'] ?? null]);
    }

    public function testInfirmerieReserveeALInfirmierJournalEtChiffrement(): void
    {
        $visit = $this->ok('POST', '/secondary/services/infirmary/visits', [
            'person_id' => 'eleve-1', 'reason_code' => 'injury', 'outcome' => 'rest', 'parent_called' => true,
            'notes' => 'Entorse fictive de la cheville', 'pe_exempt_until' => '2026-11-06',
        ], self::NURSE, self::TA, $this->school);
        self::assertSame('Entorse fictive de la cheville', $visit['notes']);
        $raw = (string) $this->pdo()->query("SELECT notes_encrypted FROM se_infirmary_visits WHERE id = '" . $visit['id'] . "'")->fetchColumn();
        self::assertStringNotContainsString('Entorse', $raw, 'Notes chiffrées au repos.');

        $record = $this->ok('PUT', '/secondary/services/infirmary/records/eleve-1', ['allergies' => 'Arachide (fictif)', 'emergency_instructions' => 'Appeler le garant'], self::NURSE, self::TA, $this->school);
        self::assertSame(['Arachide (fictif)', null], [$record['allergies'], $record['conditions']]);
        $rawRecord = (string) $this->pdo()->query("SELECT content_encrypted FROM se_health_records WHERE person_id = 'eleve-1'")->fetchColumn();
        self::assertStringNotContainsString('Arachide', $rawRecord);

        foreach ([self::HEAD, self::MANAGER, self::TEACHER] as $other) {
            foreach (['/secondary/services/infirmary/records/eleve-1', '/secondary/services/infirmary/visits', '/secondary/services/infirmary/visits/' . $visit['id']] as $path) {
                [$status] = $this->call('GET', $path, null, $other, self::TA, $this->school);
                self::assertSame(403, $status, $other . ' ' . $path);
            }
        }
        [$write] = $this->call('PUT', '/secondary/services/infirmary/records/eleve-1', ['allergies' => 'x'], self::HEAD, self::TA, $this->school);
        self::assertSame(403, $write);
        self::assertContains('secondary.health.denied', $this->audit->actions());

        $before = (int) $this->pdo()->query('SELECT COUNT(*) FROM se_health_access_log')->fetchColumn();
        self::assertSame('Arachide (fictif)', $this->ok('GET', '/secondary/services/infirmary/records/eleve-1', null, self::NURSE, self::TA, $this->school)['allergies']);
        $this->ok('GET', '/secondary/services/infirmary/visits/' . $visit['id'], null, self::NURSE, self::TA, $this->school);
        $this->call('GET', '/secondary/services/infirmary/visits?filter[person_id]=eleve-1', null, self::NURSE, self::TA, $this->school);
        self::assertSame($before + 3, (int) $this->pdo()->query('SELECT COUNT(*) FROM se_health_access_log')->fetchColumn(), 'Chaque lecture est journalisée.');

        $log = $this->call('GET', '/secondary/services/infirmary/access-log?filter[person_id]=eleve-1', null, self::HEAD, self::TA, $this->school)[1];
        self::assertContains('read_record', array_column($log['data'], 'access_kind'));
        self::assertStringNotContainsString('Arachide', json_encode($log, JSON_THROW_ON_ERROR), 'Le journal ne contient aucune donnée de santé.');

        $exemptions = $this->ok('GET', '/secondary/services/infirmary/pe-exemptions?date=2026-10-30', null, self::TEACHER, self::TA, $this->school);
        self::assertSame([['person_id' => 'eleve-1', 'class_id' => 'classe-1', 'exempt_until' => '2026-11-06']], $exemptions['exemptions']);
        self::assertStringNotContainsString('injury', json_encode($exemptions, JSON_THROW_ON_ERROR), 'Aucun motif médical.');
        $contract = $this->service(PeExemptions::class);
        self::assertTrue($contract->isExempt(self::TA, $this->school, 'eleve-1', '2026-11-06'));
        self::assertFalse($contract->isExempt(self::TA, $this->school, 'eleve-1', '2026-11-07'));
        self::assertFalse($contract->isExempt(self::TB, $this->school, 'eleve-1', '2026-11-01'), 'Autre client.');

        [$tooLong] = $this->call('POST', '/secondary/services/infirmary/visits', ['person_id' => 'eleve-2', 'reason_code' => 'illness', 'outcome' => 'back_to_class', 'parent_called' => false, 'pe_exempt_until' => '2028-01-01'], self::NURSE, self::TA, $this->school);
        self::assertSame(422, $tooLong);
        [$unknown] = $this->call('GET', '/secondary/services/infirmary/records/inconnu', null, self::NURSE, self::TA, $this->school);
        self::assertSame(404, $unknown);
    }

    public function testUnAutreClientNeVoitRien(): void
    {
        $offer = $this->ok('POST', '/secondary/services/canteen-transport/offers', ['service_kind' => 'canteen', 'label' => 'Demi-pension', 'amount_xaf' => 15000], self::MANAGER, self::TA, $this->school);
        $other = $this->factory->opened(self::TB, 'lycee_prive');
        $this->service(ModuleSwitch::class)->enable(self::TB, $other, 'canteen_transport', 'Ouverture', 'admin-b');
        $this->grant(self::TB, 'resp-b', $other, ['secondary.services.manage']);
        [$status] = $this->call('PATCH', '/secondary/services/canteen-transport/offers/' . $offer['id'], ['label' => 'Pris'], 'resp-b', self::TB, $other);
        self::assertSame(404, $status);
        self::assertSame([], $this->call('GET', '/secondary/services/canteen-transport/offers', null, 'resp-b', self::TB, $other)[1]['data']);

        $sibling = $this->factory->opened(self::TA, 'college_prive');
        $switch = $this->service(ModuleSwitch::class);
        $switch->enable(self::TA, $sibling, 'canteen_transport', 'Ouverture', 'admin');
        $switch->enable(self::TA, $sibling, 'infirmary', 'Ouverture', 'admin');
        $this->grant(self::TA, 'resp-a2', $sibling, ['secondary.services.manage']);
        $this->grant(self::TA, 'infirmier-a2', $sibling, ['secondary.infirmary.manage']);
        [$crossStatus] = $this->call('PATCH', '/secondary/services/canteen-transport/offers/' . $offer['id'], ['label' => 'Pris'], 'resp-a2', self::TA, $sibling);
        self::assertSame(404, $crossStatus, 'Autre établissement du même client.');
        self::assertSame([], $this->call('GET', '/secondary/services/canteen-transport/offers', null, 'resp-a2', self::TA, $sibling)[1]['data']);
        $visit = $this->ok('POST', '/secondary/services/infirmary/visits', ['person_id' => 'eleve-1', 'reason_code' => 'illness', 'outcome' => 'rest', 'parent_called' => false], self::NURSE, self::TA, $this->school);
        self::assertSame(404, $this->call('GET', '/secondary/services/infirmary/visits/' . $visit['id'], null, 'infirmier-a2', self::TA, $sibling)[0], 'Passage d\'un autre établissement.');
    }
}

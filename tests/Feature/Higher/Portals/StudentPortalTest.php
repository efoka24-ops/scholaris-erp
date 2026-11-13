<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Portals;

use Nyholm\Psr7\ServerRequest;
use Scholaris\Modules\Apps\Higher\Portals\Student\DocumentsSection;
use Scholaris\Modules\Apps\Higher\Portals\Student\FinanceSection;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSection;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSectionContext;
use Tests\Support\Higher\Billing\HigherFinanceTestCase;

/**
 * Portail étudiant et garant (T241, T317) : inscription, paiement, reçus, documents ; le garant voit toujours la situation
 * financière, absences et notes seulement avec consentement (RG-INS-7) ; sections ajoutées aux portails du socle (S20).
 */
final class StudentPortalTest extends HigherFinanceTestCase
{
    protected function setUp(): void
    {
        $this->bootFinance();
        $this->seedFeeCatalog();
        $this->installDocuments();
        $this->cdcGrid();
        $this->enrollments->add(self::T, 'en-marie', 'marie', self::E, 'CF-L1', self::YEAR);
    }

    public function testLEtudiantVoitSonInscriptionSesFraisSesRecusEtSesDocuments(): void
    {
        $account = $this->openAccount();
        $this->clock->set('2026-10-10T10:00:00+01:00');
        $this->pay($account['id'], 'comptable', 170000, 'orange_money', 'portal-om-170000', 'OM-PORTAL-1');
        $this->issueDocument('scolarite-ec', 'DOC-06', 'marie', 'EC', [], 'CF');

        [$response, $json] = $this->api('GET', '/higher/portal/student', 'marie', self::T, self::E);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $portal = $json['data'];
        self::assertSame('marie', $portal['person_id']);
        self::assertSame('active', $portal['enrolments'][0]['status']);
        self::assertSame(330000, $portal['finance'][0]['balance_xaf']);
        self::assertTrue($portal['finance'][0]['up_to_date']);
        self::assertCount(1, $portal['receipts']);
        self::assertSame('DOC-06', $portal['documents'][0]['code']);
        self::assertFalse($portal['timetable']['available']);
        self::assertContains('DOC-06', $portal['document_requests']['always_available']);
    }

    public function testPaiementMobileDepuisLePortailEtParLeGarant(): void
    {
        $account = $this->openAccount();
        $other = $this->openAccount('yann');
        [$paid, $json] = $this->portalPay('marie', ['account_id' => $account['id'], 'amount_xaf' => 50000, 'channel' => 'mtn_momo', 'payer_phone' => '+237670000000'], 'portal-key-0001');
        self::assertSame(201, $paid, json_encode($json, JSON_THROW_ON_ERROR));
        self::assertSame('pending', $json['data']['payment']['status'], 'En attente de confirmation de l\'opérateur');

        [$foreign] = $this->portalPay('marie', ['account_id' => $other['id'], 'amount_xaf' => 1000, 'channel' => 'mtn_momo', 'payer_phone' => '+237670000000'], 'portal-key-0002');
        self::assertSame(404, $foreign, 'Compte d\'une autre personne');
        [$cash] = $this->portalPay('marie', ['account_id' => $account['id'], 'amount_xaf' => 1000, 'channel' => 'cash', 'payer_phone' => '+237670000000'], 'portal-key-0003');
        self::assertSame(422, $cash);

        [$guarantor, $g] = $this->portalPay('papa', ['student_id' => 'marie', 'account_id' => $account['id'], 'amount_xaf' => 20000, 'channel' => 'orange_money', 'payer_phone' => '+237690000002'], 'portal-key-0004');
        self::assertSame(201, $guarantor, json_encode($g, JSON_THROW_ON_ERROR));
        [$notChild] = $this->portalPay('papa', ['student_id' => 'yann', 'account_id' => $other['id'], 'amount_xaf' => 20000, 'channel' => 'orange_money', 'payer_phone' => '+237690000002'], 'portal-key-0005');
        self::assertSame(404, $notChild);
    }

    public function testLeGarantVoitToujoursLesFraisEtLeResteAvecConsentement(): void
    {
        $this->openAccount();
        [$response, $json] = $this->api('GET', '/higher/portal/guarantor', 'papa', self::T, self::E);
        self::assertSame(200, $response->getStatusCode());
        $student = $json['data']['students'][0];
        self::assertSame('marie', $student['person_id']);
        self::assertSame(500000, $student['finance'][0]['balance_xaf']);
        self::assertFalse($student['consent']);
        self::assertSame('consent_required', $student['grades']['reason']);
        self::assertSame('consent_required', $student['attendance']['reason']);

        $this->consents->give(self::T, 'marie', ConsentChecker::PARENT_ACCESS);
        [, $json] = $this->api('GET', '/higher/portal/guarantor', 'papa', self::T, self::E);
        self::assertTrue($json['data']['students'][0]['consent']);
        self::assertNotSame('consent_required', $json['data']['students'][0]['grades']['reason']);
    }

    public function testSectionsAjouteesAuxPortailsDuSocle(): void
    {
        $this->openAccount();
        $this->issueDocument('scolarite-ec', 'DOC-06', 'marie', 'EC', [], 'CF');
        $finance = $this->service(FinanceSection::class);
        $documents = $this->service(DocumentsSection::class);
        self::assertSame([PortalSection::LEARNER, PortalSection::FAMILY], $finance->portals());
        $family = $finance->build(new PortalSectionContext(self::T, PortalSection::FAMILY, 'papa', 'marie', false));
        self::assertNotNull($family, 'Situation financière visible sans consentement');
        self::assertSame(500000, $family['balance_xaf']);
        self::assertNull($finance->build(new PortalSectionContext(self::T, PortalSection::LEARNER, 'yann', 'yann', true)));
        $docs = $documents->build(new PortalSectionContext(self::T, PortalSection::LEARNER, 'marie', 'marie', true));
        self::assertSame('DOC-06', $docs['documents'][0]['code'] ?? null);
        self::assertContains('portals.sections.higher', $this->kernel->container()->getKnownEntryNames());
    }

    public function testSansPermissionNiCompteRattacheLePortailEstRefuse(): void
    {
        [$noLink] = $this->api('GET', '/higher/portal/student', 'comptable', self::T, self::E);
        self::assertSame(403, $noLink->getStatusCode());
        $this->links->link(self::T, 'yann', 'yann');
        [$noRight] = $this->api('GET', '/higher/portal/student', 'yann', self::T, self::E);
        self::assertSame(403, $noRight->getStatusCode(), 'self.read absent');
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function portalPay(string $user, array $body, string $key): array
    {
        $response = $this->kernel->handle(new ServerRequest('POST', '/api/v1/higher/portal/student/payments', [
            'Authorization' => 'Bearer ' . $user . ':' . self::T, 'X-Establishment-Id' => self::E, 'Content-Type' => 'application/json', 'Idempotency-Key' => $key,
        ], json_encode($body, JSON_THROW_ON_ERROR)));
        $decoded = json_decode((string) $response->getBody(), true);

        return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
    }
}

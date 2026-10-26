<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Apee;

use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Tests\Support\PublicSchools\PublicSchoolsTestCase;

/**
 * T650, T651, T655 : APEE (cotisation volontaire, comptes séparés), reçus DOC-24 et NOT-10, état financier DOC-25 (TST-09, exemple
 * chiffré du CDC), RG-APE-1 à 4, exonérations, paquet minimum DOC-27. Toutes les valeurs sont fictives.
 */
final class ApeeTest extends PublicSchoolsTestCase
{
    protected function setUp(): void
    {
        $this->bootPublic();
        $this->actor('treasurer', 'p-treasurer', [
            'public_schools.school.read', 'public_schools.apee.read', 'public_schools.apee.write', 'public_schools.apee.read_receipts', 'public_schools.apee.non_payers.read', 'documents.read',
        ], new Scope(Scope::ESTABLISHMENT, 'e1', 'pub_apee_board'));
        $this->people->add('t1', 'p-treasurer', 'Thérèse', 'Trésor');
        $this->permissions->grant('director', 'public_schools.apee.read', 'public_schools.school.read', 'public_schools.school.write');
        $this->permissions->grant('inspector', 'public_schools.apee.read');
    }

    /** @return string identifiant de l'année APEE */
    private function vote(int $amount = 5000, int $expected = 420): string
    {
        [$response, $json] = $this->api('POST', '/public/apee/years', 'treasurer', ['year_label' => '2026-2027', 'amount_per_pupil' => $amount, 'voted_on' => '2026-09-26', 'minutes_ref' => 'SYN-AG-2026-01', 'expected_pupils' => $expected]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (string) $json['data']['id'];
    }

    public function testUneContributionDonneUnReçuNumeroteEtUnSmsAuPayeur(): void
    {
        $year = $this->vote();
        [$response, $json] = $this->api('POST', '/public/apee/entries', 'treasurer', ['apee_year_id' => $year, 'direction' => 'income', 'category' => 'contribution', 'amount' => 5000, 'method' => 'mobile_money', 'operator_ref' => 'SYN-OM-0001', 'family_ref' => 's1']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertNotNull($json['data']['receipt_number']);
        self::assertSame('recorded', $json['data']['state']);
        self::assertCount(1, $this->messenger->of('pub.not10.payer'));
        $document = $this->rows("SELECT subject_person_id, type_code FROM documents WHERE type_code = 'pub_doc24_pta_receipt'");
        self::assertSame('p-parent1', $document[0]['subject_person_id'], 'Le reçu est au nom du parent payeur, jamais un document de l\'élève (RG-DOC-3).');

        [$mobileNoRef, $mobileJson] = $this->api('POST', '/public/apee/entries', 'treasurer', ['apee_year_id' => $year, 'direction' => 'income', 'category' => 'contribution', 'amount' => 5000, 'method' => 'mobile_money', 'family_ref' => 's2']);
        self::assertSame(422, $mobileNoRef->getStatusCode());
        self::assertArrayHasKey('operator_ref', $mobileJson['errors']);
    }

    public function testEtatFinancierDeLExempleDuCdc(): void
    {
        for ($i = 1; $i <= 344; ++$i) {
            $id = sprintf('syn-pupil-%03d', $i);
            $this->people->add('t1', $id, 'Élève' . $i, 'SYN');
            $this->enrollments->add('t1', 'en-' . $id, $id, 'e1', 'c1');
        }
        $year = $this->vote();
        for ($i = 1; $i <= 344; ++$i) {
            [$r] = $this->api('POST', '/public/apee/entries', 'treasurer', ['apee_year_id' => $year, 'direction' => 'income', 'category' => 'contribution', 'amount' => 5000, 'family_ref' => sprintf('syn-pupil-%03d', $i)]);
            self::assertSame(201, $r->getStatusCode(), (string) $r->getBody());
        }
        for ($i = 0; $i < 18; ++$i) {
            $this->api('POST', '/public/apee/entries', 'treasurer', ['apee_year_id' => $year, 'direction' => 'expense', 'category' => 'parents_teacher', 'amount' => 25000, 'proof_ref' => 'SYN-PJ-' . $i]);
        }
        $this->api('POST', '/public/apee/entries', 'treasurer', ['apee_year_id' => $year, 'direction' => 'expense', 'category' => 'supplies', 'amount' => 300000, 'proof_ref' => 'SYN-PJ-CRAIE']);
        [, $maintenance] = $this->api('POST', '/public/apee/entries', 'treasurer', ['apee_year_id' => $year, 'direction' => 'expense', 'category' => 'maintenance', 'amount' => 250000]);

        [, $before] = $this->api('GET', '/public/apee/years/' . $year . '/statement', 'director');
        self::assertSame('pending', $maintenance['data']['state'], 'RG-APE-3 : dépense sans pièce = en attente.');
        self::assertSame(250000, $before['data']['pending_total']);
        self::assertSame(750000, $before['data']['expenses_total']);

        $this->api('POST', '/public/apee/entries/' . $maintenance['data']['id'] . '/proof', 'treasurer', ['proof_ref' => 'SYN-FACTURE-12']);
        [$response, $json] = $this->api('GET', '/public/apee/years/' . $year . '/statement', 'director');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2100000, $json['data']['expected']);
        self::assertSame(1720000, $json['data']['collected']);
        self::assertSame(81.9, $json['data']['collection_rate']);
        self::assertEquals(['parents_teacher' => 450000, 'supplies' => 300000, 'maintenance' => 250000], array_intersect_key($json['data']['expenses'], ['parents_teacher' => 1, 'supplies' => 1, 'maintenance' => 1]));
        self::assertSame(1000000, $json['data']['expenses_total']);
        self::assertSame(720000, $json['data']['balance']);
        self::assertSame(344, $json['data']['contributing_families']);
        self::assertSame(76, $json['data']['non_contributing_families']);

        [$doc, $docJson] = $this->api('POST', '/public/apee/years/' . $year . '/statement/document', 'treasurer', []);
        self::assertSame(201, $doc->getStatusCode(), (string) $doc->getBody());
        self::assertNotEmpty($docJson['data']['document']['number']);
    }

    public function testLaListeDesNonContributeursEstReserveeAuBureau(): void
    {
        $year = $this->vote(5000, 6);
        $this->api('POST', '/public/apee/entries', 'treasurer', ['apee_year_id' => $year, 'direction' => 'income', 'category' => 'contribution', 'amount' => 5000, 'family_ref' => 's1']);
        $this->api('POST', '/public/apee/years/' . $year . '/exemptions', 'treasurer', ['family_ref' => 's2', 'reason_code' => 'hardship']);

        [$board, $boardJson] = $this->api('GET', '/public/apee/years/' . $year . '/non-contributors', 'treasurer');
        [$director] = $this->api('GET', '/public/apee/years/' . $year . '/non-contributors', 'director');
        [$teacher] = $this->api('GET', '/public/apee/years/' . $year . '/non-contributors', 'teacher');
        [$exemptions] = $this->api('GET', '/public/apee/years/' . $year . '/exemptions', 'director');
        [, $entries] = $this->api('GET', '/public/apee/years/' . $year . '/entries', 'director');

        self::assertSame(200, $board->getStatusCode(), (string) $board->getBody());
        $ids = array_column($boardJson['data'], 'person_id');
        sort($ids);
        self::assertSame(['s3', 's4', 's5'], $ids);
        self::assertSame(403, $director->getStatusCode(), 'RG-APE-2');
        self::assertContains($teacher->getStatusCode(), [403, 404]);
        self::assertSame(403, $exemptions->getStatusCode(), 'Exonérations sans publicité nominative (EX-APE-06).');
        self::assertNull($entries['data'][0]['family_ref'], 'Le directeur voit les comptes, pas qui a payé.');
    }

    public function testLeDirecteurConsulteSansModifierEtLaContributionNAAucunEffetScolaire(): void
    {
        $year = $this->vote();
        [$director] = $this->api('POST', '/public/apee/entries', 'director', ['apee_year_id' => $year, 'direction' => 'expense', 'category' => 'supplies', 'amount' => 1000]);
        [$directorVote] = $this->api('POST', '/public/apee/years', 'director', ['year_label' => '2027-2028', 'amount_per_pupil' => 1, 'voted_on' => '2027-09-20']);
        [$teacher] = $this->api('GET', '/public/apee/years', 'teacher');
        [$read] = $this->api('GET', '/public/apee/years', 'director');
        [$inspector] = $this->api('GET', '/public/apee/years/' . $year . '/statement?purpose=control', 'inspector');
        [$otherSchool] = $this->api('GET', '/public/apee/years/' . $year . '/statement', 'treasurer', null, 't1', 'e2');

        self::assertSame(403, $director->getStatusCode(), 'RG-APE-4');
        self::assertSame(403, $directorVote->getStatusCode());
        self::assertSame(404, $teacher->getStatusCode());
        self::assertSame(200, $read->getStatusCode());
        self::assertSame(200, $inspector->getStatusCode());
        self::assertSame(404, $otherSchool->getStatusCode());
        // RG-APE-1 : aucune table d'élève ne lit l'APEE (test d'architecture) ; un élève non contributeur reste inscrit.
        self::assertSame([], $this->rows("SELECT id FROM pub_apee_entries WHERE family_ref = 's3'"));
    }

    public function testUnRejeuDeLaMemeOperationNEstEnregistreQuUneFois(): void
    {
        $year = $this->vote();
        $body = ['apee_year_id' => $year, 'direction' => 'income', 'category' => 'donation', 'amount' => 20000, 'label' => 'Don SYN', 'client_uuid' => 'c0ffee00-0000-4000-8000-000000000001'];
        [$first] = $this->api('POST', '/public/apee/entries', 'treasurer', $body);
        [$again, $json] = $this->api('POST', '/public/apee/entries', 'treasurer', $body);

        self::assertSame(201, $first->getStatusCode());
        self::assertSame(200, $again->getStatusCode());
        self::assertTrue($json['data']['replayed']);
        self::assertCount(1, $this->rows('SELECT id FROM pub_apee_entries'));
    }

    public function testPaquetMinimumEtBordereau(): void
    {
        [$response, $json] = $this->api('POST', '/public/supplies', 'director', ['source' => 'minimum_package', 'slip_ref' => 'SYN-BL-77', 'received_on' => '2026-10-01', 'deliverer_name' => 'Livreur SYN', 'lines' => [['item_label' => 'Boîtes de craie', 'quantity' => 40, 'structure_id' => 'c1'], ['item_label' => 'Registres', 'quantity' => 4]]]);
        [$treasurer] = $this->api('POST', '/public/supplies', 'treasurer', ['source' => 'donation', 'received_on' => '2026-10-01', 'lines' => [['item_label' => 'Cahiers', 'quantity' => 100]]]);
        [$slip, $slipJson] = $this->api('POST', '/public/supplies/' . $json['data']['id'] . '/slip', 'director', []);
        [$bad] = $this->api('POST', '/public/supplies', 'director', ['source' => 'gift', 'received_on' => '2099-01-01', 'lines' => []]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(2, $json['data']['lines']);
        self::assertSame(403, $treasurer->getStatusCode());
        self::assertSame(201, $slip->getStatusCode(), (string) $slip->getBody());
        self::assertNotEmpty($slipJson['data']['document']['number']);
        self::assertSame(422, $bad->getStatusCode());
    }
}

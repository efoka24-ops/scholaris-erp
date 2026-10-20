<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\ConsentRecorder;
use Tests\Support\People\PeopleTestCase;

/** Consentements horodatés, révocables, jamais supprimés ; le majeur contrôle l'accès de ses parents. */
final class ConsentTest extends PeopleTestCase
{
    protected function setUp(): void
    {
        $this->bootPeople();
    }

    private function checker(): ConsentChecker
    {
        return $this->kernel->container()->get(ConsentChecker::class);
    }

    private function recorder(): ConsentRecorder
    {
        return $this->kernel->container()->get(ConsentRecorder::class);
    }

    public function testDonnerPuisRevoquerConserveLHistorique(): void
    {
        $person = $this->person('Yann', 'Foka');
        [$response, $granted] = $this->api('POST', '/consents', body: ['person_id' => $person['id'], 'purpose' => 'communication']);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('2026-10-08T09:00:00+01:00', $granted['data']['granted_at']);
        self::assertTrue($this->checker()->hasConsent('t1', (string) $person['id'], ConsentChecker::COMMUNICATION));
        self::assertFalse($this->checker()->hasConsent('t1', (string) $person['id'], ConsentChecker::PHOTO));
        self::assertFalse($this->checker()->hasConsent('t2', (string) $person['id'], ConsentChecker::COMMUNICATION), 'Autre client.');
        self::assertSame(409, $this->code('POST', '/consents', body: ['person_id' => $person['id'], 'purpose' => 'communication']));

        $this->clock->advance(3600);
        [$revoked, $body] = $this->api('POST', '/consents/' . $granted['data']['id'] . '/revoke');
        self::assertSame(200, $revoked->getStatusCode());
        self::assertSame('2026-10-08T10:00:00+01:00', $body['data']['revoked_at']);
        self::assertFalse($this->checker()->hasConsent('t1', (string) $person['id'], ConsentChecker::COMMUNICATION));
        self::assertSame(409, $this->code('POST', '/consents/' . $granted['data']['id'] . '/revoke'));

        $this->clock->advance(60);
        self::assertSame(201, $this->code('POST', '/consents', body: ['person_id' => $person['id'], 'purpose' => 'communication']));
        self::assertTrue($this->checker()->hasConsent('t1', (string) $person['id'], ConsentChecker::COMMUNICATION));

        $rows = $this->api('GET', '/consents?filter[person_id]=' . $person['id'])[1]['data'];
        self::assertCount(2, $rows, 'Aucun consentement n\'est supprimé.');
        self::assertSame(404, $this->code('DELETE', '/consents/' . $granted['data']['id']), 'Aucune route de suppression.');
    }

    public function testFinalitesInconnuesEtPersonneInconnue(): void
    {
        $person = $this->person('Yann', 'Foka');

        self::assertSame(422, $this->code('POST', '/consents', body: ['person_id' => $person['id'], 'purpose' => 'publicite']));
        self::assertSame(404, $this->code('POST', '/consents', body: ['person_id' => 'inconnue', 'purpose' => 'photo']));
        self::assertSame(422, $this->code('GET', '/consents'));
    }

    public function testLesParentsNAccedentPasAuxDonneesDUnMajeurSansConsentement(): void
    {
        $student = $this->person('Aïcha', 'Mbarga', ['birth_date' => '2005-03-02']);
        $parent = $this->person('Marie', 'Mbarga');
        $minor = $this->person('Léo', 'Mbarga', ['birth_date' => '2014-03-02']);
        $this->api('POST', '/people/' . $student['id'] . '/relations', body: ['related_person_id' => $parent['id'], 'kind' => 'parent']);
        $this->api('POST', '/people/' . $minor['id'] . '/relations', body: ['related_person_id' => $parent['id'], 'kind' => 'parent']);
        $this->account('parent-user', (string) $parent['id']);
        $this->account('student-user', (string) $student['id']);

        self::assertSame(200, $this->code('GET', '/people/' . $minor['id'], 'parent-user'), 'Mineur : accès des parents par défaut.');
        self::assertSame(404, $this->code('GET', '/people/' . $student['id'], 'parent-user'), 'Majeur sans consentement.');
        self::assertSame(200, $this->code('GET', '/people/' . $student['id'], 'student-user'), 'La personne voit sa propre fiche.');

        [$given] = $this->api('POST', '/consents', 'student-user', body: ['person_id' => $student['id'], 'purpose' => 'parent_access']);
        self::assertSame(201, $given->getStatusCode(), 'Le majeur donne lui-même son consentement.');
        self::assertSame(200, $this->code('GET', '/people/' . $student['id'], 'parent-user'));

        $consentId = $this->api('GET', '/consents?filter[person_id]=' . $student['id'] . '&filter[purpose]=parent_access')[1]['data'][0]['id'];
        self::assertSame(200, $this->code('POST', '/consents/' . $consentId . '/revoke', 'student-user'));
        self::assertSame(404, $this->code('GET', '/people/' . $student['id'], 'parent-user'), 'Révocation : le parent perd l\'accès.');
    }

    public function testUnParentNeDonneJamaisLeConsentementDUnMajeur(): void
    {
        $student = $this->person('Aïcha', 'Mbarga', ['birth_date' => '2005-03-02']);
        $parent = $this->person('Marie', 'Mbarga');
        $this->api('POST', '/people/' . $student['id'] . '/relations', body: ['related_person_id' => $parent['id'], 'kind' => 'parent']);
        $this->account('parent-user', (string) $parent['id']);

        self::assertSame(404, $this->code('POST', '/consents', 'parent-user', body: ['person_id' => $student['id'], 'purpose' => 'parent_access']));
    }

    public function testLeConsentementEcritExigeUnePreuveEtLaRevoquationANnule(): void
    {
        $person = $this->person('Mina', 'Tchameni');

        self::assertFalse($this->recorder()->hasWrittenConsent('t1', (string) $person['id'], ConsentChecker::PHOTO));

        $plain = $this->recorder()->grant('t1', (string) $person['id'], ConsentChecker::PHOTO, 'admin');
        self::assertNull($plain['evidence_ref']);
        self::assertTrue($this->checker()->hasConsent('t1', (string) $person['id'], ConsentChecker::PHOTO));
        self::assertFalse($this->recorder()->hasWrittenConsent('t1', (string) $person['id'], ConsentChecker::PHOTO));

        $this->recorder()->revoke('t1', (string) $plain['id'], 'admin');
        self::assertFalse($this->recorder()->hasWrittenConsent('t1', (string) $person['id'], ConsentChecker::PHOTO));

        $written = $this->recorder()->grant('t1', (string) $person['id'], ConsentChecker::PHOTO, 'admin', 'scan-2026-10-16');
        self::assertSame('scan-2026-10-16', $written['evidence_ref']);
        self::assertTrue($this->recorder()->hasWrittenConsent('t1', (string) $person['id'], ConsentChecker::PHOTO));

        $this->recorder()->revoke('t1', (string) $written['id'], 'admin');
        self::assertFalse($this->recorder()->hasWrittenConsent('t1', (string) $person['id'], ConsentChecker::PHOTO));
    }
}

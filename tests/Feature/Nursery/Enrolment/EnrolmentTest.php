<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Enrolment;

use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Tests\Support\Nursery\NurseryTestCase;

/** EX-MAT-05, 06, 20 : dossier de l'enfant, inscription en section, avertissements (âge, effectif), allergies, accord photo. */
final class EnrolmentTest extends NurseryTestCase
{
    private string $section;

    protected function setUp(): void
    {
        $this->bootNursery();
        $this->section = (string) $this->ok('POST', '/nursery/sections', ['label' => 'Petite section', 'capacity' => 1])['id'];
        $this->grant(self::TA, 'secretaire-a', self::EA, 'nursery.enrol');
        $this->grant(self::TA, 'secretaire-a', self::EA, 'nursery.child.read');
    }

    /** @return array<string, mixed> */
    private function body(string $first = 'Lina'): array
    {
        return [
            'first_name' => $first, 'last_name' => 'Fictive', 'birth_date' => '2023-02-01', 'gender' => 'female', 'section_id' => $this->section,
            'guardians' => [['kind' => 'financial_guarantor', 'identity' => ['first_name' => 'Marie', 'last_name' => 'Fictive', 'phone' => '+237600000001']]],
            'allergies' => 'arachides', 'photo_consent' => true,
        ];
    }

    public function testLaSecretaireInscritUnEnfantQuiApparaitDansSaSection(): void
    {
        $result = $this->ok('POST', '/nursery/enrolments', $this->body(), 'secretaire-a');
        self::assertSame($this->section, $result['section_id']);
        self::assertSame('AY-2026', $result['academic_year_id']);
        self::assertCount(1, $result['guardian_ids']);
        self::assertSame([], $result['warnings']);

        $child = $this->ok('GET', '/nursery/children/' . $result['child_id']);
        self::assertSame('Lina', $child['first_name']);
        self::assertSame($this->section, $child['section_id']);
        self::assertSame('Marie Fictive', $child['guardians'][0]['full_name']);
        self::assertTrue($this->world->people->hasConsent(self::TA, $result['child_id'], ConsentChecker::PHOTO));
        self::assertContains('nursery.child.enrolled', $this->audit->actions());
    }

    public function testAgeMinimalEtEffectifProduisentDesAvertissementsSansBloquer(): void
    {
        $this->setSetting(self::TA, self::EA, 'nursery.enrol.min_age_months', 60);
        $this->ok('POST', '/nursery/enrolments', $this->body('Un'), 'secretaire-a');
        $second = $this->ok('POST', '/nursery/enrolments', $this->body('Deux'), 'secretaire-a');
        $codes = array_column($second['warnings'], 'code');
        self::assertContains('below_minimum_age', $codes);
        self::assertContains('over_capacity', $codes);
    }

    public function testSansAgeMinimalParametreAucuneAlerteDAge(): void
    {
        $first = $this->ok('POST', '/nursery/enrolments', $this->body('Un'), 'secretaire-a');
        self::assertNotContains('below_minimum_age', array_column($first['warnings'], 'code'));
    }

    public function testSansAnneeEnCoursLInscriptionEstRefusee(): void
    {
        $this->periods->open = false;
        [$status, $json] = $this->call('POST', '/nursery/enrolments', $this->body(), 'secretaire-a');
        self::assertSame(422, $status);
        self::assertArrayHasKey('academic_year', $json['errors']);
    }

    public function testCorpsInvalideEtSectionInconnue(): void
    {
        self::assertSame(422, $this->code('POST', '/nursery/enrolments', [], 'secretaire-a'));
        self::assertSame(422, $this->code('POST', '/nursery/enrolments', ['section_id' => 'inconnue'] + $this->body(), 'secretaire-a'));
        self::assertSame(422, $this->code('POST', '/nursery/enrolments', ['guardians' => []] + $this->body(), 'secretaire-a'));
        self::assertSame(422, $this->code('POST', '/nursery/enrolments', $this->body() + ['inconnu' => 'x'], 'secretaire-a'));
    }

    public function testUneEducatriceNInscritPas(): void
    {
        $this->grant(self::TA, 'educatrice-a', self::EA, 'nursery.child.read', $this->section);
        self::assertSame(403, $this->code('POST', '/nursery/enrolments', $this->body(), 'educatrice-a'));
    }

    public function testLesAllergiesNeSontVuesQueDeCeuxQuiPeuventLesLireEtLaConsultationEstJournalisee(): void
    {
        $child = (string) $this->ok('POST', '/nursery/enrolments', $this->body(), 'secretaire-a')['child_id'];
        $this->grant(self::TA, 'educatrice-a', self::EA, 'nursery.child.read', $this->section);
        self::assertArrayNotHasKey('allergies', $this->ok('GET', '/nursery/children/' . $child, null, 'educatrice-a'));

        $this->grant(self::TA, 'educatrice-a', self::EA, 'nursery.allergy.read', $this->section);
        self::assertSame('arachides', $this->ok('GET', '/nursery/children/' . $child, null, 'educatrice-a')['allergies']);
        self::assertContains('nursery.allergy.viewed', $this->audit->actions());
    }

    public function testUnEnfantEstIntrouvablePourUneAutreSectionEtUnAutreClient(): void
    {
        $child = (string) $this->ok('POST', '/nursery/enrolments', $this->body(), 'secretaire-a')['child_id'];
        $other = (string) $this->ok('POST', '/nursery/sections', ['label' => 'Autre'])['id'];
        $this->grant(self::TA, 'educatrice-b', self::EA, 'nursery.child.read', $other);
        self::assertSame(404, $this->code('GET', '/nursery/children/' . $child, null, 'educatrice-b'));
        self::assertSame([], $this->ok('GET', '/nursery/children', null, 'educatrice-b'));

        $this->grantAll(self::TB, 'directrice-b', self::EB);
        self::assertSame(404, $this->code('GET', '/nursery/children/' . $child, null, 'directrice-b', self::TB, self::EB));
        self::assertSame(404, $this->code('GET', '/nursery/children?section_id=' . $this->section, null, 'directrice-b', self::TB, self::EB));
    }

    public function testLaListeDesEnfantsEstPagineeEtFiltreeParSection(): void
    {
        $this->ok('POST', '/nursery/enrolments', $this->body('Aaa'), 'secretaire-a');
        $this->ok('POST', '/nursery/enrolments', $this->body('Bbb'), 'secretaire-a');
        [, $page] = $this->call('GET', '/nursery/children?per_page=1&section_id=' . $this->section);
        self::assertCount(1, $page['data']);
        self::assertIsString($page['meta']['next_cursor']);
        self::assertSame(422, $this->code('GET', '/nursery/children?per_page=500'));
    }
}

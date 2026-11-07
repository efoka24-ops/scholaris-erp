<?php

declare(strict_types=1);

namespace Tests\Feature\PublicPrimary\Promotion;

use Tests\Support\PublicSchools\PublicPrimaryTestCase;

/**
 * T665 : politique nationale (RG-PAS-1), propositions (NA en une discipline clé = passe avec remédiation ; NA en deux = examen par
 * le conseil), conseil des maîtres (API-08), redoublement motivé et avis de l'inspecteur au deuxième redoublement (RG-PAS-2),
 * recours dans le délai paramétré, PV DOC-12, statistiques et section de fin d'année du questionnaire (T653).
 */
final class PromotionTest extends PublicPrimaryTestCase
{
    protected function setUp(): void
    {
        $this->bootPrimary();
        $this->seedReferential();
        $checks = array_merge(
            $this->paulChecks(),
            $this->cellsFor('s4', 'example_francais', ['C1' => [0, 0, 1], 'C2' => [0, 0, 0], 'C3' => [1, 0, 0]]),
            $this->cellsFor('s4', 'example_mathematiques', ['C1' => [1, 1, 1], 'C2' => [1, 1, 1], 'C3' => [1, 1, 1]]),
            $this->cellsFor('s4', 'example_english', ['C1' => [0, 0, 0], 'C2' => [0, 0, 0], 'C3' => [0, 0, 0]]),
        );
        [$saved] = $this->api('PUT', '/public-primary/units/' . $this->unit(8) . '/criterion-checks', 'teacher2', ['structure_id' => 'c2', 'checks' => $checks]);
        self::assertSame(201, $saved->getStatusCode(), (string) $saved->getBody());
    }

    /** @return array<string, array<string, mixed>> */
    private function proposals(): array
    {
        [$response, $json] = $this->api('POST', '/public-primary/promotion/' . self::YEAR . '/proposals', 'teacher2', ['structure_id' => 'c2']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return array_column($json['data'], null, 'person_id');
    }

    public function testLesPropositionsSuiventLaPolitiqueNationale(): void
    {
        $rows = $this->proposals();

        self::assertSame('promote_with_remediation', $rows['paul']['proposal'], 'NA en English seulement.');
        self::assertSame('council_review', $rows['s4']['proposal'], 'NA en Français et en English.');
        self::assertSame('example-cdc-2026-10', $rows['paul']['policy_version']);
    }

    public function testLaPolitiqueNeSeModifiePasALEcole(): void
    {
        [$director] = $this->api('PUT', '/public-primary/promotion/policy', 'director', ['version' => 'ecole', 'na_for_remediation' => 2, 'na_for_council' => 3]);
        [$admin] = $this->api('PUT', '/public-primary/promotion/policy', 'admin', ['version' => 'national-2027', 'na_for_remediation' => 1, 'na_for_council' => 3]);
        [, $policy] = $this->api('GET', '/public-primary/promotion/policy', 'director');
        $rows = $this->proposals();

        self::assertSame(403, $director->getStatusCode(), 'RG-PAS-1');
        self::assertSame(200, $admin->getStatusCode(), (string) $admin->getBody());
        self::assertSame('national-2027', $policy['data']['version']);
        self::assertSame('promote_with_remediation', $rows['s4']['proposal'], 'Avec un seuil de 3, deux NA donnent un passage avec remédiation.');
    }

    public function testConseilRedoublementMotiveEtAvisDeLInspecteurAuDeuxiemeRedoublement(): void
    {
        $this->proposals();
        $this->connections()->pdo()->prepare("INSERT INTO pp_promotion_decisions (id, tenant_id, establishment_id, year_label, structure_id, person_id, level_code, proposal, key_na_count, decision, decision_state, reason, policy_version, created_at, updated_at) VALUES ('01PREVREPEAT00000000000001', 't1', 'e1', '2025-2026', 'c2', 's4', 'CE1', 'council_review', 2, 'repeat', 'final', 'Redoublement SYN', 'example', '2026-06-20T10:00:00+01:00', '2026-06-20T10:00:00+01:00')")->execute();
        $path = '/public-primary/promotion/' . self::YEAR . '/council';
        $base = ['structure_id' => 'c2', 'held_on' => '2027-06-18', 'attendees' => ['Rose Ngono', 'Paul Essomba']];

        [$missing] = $this->api('POST', $path, 'director', $base + ['decisions' => []]);
        [$noReason, $noReasonJson] = $this->api('POST', $path, 'director', $base + ['decisions' => [['person_id' => 's4', 'decision' => 'repeat']]]);
        [$teacher] = $this->api('POST', $path, 'teacher2', $base + ['decisions' => [['person_id' => 's4', 'decision' => 'repeat', 'reason' => 'x']]]);
        [$response, $json] = $this->api('POST', $path, 'director', $base + ['decisions' => [['person_id' => 's4', 'decision' => 'repeat', 'reason' => 'Lecture non acquise malgré la remédiation (SYN).']]]);

        self::assertSame(422, $missing->getStatusCode(), 'Une décision est attendue pour l\'élève examiné par le conseil.');
        self::assertSame(422, $noReason->getStatusCode());
        self::assertArrayHasKey('decisions.0.reason', $noReasonJson['errors']);
        self::assertSame(403, $teacher->getStatusCode());
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $rows = array_column($json['data']['decisions'], null, 'person_id');
        self::assertSame('pending_opinion', $rows['s4']['state'], 'RG-PAS-2 : deuxième redoublement en CE1.');
        self::assertSame('final', $rows['paul']['state']);
        self::assertSame('promote_with_remediation', $rows['paul']['decision']);
        self::assertCount(1, $this->messenger->of('pub.pas.parent'), 'Famille de Paul prévenue (s5 n\'a pas de garant) ; s4 attend l\'avis.');

        [$otherInspector] = $this->api('POST', '/public-primary/promotion/decisions/' . $rows['s4']['id'] . '/opinion', 'inspector2', ['opinion' => 'favourable', 'comment' => 'x']);
        [$opinion, $opinionJson] = $this->api('POST', '/public-primary/promotion/decisions/' . $rows['s4']['id'] . '/opinion', 'inspector', ['opinion' => 'favourable', 'comment' => 'Redoublement justifié, suivi renforcé.']);
        [$minutes, $minutesJson] = $this->api('POST', '/public-primary/promotion/councils/' . $json['data']['council_id'] . '/minutes', 'director', []);
        [, $stats] = $this->api('GET', '/public-primary/promotion/' . self::YEAR . '/statistics', 'director');

        self::assertSame(404, $otherInspector->getStatusCode());
        self::assertSame(200, $opinion->getStatusCode(), (string) $opinion->getBody());
        self::assertSame('repeat', $opinionJson['data']['decision']);
        self::assertSame('final', $opinionJson['data']['state']);
        self::assertSame(201, $minutes->getStatusCode(), (string) $minutes->getBody());
        self::assertNotEmpty($minutesJson['data']['document']['number']);
        self::assertSame(1, $stats['data']['classes'][0]['decisions']['repeat']);
    }

    public function testRecoursDansLeDelaiParametre(): void
    {
        $this->proposals();
        [, $council] = $this->api('POST', '/public-primary/promotion/' . self::YEAR . '/council', 'director', ['structure_id' => 'c2', 'held_on' => '2026-10-05', 'attendees' => ['Rose Ngono'], 'decisions' => [['person_id' => 's4', 'decision' => 'promote_with_remediation']]]);
        $rows = array_column($council['data']['decisions'], null, 'person_id');
        self::assertSame('2026-10-13', $rows['paul']['appeal_until'], 'Délai de recours de 8 jours (paramètre à confirmer).');

        [$lodged] = $this->api('POST', '/public-primary/promotion/decisions/' . $rows['paul']['id'] . '/appeal', 'director', ['action' => 'lodge', 'reason' => 'La famille conteste (SYN).']);
        [$revised, $revisedJson] = $this->api('POST', '/public-primary/promotion/decisions/' . $rows['paul']['id'] . '/appeal', 'director', ['action' => 'revise', 'decision' => 'promote', 'reason' => 'Résultats de l\'unité 8 revus.']);

        $this->settings->values['public_primary.promotion.appeal_days'] = 0;
        [, $late] = $this->api('POST', '/public-primary/promotion/' . self::YEAR . '/proposals', 'teacher2', ['structure_id' => 'c2']);
        self::assertSame(200, $lodged->getStatusCode(), (string) $lodged->getBody());
        self::assertSame(200, $revised->getStatusCode(), (string) $revised->getBody());
        self::assertSame('promote', $revisedJson['data']['decision']);
        self::assertSame('revised', $revisedJson['data']['appeal_state']);
        self::assertNotEmpty($late['data']);
    }

    public function testSectionDeFinDAnneeDuQuestionnaire(): void
    {
        $this->proposals();
        $this->api('POST', '/public-primary/promotion/' . self::YEAR . '/council', 'director', ['structure_id' => 'c2', 'held_on' => '2027-06-18', 'attendees' => ['Rose Ngono'], 'decisions' => [['person_id' => 's4', 'decision' => 'promote_with_remediation']]]);
        $values = $this->container()->get(\Scholaris\Modules\Apps\PublicPrimary\Promotion\Application\EndOfYearCensusSection::class)->values('t1', 'e1', self::YEAR);

        self::assertSame(2, $values['promote_with_remediation.ce1']);
        self::assertSame(1, $values['promote.ce1']);
    }
}

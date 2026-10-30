<?php

declare(strict_types=1);

namespace Tests\Feature\PublicPrimary\Evaluation;

use Tests\Support\PublicSchools\PublicPrimaryTestCase;

/**
 * T660 à T663 : référentiel d'exemple, unités 2026-2027, saisie des critères (API-06), mentions (TST-03, cas « Paul E. »), remédiation
 * (DOC-11) et message au parent si NA ; RG-EVA-4 (absent partout = non évalué) ; idempotence et périmètre.
 */
final class CriterionChecksTest extends PublicPrimaryTestCase
{
    protected function setUp(): void
    {
        $this->bootPrimary();
        $this->seedReferential();
    }

    public function testLesHuitUnitesSontRepartiesSurLesTroisTrimestresAvecLaSemaineDEvaluation(): void
    {
        self::assertCount(8, $this->units);
        self::assertSame([2, 3, 3], array_values(array_count_values(array_column($this->units, 'period_id'))));
        self::assertSame('2026-09-07', $this->units[0]['starts_on']);
        self::assertSame('2027-06-11', $this->units[7]['ends_on']);
        self::assertTrue($this->units[7]['is_final_integration']);
        self::assertFalse($this->units[0]['is_final_integration']);
        foreach ($this->units as $unit) {
            self::assertGreaterThanOrEqual($unit['starts_on'], $unit['evaluation_week_from']);
            self::assertLessThanOrEqual($unit['ends_on'], $unit['evaluation_week_from']);
        }
    }

    public function testLeNombreDUnitesEstUnParametre(): void
    {
        $this->settings->values['public_primary.evaluation.units_per_year'] = 6;
        [$response, $json] = $this->api('POST', '/public-primary/units/generate', 'admin', [
            'year_label' => '2027-2028', 'level_code' => 'CE1',
            'trimesters' => [['period_id' => 'p1', 'starts_on' => '2027-09-06', 'ends_on' => '2027-12-17'], ['period_id' => 'p2', 'starts_on' => '2028-01-03', 'ends_on' => '2028-06-09']],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(6, $json['data']);
    }

    public function testLeCasPaulDonneEcaANaEtLesListesDeRemediation(): void
    {
        [$response, $json] = $this->api('PUT', '/public-primary/units/' . $this->unit(3) . '/criterion-checks', 'teacher2', ['structure_id' => 'c2', 'checks' => $this->paulChecks()]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $mentions = array_column($json['data']['mentions'], null, 'discipline_code');
        self::assertSame('ECA', $mentions['example_francais']['mention']);
        self::assertSame(['C3'], $mentions['example_francais']['to_remediate']);
        self::assertSame('A', $mentions['example_mathematiques']['mention']);
        self::assertTrue($mentions['example_mathematiques']['perfection_mastered'], 'Mention A : le critère de perfectionnement est examiné et signalé.');
        self::assertSame('NA', $mentions['example_english']['mention']);
        self::assertSame(['C1', 'C2', 'C3'], $mentions['example_english']['to_remediate']);

        [$list, $listJson] = $this->api('GET', '/public-primary/units/' . $this->unit(3) . '/remediation?structure_id=c2', 'teacher2');
        self::assertSame(200, $list->getStatusCode());
        $labels = array_map(static fn (array $g): string => $g['discipline_code'] . ' ' . $g['criterion_code'], $listJson['data']);
        sort($labels);
        self::assertSame(['example_english C1', 'example_english C2', 'example_english C3', 'example_francais C3'], $labels);

        $sms = array_values(array_filter($this->messenger->sent, static fn (array $m): bool => $m['template'] === 'pub.eva_na.parent'));
        self::assertCount(1, $sms, 'Message au parent pour English (NA) seulement, une seule fois.');
    }

    public function testUnElevAbsentAToutesLesOccasionsEstNonEvalueJamaisNa(): void
    {
        $cells = $this->cellsFor('paul', 'example_english', ['C1' => [null, null, null], 'C2' => [null, null, null], 'C3' => [null, null, null]]);
        [$response, $json] = $this->api('PUT', '/public-primary/units/' . $this->unit(3) . '/criterion-checks', 'teacher2', ['structure_id' => 'c2', 'checks' => $cells]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('not_assessed', $json['data']['mentions'][0]['mention']);
        self::assertSame([], $json['data']['mentions'][0]['to_remediate']);
        self::assertSame([], array_filter($this->messenger->sent, static fn (array $m): bool => $m['template'] === 'pub.eva_na.parent'));
    }

    public function testLesReglesDeMentionSontDesParametres(): void
    {
        $this->settings->values['public_primary.evaluation.mastery_min_success'] = 3;
        [, $json] = $this->api('PUT', '/public-primary/units/' . $this->unit(3) . '/criterion-checks', 'teacher2', ['structure_id' => 'c2', 'checks' => $this->paulChecks()]);
        $mentions = array_column($json['data']['mentions'], 'mention', 'discipline_code');

        self::assertSame('NA', $mentions['example_francais'], 'Avec 3 réussites exigées, aucun critère de Français n\'est maîtrisé.');
        self::assertSame('ECA', $mentions['example_mathematiques']);
    }

    public function testUnRejeuIdentiqueNEstAppliqueQuUneFoisEtUnContenuDifferentEstRefuse(): void
    {
        $uuid = '7f9c2b1e-1d2a-4c3b-9e8f-0a1b2c3d4e5f';
        $path = '/public-primary/units/' . $this->unit(3) . '/criterion-checks';
        [$first] = $this->api('PUT', $path, 'teacher2', ['structure_id' => 'c2', 'client_uuid' => $uuid, 'checks' => $this->paulChecks()]);
        [$again, $againJson] = $this->api('PUT', $path, 'teacher2', ['structure_id' => 'c2', 'client_uuid' => $uuid, 'checks' => $this->paulChecks()]);
        $other = $this->cellsFor('paul', 'example_english', ['C1' => [1, 1, 1]]);
        [$conflict] = $this->api('PUT', $path, 'teacher2', ['structure_id' => 'c2', 'client_uuid' => $uuid, 'checks' => $other]);

        self::assertSame(201, $first->getStatusCode());
        self::assertSame(200, $again->getStatusCode());
        self::assertTrue($againJson['data']['replayed']);
        self::assertSame(409, $conflict->getStatusCode());
        self::assertCount(30, $this->rows('SELECT id FROM pp_criterion_checks'));
    }

    public function testPerimetreEtValidation(): void
    {
        $path = '/public-primary/units/' . $this->unit(3) . '/criterion-checks';
        [$otherTeacher] = $this->api('PUT', $path, 'teacher', ['structure_id' => 'c2', 'checks' => $this->paulChecks()]);
        [$inspector] = $this->api('PUT', $path, 'inspector', ['structure_id' => 'c2', 'checks' => $this->paulChecks()]);
        [$outsider] = $this->api('GET', '/public-primary/units/' . $this->unit(3) . '/grid?structure_id=c2', 'outsider');
        [$teacherGrid] = $this->api('GET', '/public-primary/units/' . $this->unit(3) . '/grid?structure_id=c2', 'teacher');
        [$wrongLevel] = $this->api('PUT', $path, 'teacher', ['structure_id' => 'c1', 'checks' => $this->paulChecks()]);
        [$bad, $badJson] = $this->api('PUT', $path, 'teacher2', ['structure_id' => 'c2', 'checks' => [['person_id' => 's1', 'criterion_id' => 'x', 'occasion_no' => 4, 'outcome' => 'maybe']]]);
        [$otherTenant] = $this->api('PUT', $path, 'teacher2', ['structure_id' => 'c2', 'checks' => $this->paulChecks()], 't2', 'x1');

        self::assertSame(403, $otherTeacher->getStatusCode());
        self::assertSame(403, $inspector->getStatusCode());
        self::assertSame(404, $outsider->getStatusCode());
        self::assertSame(404, $teacherGrid->getStatusCode(), 'Un enseignant ne lit pas la grille d\'une autre classe.');
        self::assertSame(422, $wrongLevel->getStatusCode(), 'L\'unité du CE1 ne s\'applique pas à la classe de CP.');
        self::assertSame(422, $bad->getStatusCode());
        self::assertArrayHasKey('checks.0.person_id', $badJson['errors']);
        self::assertArrayHasKey('checks.0.occasion_no', $badJson['errors']);
        self::assertContains($otherTenant->getStatusCode(), [401, 403, 404]);
        self::assertSame([], $this->rows('SELECT id FROM pp_criterion_checks'));
    }

    public function testLaGrilleRendElevesCriteresEtCases(): void
    {
        $this->api('PUT', '/public-primary/units/' . $this->unit(3) . '/criterion-checks', 'teacher2', ['structure_id' => 'c2', 'checks' => $this->paulChecks()]);
        [$response, $json] = $this->api('GET', '/public-primary/units/' . $this->unit(3) . '/grid?structure_id=c2', 'teacher2');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(3, $json['data']['occasions']);
        self::assertCount(3, $json['data']['frames']);
        self::assertSame(['s4', 's5', 'paul'], array_values(array_map(static fn (array $p): string => $p['person_id'], $json['data']['pupils'])));
        self::assertCount(30, $json['data']['cells']);
        self::assertSame(['A', 'ECA', 'NA'], array_column($json['data']['scale'], 'code'));
        self::assertStringNotContainsStringIgnoringCase('rank', (string) $response->getBody());
    }

    public function testLeReferentielSeRegleAuNiveauNational(): void
    {
        [$director] = $this->api('POST', '/public-primary/frames', 'director', ['subsystem' => 'francophone', 'level_code' => 'CE1', 'discipline_code' => 'x', 'label_fr' => 'X', 'label_en' => 'X', 'criteria' => [['code' => 'C1', 'label_fr' => 'a', 'label_en' => 'a']]]);
        [$version, $versionJson] = $this->api('POST', '/public-primary/frames', 'admin', ['subsystem' => 'francophone', 'level_code' => 'CE1', 'discipline_code' => 'example_francais', 'label_fr' => 'Français', 'label_en' => 'French', 'valid_from' => '2027-01-01', 'criteria' => [['code' => 'C1', 'label_fr' => 'a', 'label_en' => 'a']]]);
        [$read] = $this->api('GET', '/public-primary/frames?level_code=CE1', 'teacher2');
        [$settings, $settingsJson] = $this->api('GET', '/public-primary/settings', 'director');

        self::assertSame(403, $director->getStatusCode());
        self::assertSame(201, $version->getStatusCode(), (string) $version->getBody());
        self::assertSame(2, $versionJson['data']['version'], 'Nouvelle version, l\'ancienne est conservée.');
        self::assertSame(200, $read->getStatusCode());
        self::assertSame(200, $settings->getStatusCode());
        $keys = array_column($settingsJson['data'], 'status', 'key');
        self::assertSame('official', $keys['public_primary.remediation.minutes_per_week']);
        self::assertSame('to_confirm', $keys['public_primary.evaluation.scale']);
    }
}

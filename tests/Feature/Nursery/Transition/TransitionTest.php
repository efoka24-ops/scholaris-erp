<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Transition;

use Scholaris\Modules\Apps\Nursery\Transition\Application\ClosureReadiness;
use Scholaris\Modules\Apps\Nursery\Transition\Application\NurseryPromotionPlanner;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Feature\Nursery\Development\DevelopmentTestCase;

/** EX-MAT-21, 22, 37 (MQ-09, MQ-24) : proposition, avis, décision motivée, fiche de liaison signée et émise, clôture d'année. */
final class TransitionTest extends DevelopmentTestCase
{
    protected function setUp(): void
    {
        $this->bootDevelopment();
        $this->grant(self::TA, self::DIRECTOR, self::EA, 'nursery.transition.decide');
        $this->grant(self::TA, 'educatrice-a', self::EA, 'nursery.transition.opinion', $this->sectionA);
        $this->grant(self::TA, 'educatrice-b', self::EA, 'nursery.transition.opinion', $this->sectionB);
        // Ordre des sections saisi par l'école : petite (1) puis moyenne (2), dernière section avant le primaire.
        $this->ok('PATCH', '/nursery/sections/' . $this->sectionA, ['sort_order' => 1]);
        $this->ok('PATCH', '/nursery/sections/' . $this->sectionB, ['sort_order' => 2]);
    }

    /** @return array<string, mixed> */
    private function decisionOf(string $sectionId, string $userId = self::DIRECTOR): array
    {
        return $this->ok('POST', '/nursery/transition/proposals', ['section_id' => $sectionId], $userId)[0];
    }

    public function testPropositionSectionSuivantePuisPrimaireDepuisLaDerniere(): void
    {
        $ada = $this->decisionOf($this->sectionA);
        self::assertSame('promote', $ada['proposal']);
        self::assertSame($this->sectionB, $ada['target_section_id']);
        $bao = $this->decisionOf($this->sectionB);
        self::assertSame('to_primary', $bao['proposal']);
        self::assertSame($ada['id'], $this->decisionOf($this->sectionA)['id'], 'Idempotent.');
    }

    public function testLeMaintienExigeMotifEtDemandeDesParentsSelonLeParametre(): void
    {
        $id = (string) $this->decisionOf($this->sectionA)['id'];
        self::assertSame('opined', $this->ok('POST', '/nursery/transition/decisions/' . $id . '/opinion', ['opinion' => 'Progrès réguliers (exemple).'], 'educatrice-a')['status']);
        self::assertSame(403, $this->code('POST', '/nursery/transition/decisions/' . $id . '/decide', ['decision' => 'promote'], 'educatrice-a'));
        self::assertSame(422, $this->code('POST', '/nursery/transition/decisions/' . $id . '/decide', ['decision' => 'retain', 'reason' => 'Besoin de consolider (exemple).']));
        $retained = $this->ok('POST', '/nursery/transition/decisions/' . $id . '/decide', ['decision' => 'retain', 'reason' => 'Besoin de consolider (exemple).', 'parent_request_ref' => 'DEM-2027-001']);
        self::assertSame('retain', $retained['decision']);
        self::assertSame($this->sectionA, $retained['target_section_id']);
        self::assertSame(409, $this->code('POST', '/nursery/transition/decisions/' . $id . '/opinion', ['opinion' => 'Trop tard'], 'educatrice-a'));
        self::assertSame(403, $this->code('POST', '/nursery/transition/decisions/' . $id . '/opinion', ['opinion' => 'Hors section'], 'educatrice-b'), 'Hors section : 403 journalisé.');
    }

    public function testFicheDeLiaisonPreRempliesSigneeEmiseEtFigee(): void
    {
        $this->ok('POST', '/nursery/observations', ['learner_id' => $this->bao['child'], 'item_id' => $this->items['LANG-1'], 'level_code' => 'acquired', 'observed_on' => '2026-10-15'], 'educatrice-b');
        $this->decisionOf($this->sectionB);
        self::assertSame(409, $this->code('POST', '/nursery/transition/liaison-sheets', ['learner_id' => $this->ada['child']], 'educatrice-a'), 'Ada passe en section suivante : pas de fiche.');
        $sheet = $this->ok('POST', '/nursery/transition/liaison-sheets', ['learner_id' => $this->bao['child']], 'educatrice-b');
        self::assertNotSame('', (string) $sheet['strengths'], 'Points d’appui tirés des niveaux acquis ou en cours.');
        $payload = (string) json_encode($sheet);
        foreach (['allerg', 'restriction', 'custody'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $payload, 'Minimisation : ' . $forbidden);
        }
        $this->ok('PATCH', '/nursery/transition/liaison-sheets/' . $sheet['id'], ['needs' => 'Aime être encouragé (exemple).', 'destination' => 'École primaire fictive'], 'educatrice-b');
        self::assertSame(403, $this->code('POST', '/nursery/transition/liaison-sheets/' . $sheet['id'] . '/sign', [], 'educatrice-b'));
        $signed = $this->ok('POST', '/nursery/transition/liaison-sheets/' . $sheet['id'] . '/sign', ['locale' => 'fr']);
        self::assertSame('signed', $signed['status']);
        self::assertSame('liaison_sheet', $signed['document']['kind']);
        self::assertSame(409, $this->code('PATCH', '/nursery/transition/liaison-sheets/' . $sheet['id'], ['needs' => 'Modifié'], 'educatrice-b'));
        self::assertSame(['liaison_sheet'], array_column($this->ok('GET', '/nursery/portal/documents?learner_id=' . $this->bao['child'], null, 'parent-' . $this->bao['child']), 'kind'));
    }

    public function testClotureControleEtPlanificateurApplicableAuSeulClientNursery(): void
    {
        $readiness = $this->service(ClosureReadiness::class);
        self::assertTrue($readiness->evaluate(self::TA, self::EA, 'AY-2026')->blocks(), 'Deux enfants sans décision.');
        $ada = (string) $this->decisionOf($this->sectionA)['id'];
        $bao = (string) $this->decisionOf($this->sectionB)['id'];
        $this->ok('POST', '/nursery/transition/decisions/' . $ada . '/decide', ['decision' => 'promote']);
        $this->ok('POST', '/nursery/transition/decisions/' . $bao . '/decide', ['decision' => 'to_primary']);
        self::assertFalse($readiness->evaluate(self::TA, self::EA, 'AY-2026')->blocks());
        self::assertTrue($this->ok('GET', '/nursery/transition/closure?academic_year_id=AY-2026')['ready']);

        $planner = $this->service(NurseryPromotionPlanner::class);
        self::assertTrue($planner->appliesTo(self::TA, self::EA));
        $result = $planner->promote(self::TA, self::EA, 'AY-2026', 'AY-2027');
        self::assertSame(['to_primary' => 1, 'enrolled' => 1, 'pending' => 0, 'undecided' => 0, 'already_applied' => 0], $result->counts);
        $baoEnrollments = $this->world->enrollments->forPerson(self::TA, $this->bao['child']);
        self::assertSame('transferred', $baoEnrollments[0]->status);
        $adaYears = array_map(static fn (EnrollmentInfo $e): string => $e->academicYearId . ':' . $e->structureId, $this->world->enrollments->forPerson(self::TA, $this->ada['child']));
        self::assertContains('AY-2027:' . $this->sectionB, $adaYears);
        self::assertSame(2, $planner->promote(self::TA, self::EA, 'AY-2026', 'AY-2027')->counts['already_applied'], 'Idempotent.');
        self::assertSame(409, $this->code('POST', '/nursery/transition/decisions/' . $ada . '/decide', ['decision' => 'retain', 'reason' => 'x', 'parent_request_ref' => 'y']), 'Appliquée : figée.');

        $this->applications->set(self::TB, ApplicationAccess::CLOSED);
        self::assertFalse($planner->appliesTo(self::TB, self::EB), 'Un client sans maternelle privée n’est pas concerné.');
        self::assertFalse($readiness->evaluate(self::TB, self::EB, 'AY-2026')->blocks());
    }
}

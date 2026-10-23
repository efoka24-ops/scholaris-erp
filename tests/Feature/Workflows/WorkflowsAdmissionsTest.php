<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use Scholaris\Modules\Socle\Assessment\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Workflows\Application\WorkflowService;
use Tests\Support\Higher\Admissions\EntryTestCase;
use Tests\Support\Workflows\RecentConfirmations;

/**
 * Circuit « décision d'admission » (admissions_decision) : par défaut le directeur d'école décide ; l'administrateur peut confier
 * la décision à la scolarité ou exiger la confirmation récente. Prérequis, motif et audit restent exigés quel que soit le réglage.
 */
final class WorkflowsAdmissionsTest extends EntryTestCase
{
    private RecentConfirmations $recent;

    protected function setUp(): void
    {
        $this->bootEntry();
        $this->recent = new RecentConfirmations();
        $this->kernel->container()->set(RecentSecondFactor::class, $this->recent);
    }

    /** @param array<string, array<string, mixed>> $steps */
    private function configure(array $steps): void
    {
        $this->service(WorkflowService::class)->override('establishment', self::T, self::E, 'higher', 'admissions_decision', ['steps' => $steps], 'Réglage de test.', 'admin', null);
    }

    public function testParDefautSeulLeDirecteurDEcoleDecide(): void
    {
        $id = (string) $this->completeApplication($this->openCampaign(), 'Defaut')['id'];

        self::assertSame(403, $this->call('POST', '/higher/admissions/applications/' . $id . '/decision', ['decision' => 'conditional', 'reason' => 'Diplôme à vérifier.'], self::SCOL)[0]);
        self::assertSame('conditional', $this->ok('POST', '/higher/admissions/applications/' . $id . '/decision', ['decision' => 'conditional', 'reason' => 'Diplôme à vérifier.'], self::DIR)['status']);
    }

    public function testLaScolaritePeutDevenirCelleQuiDecide(): void
    {
        $this->configure(['decision' => ['actor' => 'scolarite']]);
        $id = (string) $this->completeApplication($this->openCampaign(), 'Scol')['id'];

        self::assertSame(403, $this->call('POST', '/higher/admissions/applications/' . $id . '/decision', ['decision' => 'conditional', 'reason' => 'Diplôme à vérifier.'], self::DIR)[0], 'Le directeur n\'est plus l\'acteur de la décision.');
        self::assertSame('conditional', $this->ok('POST', '/higher/admissions/applications/' . $id . '/decision', ['decision' => 'conditional', 'reason' => 'Diplôme à vérifier.'], self::SCOL)['status']);
    }

    public function testLesPrerequisEtLeMotifRestentExigesQuelQueSoitLActeur(): void
    {
        $this->configure(['decision' => ['actor' => 'scolarite']]);
        $bts = $this->completeApplication($this->openCampaign(), 'Bts', 'bts');
        [$status, $problem] = $this->call('POST', '/higher/admissions/applications/' . $bts['id'] . '/decision', ['decision' => 'conditional', 'reason' => 'x'], self::SCOL);
        self::assertSame(422, $status);
        self::assertStringContainsString('Prérequis', (string) $problem['detail']);

        $tst = $this->completeApplication($this->openCampaign(), 'Piece');
        [$status, $problem] = $this->call('POST', '/higher/admissions/applications/' . $tst['id'] . '/decision', ['decision' => 'admitted', 'reason' => 'Bon dossier.'], self::SCOL);
        self::assertSame(422, $status, 'Pièces bloquantes non vérifiées.');
        self::assertStringContainsString('RG-ADM-1', (string) $problem['detail']);
        self::assertSame(422, $this->call('POST', '/higher/admissions/applications/' . $tst['id'] . '/decision', ['decision' => 'conditional'], self::SCOL)[0], 'Motif obligatoire.');
    }

    public function testLaConfirmationRecentePeutEtreExigeePourDecider(): void
    {
        $this->configure(['decision' => ['recent_confirmation' => true]]);
        $id = (string) $this->completeApplication($this->openCampaign(), 'Conf')['id'];

        [$status, $problem] = $this->call('POST', '/higher/admissions/applications/' . $id . '/decision', ['decision' => 'conditional', 'reason' => 'Diplôme à vérifier.'], self::DIR);
        self::assertSame([403, 'https://api.scholaris.cm/errors/second-factor-required'], [$status, $problem['type']]);
        $this->recent->confirm(self::T, self::DIR);
        self::assertSame('conditional', $this->ok('POST', '/higher/admissions/applications/' . $id . '/decision', ['decision' => 'conditional', 'reason' => 'Diplôme à vérifier.'], self::DIR)['status']);
        self::assertContains('higher.admission.decided', array_column($this->audit->entries, 'action'));
    }
}

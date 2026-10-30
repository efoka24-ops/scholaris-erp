<?php

declare(strict_types=1);

namespace Tests\Unit\Scheduling;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Scheduling\Domain\Conflict;
use Scholaris\Modules\Socle\Scheduling\Domain\ConflictDetector;
use Scholaris\Modules\Socle\Scheduling\Domain\Hhmm;
use Scholaris\Modules\Socle\Scheduling\Domain\SlotPlacement;

/** Conflits d'emploi du temps : enseignant, salle, classe ou groupe, capacité. */
final class ConflictTest extends TestCase
{
    /** @param list<string> $structures */
    private function placement(?string $id = 'a', string $teacher = 't1', ?string $room = 'r1', array $structures = ['c1'], int $weekday = 1, string $start = '08:00', string $end = '10:00', string $from = '2026-09-07', ?string $to = '2027-06-30'): SlotPlacement
    {
        return new SlotPlacement($id, $teacher, $room, $structures, $weekday, $start, $end, $from, $to);
    }

    /** @param list<Conflict> $conflicts @return list<string> */
    private function kinds(array $conflicts): array
    {
        return array_map(static fn (Conflict $c): string => $c->kind, $conflicts);
    }

    public function testLeMemeEnseignantSurDesPlagesQuiSeChevauchentEstEnConflit(): void
    {
        $existing = $this->placement('a');
        $candidate = $this->placement(null, 't1', 'r2', ['c2'], 1, '09:00', '11:00');

        self::assertSame([Conflict::TEACHER], $this->kinds((new ConflictDetector())->detect($candidate, [$existing])));
    }

    public function testLaMemeSalleEstEnConflit(): void
    {
        $candidate = $this->placement(null, 't2', 'r1', ['c2'], 1, '09:30', '10:30');

        self::assertSame([Conflict::ROOM], $this->kinds((new ConflictDetector())->detect($candidate, [$this->placement('a')])));
    }

    public function testLaMemeClasseOuLeMemeGroupeEstEnConflitEtLesStructuresCommunesSontIndiquees(): void
    {
        $candidate = $this->placement(null, 't2', 'r2', ['c1', 'c9'], 1, '08:00', '09:00');
        $conflicts = (new ConflictDetector())->detect($candidate, [$this->placement('a', structures: ['c1', 'c2'])]);

        self::assertSame([Conflict::STRUCTURE], $this->kinds($conflicts));
        self::assertSame(['c1'], $conflicts[0]->structureIds);
        self::assertSame('a', $conflicts[0]->slotId);
    }

    public function testUnSeulCreneauPeutPorterPlusieursStructuresSansSeHeurterLuiMeme(): void
    {
        $mutualised = $this->placement('a', structures: ['c1', 'c2']);

        self::assertSame([], (new ConflictDetector())->detect($mutualised, [$mutualised]));
    }

    public function testDesPlagesJointivesNeSontPasEnConflit(): void
    {
        $candidate = $this->placement(null, 't1', 'r1', ['c1'], 1, '10:00', '12:00');

        self::assertSame([], (new ConflictDetector())->detect($candidate, [$this->placement('a')]));
    }

    public function testUnAutreJourDeLaSemaineNEstPasEnConflit(): void
    {
        self::assertSame([], (new ConflictDetector())->detect($this->placement(null, weekday: 2), [$this->placement('a')]));
    }

    public function testDesPeriodesDeValiditeDisjointesNeSontPasEnConflit(): void
    {
        $semester1 = $this->placement('a', from: '2026-09-07', to: '2027-01-31');
        $semester2 = $this->placement(null, from: '2027-02-01', to: '2027-06-30');

        self::assertSame([], (new ConflictDetector())->detect($semester2, [$semester1]));
    }

    public function testUneValiditeSansFinRecouvreToutCeQuiSuit(): void
    {
        $open = $this->placement('a', to: null);

        self::assertSame([Conflict::TEACHER, Conflict::ROOM, Conflict::STRUCTURE], $this->kinds((new ConflictDetector())->detect($this->placement(null, from: '2030-01-01', to: null), [$open])));
    }

    public function testLeCreneauModifieNEstPasEnConflitAvecLuiMeme(): void
    {
        self::assertSame([], (new ConflictDetector())->detect($this->placement('a'), [$this->placement('a')]));
    }

    public function testLaCapaciteDeSalleInsuffisanteEstSignalee(): void
    {
        $conflicts = (new ConflictDetector())->detect($this->placement(null), [], 30, 45);

        self::assertSame([Conflict::CAPACITY], $this->kinds($conflicts));
        self::assertSame([], (new ConflictDetector())->detect($this->placement(null), [], 50, 45));
        self::assertSame([], (new ConflictDetector())->detect($this->placement(null), [], null, 45), 'capacité inconnue : pas de contrôle');
    }

    public function testLesPlagesDuJourEtDuSoir(): void
    {
        self::assertSame('day', Hhmm::periodOfDay('08:00'));
        self::assertSame('day', Hhmm::periodOfDay('16:59'));
        self::assertSame('evening', Hhmm::periodOfDay('17:00'));
        self::assertTrue(Hhmm::isValid('23:59'));
        self::assertFalse(Hhmm::isValid('24:00'));
        self::assertSame(90, Hhmm::duration('08:00', '09:30'));
    }
}

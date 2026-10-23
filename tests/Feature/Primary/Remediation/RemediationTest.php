<?php

declare(strict_types=1);

namespace Tests\Feature\Primary\Remediation;

use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Primary\PrimaryTestCase;

/** Registre de remédiation et fiche de suivi individualisée (arrêté du 14 août 2026), suivi hebdomadaire paramétré, isolation. */
final class RemediationTest extends PrimaryTestCase
{
    private string $class;
    private string $other;

    protected function setUp(): void
    {
        $enrollments = new InMemoryEnrollments();
        $people = new InMemoryPeople();
        $this->bootPrimary([EnrollmentDirectory::class => $enrollments, PersonDirectory::class => $people]);
        $level = $this->makeLevel(self::TA, self::EA, 'CE1', 2);
        [$this->class, $this->other] = $level['classes'];
        foreach (['e1', 'e2', 'e3'] as $i => $id) {
            $people->add(self::TA, $id, 'Prénom' . $i, 'Nom' . $i);
            $enrollments->add(self::TA, 'en' . $id, $id, self::EA, $this->class, self::YEAR);
        }
        $people->add(self::TA, 'x1', 'Autre', 'Classe');
        $enrollments->add(self::TA, 'enx1', 'x1', self::EA, $this->other, self::YEAR);
        $this->assign(self::TA, 'maitre', self::EA, 'primary.remediation.write', Scope::CLASS_GROUP, $this->class);
    }

    public function testRegistreSuiviHebdomadaireEtFiche(): void
    {
        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, self::EA, 'primary.remediation.fundamental_subjects', ['LECT', 'CALC'], self::DIRECTOR);
        $session = ['held_on' => '2026-10-19', 'duration_minutes' => 40, 'remediation_form' => 'needs_group', 'subject_code' => 'LECT', 'subject_label' => 'Lecture', 'objective' => 'Lire les syllabes', 'person_ids' => ['e1', 'e2']];
        self::assertSame(201, $this->call('POST', "/primary/classes/{$this->class}/remediation", $session, 'maitre')[0]);
        $this->ok('POST', "/primary/classes/{$this->class}/remediation", ['held_on' => '2026-10-21', 'duration_minutes' => 30] + $session, 'maitre');
        self::assertSame(422, $this->call('POST', "/primary/classes/{$this->class}/remediation", ['remediation_form' => 'inconnue'] + $session, 'maitre')[0]);
        self::assertSame(422, $this->call('POST', "/primary/classes/{$this->class}/remediation", ['person_ids' => ['x1']] + $session, 'maitre')[0], 'Élève d\'une autre classe.');
        self::assertSame(403, $this->call('POST', "/primary/classes/{$this->other}/remediation", $session, 'maitre')[0]);

        $register = $this->ok('GET', "/primary/classes/{$this->class}/remediation", null, 'maitre');
        self::assertCount(2, $register['sessions']);
        self::assertSame(60, $register['weekly_minutes_target'], 'Valeur de départ : 1 h par semaine (arrêté du 14 août 2026, à confirmer).');
        $week = array_column($register['weekly'][0]['subjects'], null, 'subject');
        self::assertSame([70, true], [$week['LECT']['minutes'], $week['LECT']['meets_target']]);
        self::assertSame([0, false], [$week['CALC']['minutes'], $week['CALC']['meets_target']]);

        $plan = $this->call('POST', '/primary/remediation-plans', ['person_id' => 'e1', 'class_id' => $this->class, 'subject_code' => 'LECT', 'subject_label' => 'Lecture', 'difficulties' => 'Confond b et d', 'actions' => 'Fiches différenciées', 'opened_on' => '2026-10-01'], 'maitre');
        self::assertSame(201, $plan[0]);
        self::assertSame([2, 70], [$plan[1]['data']['sessions_attended'], $plan[1]['data']['minutes_attended']]);
        $id = $plan[1]['data']['id'];
        $reviewed = $this->ok('POST', "/primary/remediation-plans/$id/reviews", ['progress_note' => 'Progrès nets', 'status_after' => 'resolved', 'reviewed_on' => '2026-11-20'], 'maitre');
        self::assertSame(['resolved', '2026-11-20'], [$reviewed['status'], $reviewed['closed_on']]);
        self::assertCount(1, $reviewed['reviews']);
        self::assertSame(409, $this->call('POST', "/primary/remediation-plans/$id/reviews", ['progress_note' => 'x', 'status_after' => 'open'], 'maitre')[0]);

        $this->assign(self::TA, 'maitre-b', self::EA, 'primary.remediation.read', Scope::CLASS_GROUP, $this->other);
        self::assertSame(404, $this->call('GET', "/primary/remediation-plans/$id", null, 'maitre-b')[0]);
        self::assertSame(404, $this->call('GET', "/primary/classes/{$this->class}/remediation", null, 'maitre-b')[0]);
        $this->grantAll(self::TB, 'directeur-b', self::EB);
        self::assertSame(404, $this->call('GET', "/primary/remediation-plans/$id", null, 'directeur-b', self::TB, self::EB)[0]);
    }
}

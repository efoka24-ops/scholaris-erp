<?php

declare(strict_types=1);

namespace Tests\Feature\Primary\Attendance;

use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Primary\PrimaryTestCase;

/**
 * FIX-PRI 1 : liste d'appel par demi-journée. Le maître affecté à sa classe par son rôle (sans affectation de service ni créneau
 * d'emploi du temps) voit les élèves inscrits et leurs noms ; une autre classe est cachée (404), sans permission d'appel : 403.
 */
final class CallRosterTest extends PrimaryTestCase
{
    private string $class;
    private string $other;

    protected function setUp(): void
    {
        $enrollments = new InMemoryEnrollments();
        $people = new InMemoryPeople();
        $this->bootPrimary([EnrollmentDirectory::class => $enrollments, PersonDirectory::class => $people]);
        [$this->class, $this->other] = $this->makeLevel(self::TA, self::EA, 'CM2', 2)['classes'];
        foreach ([['e1', 'Chantal', 'Fictive'], ['e2', 'Amina', 'Bello'], ['e3', 'Daouda', 'Fictif']] as [$id, $first, $last]) {
            $people->add(self::TA, $id, $first, $last);
            $enrollments->add(self::TA, 'en' . $id, $id, self::EA, $this->class, self::YEAR);
        }
        $people->add(self::TA, 'x1', 'Autre', 'Classe');
        $enrollments->add(self::TA, 'enx1', 'x1', self::EA, $this->other, self::YEAR);
        $this->assign(self::TA, 'maitre', self::EA, 'attendance.take', Scope::CLASS_GROUP, $this->class);
        $this->assign(self::TA, 'direction', self::EA, 'attendance.read', Scope::ESTABLISHMENT);
        $this->assign(self::TA, 'sans-droit', self::EA, 'primary.journal.read', Scope::ESTABLISHMENT);
    }

    public function testLeMaitreVoitLesElevesDeSaClasseParLeurNom(): void
    {
        $roster = $this->ok('GET', "/primary/classes/{$this->class}/call-roster", null, 'maitre');
        self::assertSame('CM2 A', $roster['class']['name']);
        self::assertSame(['e2', 'e3', 'e1'], array_column($roster['learners'], 'person_id'));
        self::assertSame(['BELLO Amina', 'FICTIF Daouda', 'FICTIVE Chantal'], array_column($roster['learners'], 'name'));

        self::assertSame(404, $this->call('GET', "/primary/classes/{$this->other}/call-roster", null, 'maitre')[0], 'Classe hors périmètre.');
        self::assertCount(1, $this->ok('GET', "/primary/classes/{$this->other}/call-roster", null, 'direction')['learners']);
        self::assertSame(403, $this->call('GET', "/primary/classes/{$this->class}/call-roster", null, 'sans-droit')[0]);
        $this->grantAll(self::TB, 'directeur-b', self::EB);
        self::assertSame(404, $this->call('GET', "/primary/classes/{$this->class}/call-roster", null, 'directeur-b', self::TB, self::EB)[0]);
    }
}

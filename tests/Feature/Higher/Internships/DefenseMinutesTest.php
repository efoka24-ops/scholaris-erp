<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Internships;

use Scholaris\Modules\Apps\Higher\Internships\Contracts\DefenseResults;
use Tests\Support\Higher\Internships\InternshipsTestCase;

/** EX-STG-05/06 : convocation (DOC-21, NOT-13), note du jury, PV de soutenance (DOC-22) et lecture des notes clôturées. */
final class DefenseMinutesTest extends InternshipsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInternships();
    }

    /** @return string soutenance programmée le 2026-10-20 */
    private function scheduled(): string
    {
        [$status, $defense] = $this->schedule($this->ready());
        self::assertSame(201, $status);

        return (string) $defense['data']['id'];
    }

    public function testConvocationEmetDoc21EtEnvoieNot13(): void
    {
        $id = $this->scheduled();
        $convened = $this->ok('POST', '/higher/defenses/' . $id . '/convene', null, self::OFFICER);

        self::assertSame('convened', $convened['status']);
        $codes = $this->fakes->codes();
        self::assertSame('DOC-21', end($codes));
        $sent = $this->messenger->sent[0];
        self::assertSame(['p1', 'higher.not13.defense_convocation'], [$sent['person'], $sent['template']]);
        self::assertSame(['subject' => 'Tableaux de bord d\'une PME', 'date' => '2026-10-20', 'time' => '09:00', 'label' => 'Amphi A'], array_intersect_key($sent['variables'], array_flip(['subject', 'date', 'time', 'label'])));
        self::assertSame(409, $this->call('POST', '/higher/defenses/' . $id . '/convene', null, self::OFFICER)[0]);
    }

    public function testNoteParLePresidentLeJourDeLaSoutenancePuisPv(): void
    {
        $id = $this->scheduled();
        self::assertSame(409, $this->call('POST', '/higher/defenses/' . $id . '/grade', ['grade' => 14.5], self::PRESIDENT)[0], 'Pas encore convoquée.');
        $this->ok('POST', '/higher/defenses/' . $id . '/convene', null, self::OFFICER);
        self::assertSame(409, $this->call('POST', '/higher/defenses/' . $id . '/grade', ['grade' => 14.5], self::PRESIDENT)[0], 'Avant le jour.');

        $this->clock->freeze('2026-10-20');
        self::assertSame(403, $this->call('POST', '/higher/defenses/' . $id . '/grade', ['grade' => 14.5], self::SUPERVISOR_USER)[0], 'L\'encadreur ne note pas seul.');
        self::assertSame(404, $this->call('POST', '/higher/defenses/' . $id . '/grade', ['grade' => 14.5], self::STUDENT)[0]);
        self::assertSame(422, $this->call('POST', '/higher/defenses/' . $id . '/grade', ['grade' => 21], self::PRESIDENT)[0]);
        $graded = $this->ok('POST', '/higher/defenses/' . $id . '/grade', ['grade' => 14.5, 'remarks' => 'Bonne maîtrise'], self::PRESIDENT);
        self::assertSame('graded', $graded['status']);
        self::assertEquals(14.5, $graded['grade']);
        self::assertCount(1, $this->ok('GET', '/higher/defenses?mine=1', null, self::PRESIDENT));

        $closed = $this->ok('POST', '/higher/defenses/' . $id . '/minutes', null, self::PRESIDENT);
        self::assertSame('closed', $closed['status']);
        $docs = $this->fakes->issuedDocs;
        $minutes = end($docs);
        self::assertIsArray($minutes);
        self::assertSame('DOC-22', $minutes['code']);
        self::assertSame(['14,50/20', 'Admis(e)', 'Pr Abena'], [$minutes['data']['grade_label'], $minutes['data']['result_label'], $minutes['data']['president_name']]);
        self::assertSame(409, $this->call('POST', '/higher/defenses/' . $id . '/grade', ['grade' => 16], self::PRESIDENT)[0], 'PV établi : note figée.');

        $reader = $this->kernel->container()->get(DefenseResults::class);
        self::assertInstanceOf(DefenseResults::class, $reader);
        $results = $reader->closedFor(self::T, self::E, 'p1');
        self::assertCount(1, $results);
        self::assertEquals(14.5, $results[0]['grade']);
    }
}

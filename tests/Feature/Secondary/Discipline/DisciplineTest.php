<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Discipline;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Councils\Contracts\ConductRules;
use Scholaris\Modules\Apps\Secondary\Discipline\Contracts\ConductRecord;
use Scholaris\Modules\Apps\Secondary\Enrolment\Contracts\StudentRegistry;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceTotals;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Attendance\RecordingMessenger;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * Lot E2-B (T864 à T867) : cumul en heures de l'appel du socle, propositions du barème de l'établissement (RG-PRE-1), décision par
 * une personne habilitée selon le niveau (RG-PRE-2, TST-10), conseil de discipline avec procès-verbal (RG-PRE-3), exclusions de
 * cours rejouées sans doublon. Barème = exemple du CDC (« à confirmer ») posé comme règle de l'établissement. Données fictives.
 */
final class DisciplineTest extends SecondaryTestCase
{
    private const SG = 'surveillant-a';
    private const HEAD = 'proviseur-a';
    private const TEACHER = 'prof-a';
    private const FROM = '2026-09-07';
    private const TO = '2026-12-18';

    private DisciplineSchool $school;
    private RecordingMessenger $messenger;
    private string $leg;

    protected function setUp(): void
    {
        $this->school = new DisciplineSchool();
        $this->messenger = new RecordingMessenger();
        $this->bootSecondary([
            PersonDirectory::class => $this->school,
            OrgUnitTree::class => $this->school,
            EnrollmentDirectory::class => new DisciplineEnrollments($this->school),
            AttendanceTotals::class => $this->school,
            Messenger::class => $this->messenger,
        ]);
        $this->leg = $this->factory->opened(self::TA, 'leg');
        $this->school->classe($this->leg, 'c-2a', 'Seconde A');
        $this->school->classe($this->leg, 'c-1c', 'Première C');
        $this->school->student(self::TA, $this->leg, 'p-aicha', 'Bello', 'Aïcha', 'c-2a', 'p-mere-aicha');
        $this->school->student(self::TA, $this->leg, 'p-brice', 'Nkou', 'Brice', 'c-2a');
        $this->school->student(self::TA, $this->leg, 'p-carine', 'Ewane', 'Carine', 'c-1c');
        $this->grant(self::TA, self::SG, $this->leg, ['secondary.attendance.manage', 'secondary.discipline.manage', 'secondary.attendance.read']);
        $this->grant(self::TA, self::HEAD, $this->leg, ['secondary.discipline.validate', 'secondary.discipline.council.preside', 'secondary.attendance.read']);
        $this->grant(self::TA, self::TEACHER, $this->leg, ['secondary.attendance.take', 'secondary.attendance.read'], Scope::CLASS_GROUP, 'c-2a');
    }

    /** @param list<array<string, mixed>>|null $value */
    private function setScale(?array $value = null): void
    {
        $tiers = $this->service(ConductRules::class)->exampleScale()['tiers'];
        foreach ($tiers as $i => $tier) {
            if ($tier['code'] === 'discipline_council') {
                $tiers[$i]['repeat_offence'] = true;
            }
        }
        $this->pdo()->prepare('INSERT INTO se_school_rules (id, tenant_id, establishment_id, rule_key, value_json, version_no, valid_from, change_reason, changed_by, superseded_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([(string) new Ulid(), self::TA, $this->leg, 'discipline_scale', json_encode($value ?? $tiers), 1, '2026-09-01', 'Règlement intérieur (test)', 'admin', null, '2026-09-01T08:00:00+01:00']);
    }

    /** @return array<string, mixed> */
    private function compute(?string $classId = 'c-2a', string $from = self::FROM, string $to = self::TO, string $who = self::SG): array
    {
        return $this->ok('POST', '/secondary/discipline/proposals/compute', ['class_id' => $classId, 'from' => $from, 'to' => $to, 'period_label' => 'Trimestre 1'], $who, self::TA, $this->leg);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function only(array $result): array
    {
        self::assertCount(1, $result['created']);

        return $result['created'][0];
    }

    public function testTst10VingtHeuresProposentUnBlameAppliqueSeulementApresDecision(): void
    {
        $this->setScale();
        $this->school->attendance(self::TA, $this->leg, 'p-aicha', 120, 22, 20, 3);
        $this->school->attendance(self::TA, $this->leg, 'p-brice', 120, 4, 2);

        $hours = $this->ok('GET', '/secondary/discipline/hours?class_id=c-2a&from=' . self::FROM . '&to=' . self::TO, null, self::TEACHER, self::TA, $this->leg);
        $aicha = array_values(array_filter($hours['students'], static fn (array $s): bool => $s['person_id'] === 'p-aicha'))[0];
        self::assertEquals([22, 20, 2, 3], [$aicha['absent_hours'], $aicha['unjustified_hours'], $aicha['justified_hours'], $aicha['lates']]);

        [$teacher] = $this->call('POST', '/secondary/discipline/proposals/compute', ['class_id' => 'c-2a', 'from' => self::FROM, 'to' => self::TO], self::TEACHER, self::TA, $this->leg);
        self::assertSame(403, $teacher, 'L\'enseignant ne lance pas les propositions.');

        $proposal = $this->only($this->compute());
        self::assertSame(['conduct_blame', 'proposed', 20.0, 'surveillant_general'], [$proposal['proposed_kind'], $proposal['status'], (float) $proposal['unjustified_hours'], $proposal['decision_level']]);
        self::assertSame([], $this->service(ConductRecord::class)->appliedFor(self::TA, $this->leg, 'p-aicha', self::FROM, self::TO), 'Proposée, pas appliquée.');
        $notices = $this->messenger->of('sec.not03.absence_threshold');
        self::assertCount(1, $notices);
        self::assertSame(['p-mere-aicha', '20', 'Trimestre 1'], [$notices[0]['person'], $notices[0]['variables']['hours'], $notices[0]['variables']['period_label']]);

        $again = $this->compute();
        self::assertSame([0, 1], [count($again['created']), $again['unchanged']], 'Recalcul idempotent.');
        self::assertCount(1, $this->messenger->of('sec.not03.absence_threshold'));

        [$noReason] = $this->call('POST', '/secondary/discipline/proposals/' . $proposal['id'] . '/decide', ['decision' => 'reject'], self::SG, self::TA, $this->leg);
        self::assertSame(422, $noReason, 'Rejet motivé.');
        $applied = $this->ok('POST', '/secondary/discipline/proposals/' . $proposal['id'] . '/decide', ['decision' => 'apply'], self::SG, self::TA, $this->leg);
        self::assertSame(['applied', self::SG], [$applied['status'], $applied['decided_by']]);
        [$twice] = $this->call('POST', '/secondary/discipline/proposals/' . $proposal['id'] . '/decide', ['decision' => 'reject', 'reason' => 'Erreur'], self::HEAD, self::TA, $this->leg);
        self::assertSame(409, $twice, 'Une seule décision.');
        self::assertSame('conduct_blame', $this->service(ConductRecord::class)->appliedFor(self::TA, $this->leg, 'p-aicha', self::FROM, self::TO)[0]['code']);
        self::assertContains('secondary.conduct.applied', $this->audit->actions());
    }

    public function testLeNiveauDuPalierDitQuiDecide(): void
    {
        $this->setScale();
        $this->school->attendance(self::TA, $this->leg, 'p-aicha', 120, 30, 30);
        $this->school->attendance(self::TA, $this->leg, 'p-brice', 120, 31, 31);
        $created = $this->compute()['created'];
        $byPerson = array_column($created, null, 'person_id');
        self::assertSame('temporary_exclusion', $byPerson['p-aicha']['proposed_kind']);
        self::assertSame('discipline_council', $byPerson['p-brice']['proposed_kind']);

        [$sg] = $this->call('POST', '/secondary/discipline/proposals/' . $byPerson['p-aicha']['id'] . '/decide', ['decision' => 'apply'], self::SG, self::TA, $this->leg);
        self::assertSame(403, $sg, 'Exclusion temporaire : chef d\'établissement.');
        self::assertSame('applied', $this->ok('POST', '/secondary/discipline/proposals/' . $byPerson['p-aicha']['id'] . '/decide', ['decision' => 'apply'], self::HEAD, self::TA, $this->leg)['status']);

        [$council] = $this->call('POST', '/secondary/discipline/proposals/' . $byPerson['p-brice']['id'] . '/decide', ['decision' => 'apply'], self::HEAD, self::TA, $this->leg);
        self::assertSame(409, $council, 'Palier du conseil de discipline : jamais appliqué hors conseil (RG-PRE-3).');
        self::assertSame('rejected', $this->ok('POST', '/secondary/discipline/proposals/' . $byPerson['p-brice']['id'] . '/decide', ['decision' => 'reject', 'reason' => 'Absences régularisées par certificat médical tardif.'], self::HEAD, self::TA, $this->leg)['status']);
    }

    public function testUnPalierSuperieurRemplaceLaPropositionOuverteEtLaRecidiveVaAuConseil(): void
    {
        $this->setScale();
        $this->school->attendance(self::TA, $this->leg, 'p-aicha', 60, 12, 12);
        $warning = $this->only($this->compute());
        self::assertSame('conduct_warning', $warning['proposed_kind']);
        $this->school->attendance(self::TA, $this->leg, 'p-aicha', 90, 21, 21);
        $blame = $this->only($this->compute());
        self::assertSame('conduct_blame', $blame['proposed_kind']);
        self::assertSame('superseded', $this->ok('GET', '/secondary/discipline/proposals/' . $warning['id'], null, self::SG, self::TA, $this->leg)['status']);
        $this->ok('POST', '/secondary/discipline/proposals/' . $blame['id'] . '/decide', ['decision' => 'apply'], self::SG, self::TA, $this->leg);

        $this->school->attendance(self::TA, $this->leg, 'p-aicha', 40, 10, 10);
        $repeat = $this->only($this->compute('c-2a', '2027-01-04', '2027-03-26'));
        self::assertSame(['discipline_council', true], [$repeat['proposed_kind'], $repeat['repeat_offence']], 'Récidive : conseil de discipline.');
    }

    public function testSansBaremeAucunePropositionEtUnBaremeInvalideEstSignale(): void
    {
        $this->school->attendance(self::TA, $this->leg, 'p-aicha', 120, 40, 40);
        $scale = $this->ok('GET', '/secondary/discipline/scale', null, self::SG, self::TA, $this->leg);
        self::assertFalse($scale['configured']);
        self::assertSame('to_confirm', $scale['example_status']);
        self::assertCount(4, $scale['example']['tiers']);
        [$none] = $this->call('POST', '/secondary/discipline/proposals/compute', ['class_id' => 'c-2a', 'from' => self::FROM, 'to' => self::TO], self::SG, self::TA, $this->leg);
        self::assertSame(409, $none, 'Rien n\'est appliqué d\'office : le barème est celui de l\'établissement.');

        $this->setScale([['code' => 'x']]);
        self::assertNotSame([], $this->ok('GET', '/secondary/discipline/scale', null, self::SG, self::TA, $this->leg)['errors']);
    }

    public function testLesSeuilsViennentDuReglementDeLEtablissement(): void
    {
        $this->setScale([['from_hours' => 5, 'code' => 'avertissement_maison', 'label' => 'Avertissement', 'level' => 'surveillant_general']]);
        $this->school->attendance(self::TA, $this->leg, 'p-brice', 50, 6, 6);
        self::assertSame('avertissement_maison', $this->only($this->compute())['proposed_kind']);
    }

    public function testConseilDeDisciplineExclusionDefinitiveSeulementAvecProcesVerbalEtAudition(): void
    {
        $this->setScale();
        $this->school->attendance(self::TA, $this->leg, 'p-aicha', 120, 35, 35);
        $proposal = $this->only($this->compute());

        $council = $this->ok('POST', '/secondary/discipline/councils', [
            'person_id' => 'p-aicha', 'class_id' => 'c-2a', 'proposal_id' => $proposal['id'], 'facts' => '35 heures d\'absence non justifiées au premier trimestre.',
            'hearing_on' => '2026-10-30', 'hearing_time' => '10:00', 'place' => 'Salle des actes', 'members' => ['Proviseur (président)', 'Surveillant général', 'Représentant APEE'],
        ], self::SG, self::TA, $this->leg);
        self::assertSame('convened', $council['status']);
        $convocation = $this->messenger->of('sec.not06.discipline_council');
        self::assertCount(1, $convocation);
        self::assertSame(['2026-10-30', '10:00', 'Salle des actes'], [$convocation[0]['variables']['date'], $convocation[0]['variables']['time'], $convocation[0]['variables']['place']]);

        [$teacher] = $this->call('POST', '/secondary/discipline/councils/' . $council['id'] . '/decide', ['decision_kind' => 'final_exclusion', 'decision_reason' => 'x'], self::TEACHER, self::TA, $this->leg);
        self::assertSame(403, $teacher);
        [$noMinutes, $json] = $this->call('POST', '/secondary/discipline/councils/' . $council['id'] . '/decide', ['decision_kind' => 'final_exclusion', 'decision_reason' => 'Absentéisme persistant.'], self::HEAD, self::TA, $this->leg);
        self::assertSame(422, $noMinutes, 'Exclusion définitive sans procès-verbal : refus.');
        self::assertSame(['minutes', 'parents_heard', 'student_heard'], self::sortedKeys($json['errors']));
        self::assertNull($this->service(StudentRegistry::class)->profile(self::TA, $this->leg, 'p-aicha')?->finalExclusionOn);

        $decided = $this->ok('POST', '/secondary/discipline/councils/' . $council['id'] . '/decide', [
            'decision_kind' => 'final_exclusion', 'decision_reason' => 'Absentéisme persistant malgré deux avertissements.',
            'minutes' => 'Procès-verbal fictif : l\'élève et sa mère ont été entendus ; vote à la majorité.', 'student_heard' => true, 'parents_heard' => true,
        ], self::HEAD, self::TA, $this->leg);
        self::assertSame(['decided', 'final_exclusion'], [$decided['status'], $decided['decision_kind']]);
        $linked = $this->ok('GET', '/secondary/discipline/proposals/' . $proposal['id'], null, self::SG, self::TA, $this->leg);
        self::assertSame(['applied', $council['id']], [$linked['status'], $linked['council_id']]);
        self::assertSame('2026-10-23', $this->service(StudentRegistry::class)->profile(self::TA, $this->leg, 'p-aicha')?->finalExclusionOn);
        [$twice] = $this->call('POST', '/secondary/discipline/councils/' . $council['id'] . '/decide', ['decision_kind' => 'no_sanction', 'decision_reason' => 'x'], self::HEAD, self::TA, $this->leg);
        self::assertSame(409, $twice);
    }

    public function testUnConseilSansSanctionRejetteLaPropositionLiee(): void
    {
        $this->setScale();
        $this->school->attendance(self::TA, $this->leg, 'p-brice', 120, 32, 32);
        $proposal = $this->only($this->compute());
        $council = $this->ok('POST', '/secondary/discipline/councils', ['person_id' => 'p-brice', 'class_id' => 'c-2a', 'proposal_id' => $proposal['id'], 'facts' => 'Absences.', 'hearing_on' => '2026-10-28', 'hearing_time' => '15:30', 'place' => 'Bureau du proviseur'], self::HEAD, self::TA, $this->leg);
        [$wrong] = $this->call('POST', '/secondary/discipline/councils', ['person_id' => 'p-carine', 'class_id' => 'c-2a', 'facts' => 'x', 'hearing_on' => '2026-10-28', 'hearing_time' => '15:30', 'place' => 'x'], self::HEAD, self::TA, $this->leg);
        self::assertSame(404, $wrong, 'Élève hors de la classe.');
        $this->ok('POST', '/secondary/discipline/councils/' . $council['id'] . '/decide', ['decision_kind' => 'no_sanction', 'decision_reason' => 'Situation familiale exceptionnelle établie.'], self::HEAD, self::TA, $this->leg);
        self::assertSame('rejected', $this->ok('GET', '/secondary/discipline/proposals/' . $proposal['id'], null, self::SG, self::TA, $this->leg)['status']);
    }

    public function testExclusionDeCoursRejoueeSansDoublonEtBorneeALaClasse(): void
    {
        $body = ['client_uuid' => 'a7c1d1b2-0000-4000-8000-000000000001', 'person_id' => 'p-brice', 'class_id' => 'c-2a', 'session_date' => '2026-10-23', 'hour_label' => '08:00-08:55', 'reason' => 'Perturbation répétée du cours.'];
        [$created, $first] = $this->call('POST', '/secondary/discipline/class-exclusions', $body, self::TEACHER, self::TA, $this->leg);
        self::assertSame(201, $created);
        self::assertFalse($first['data']['replayed']);
        [$replayed, $second] = $this->call('POST', '/secondary/discipline/class-exclusions', $body, self::TEACHER, self::TA, $this->leg);
        self::assertSame(200, $replayed);
        self::assertSame([true, $first['data']['id']], [$second['data']['replayed'], $second['data']['id']]);
        self::assertCount(1, $this->ok('GET', '/secondary/discipline/class-exclusions', null, self::SG, self::TA, $this->leg));

        [$other] = $this->call('POST', '/secondary/discipline/class-exclusions', ['client_uuid' => 'u-2', 'class_id' => 'c-1c', 'person_id' => 'p-carine'] + $body, self::TEACHER, self::TA, $this->leg);
        self::assertSame(403, $other, 'Classe hors du périmètre de l\'enseignant.');
        [$notInClass] = $this->call('POST', '/secondary/discipline/class-exclusions', ['client_uuid' => 'u-3', 'person_id' => 'p-carine'] + $body, self::TEACHER, self::TA, $this->leg);
        self::assertSame(404, $notInClass);
        [$hours] = $this->call('GET', '/secondary/discipline/hours?class_id=c-1c', null, self::TEACHER, self::TA, $this->leg);
        self::assertSame(404, $hours, 'Lecture hors périmètre : existence cachée.');
    }

    public function testCloisonnementEntreClients(): void
    {
        $this->setScale();
        $this->school->attendance(self::TA, $this->leg, 'p-aicha', 120, 20, 20);
        $proposal = $this->only($this->compute());
        $legB = $this->factory->opened(self::TB, 'leg');
        $this->grant(self::TB, 'surveillant-b', $legB, ['secondary.discipline.manage', 'secondary.discipline.validate']);
        [$read] = $this->call('GET', '/secondary/discipline/proposals/' . $proposal['id'], null, 'surveillant-b', self::TB, $legB);
        self::assertSame(404, $read);
        [$decide] = $this->call('POST', '/secondary/discipline/proposals/' . $proposal['id'] . '/decide', ['decision' => 'apply'], 'surveillant-b', self::TB, $legB);
        self::assertSame(404, $decide);
        self::assertSame([], $this->ok('GET', '/secondary/discipline/proposals', null, 'surveillant-b', self::TB, $legB));
    }

    public function testPeriodeParDefautExigeUnePeriodeCouvrante(): void
    {
        [$status, $json] = $this->call('GET', '/secondary/discipline/hours?class_id=c-2a', null, self::SG, self::TA, $this->leg);
        self::assertSame(422, $status);
        self::assertArrayHasKey('from', $json['errors']);
    }

    /**
     * @param array<string, mixed> $errors
     * @return list<string>
     */
    private static function sortedKeys(array $errors): array
    {
        $keys = array_map('strval', array_keys($errors));
        sort($keys);

        return $keys;
    }
}

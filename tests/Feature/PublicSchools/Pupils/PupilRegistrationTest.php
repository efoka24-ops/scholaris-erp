<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Pupils;

use Scholaris\Modules\Apps\PublicSchools\Contracts\PupilDraft;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PupilRegistry;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\LearnerNumbering;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/** ELV, API-02 : inscription (TST-01, TST-02), identifiant national, doublons (Q7), âge d'entrée (RG-ELV-3), NOT-01. Données SYN-*. */
final class PupilRegistrationTest extends PublicSchoolsTestCase
{
    private string $class1 = '';

    protected function setUp(): void
    {
        $this->bootPublic();
        foreach ([['t1', 'e1', 'SYN-E1', 'open'], ['t1', 'e2', 'SYN-E2', 'closed'], ['t1', 'e3', 'SYN-E3', 'open'], ['t2', 'e9', 'SYN-E9', 'open']] as [$tenant, $establishment, $code, $status]) {
            $this->seedSchool($tenant, $establishment, $code, $status);
        }
        $this->director('dir1', 't1', 'e1', 'public_schools.pupils.read', 'public_schools.pupils.write');
        $this->director('dir3', 't1', 'e3', 'public_schools.pupils.read', 'public_schools.pupils.write');
        $this->director('dir9', 't2', 'e9', 'public_schools.pupils.read', 'public_schools.pupils.write');
        $this->class1 = $this->createClass('t1', 'e1', 'SIL A SYN', 'y2026', 'SIL');
    }

    /** @return array<string, mixed> */
    private function body(array $changes = []): array
    {
        return $changes + [
            'last_name' => 'SYN-Mbarga',
            'first_name' => 'Awa',
            'birth_date' => '2020-03-14',
            'sex' => 'F',
            'birth_act_ref' => 'SYN-ACTE-001',
            'class_id' => $this->class1,
            'guardians' => [['kind' => 'parent', 'identity' => ['first_name' => 'Jean', 'last_name' => 'SYN-Mbarga', 'phone' => '677000111']]],
        ];
    }

    /** @return array<string, mixed> */
    private function register(array $changes = [], string $user = 'dir1', string $tenant = 't1', string $establishment = 'e1'): array
    {
        [$response, $json] = $this->api('POST', '/public/pupils', $user, $tenant, $establishment, $this->body($changes));
        self::assertSame(201, $response->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));

        return $json['data'];
    }

    public function testInscriptionAvecIdentifiantNationalInscriptionEtNotification(): void
    {
        $data = $this->register();

        self::assertSame('SYNR1-2026-0000001', $data['learner_number']);
        self::assertSame([], $data['warnings']);
        self::assertFalse($data['replayed']);
        $enrollments = $this->kernel->container()->get(EnrollmentDirectory::class)->forPerson('t1', $data['person_id']);
        self::assertCount(1, $enrollments);
        self::assertSame(['e1', $this->class1, 'y2026'], [$enrollments[0]->establishmentId, $enrollments[0]->structureId, $enrollments[0]->academicYearId]);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM pub_pupil_profiles WHERE tenant_id = ? AND person_id = ?', ['t1', $data['person_id']]));
        self::assertSame($data['learner_number'], $this->kernel->container()->get(LearnerNumbering::class)->find('t1', $data['person_id']));
        self::assertCount(1, $this->messages->sent, 'NOT-01 : les garants reçoivent l\'identifiant.');
        self::assertSame('public_pupil_registered', $this->messages->sent[0]['template']);
        self::assertSame('SYNR1-2026-0000001', $this->messages->sent[0]['variables']['learner_number']);

        $second = $this->register(['last_name' => 'SYN-Biya', 'guardians' => [], 'class_id' => null]);
        self::assertSame('SYNR1-2026-0000002', $second['learner_number'], 'Séquence annuelle par région.');
        self::assertCount(1, $this->messages->sent, 'Sans garant, aucune notification.');
    }

    public function testAucunChampApeeNiDansLaRequeteNiDansLeSchema(): void
    {
        // TST-01 : la requête refuse tout champ APEE ...
        self::assertSame(422, $this->code('POST', '/public/pupils', 'dir1', 't1', 'e1', $this->body(['apee_paid' => true])));
        self::assertSame(422, $this->code('POST', '/public/pupils', 'dir1', 't1', 'e1', $this->body(['contribution' => 5000])));
        // ... et aucune table d'élève n'a de colonne APEE.
        foreach (['pub_pupil_profiles', 'pub_pupil_registrations', 'pub_transfers', 'pub_dropouts', 'pub_class_profiles'] as $table) {
            $columns = array_column($this->connections()->pdo()->query('PRAGMA table_info(' . $table . ')')->fetchAll(\PDO::FETCH_ASSOC), 'name');
            self::assertSame([], array_values(array_filter($columns, static fn (string $name): bool => str_contains($name, 'apee'))), $table);
        }
    }

    public function testSansActeDeNaissanceInscriptionAccepteeEtSignalee(): void
    {
        $data = $this->register(['birth_act_ref' => null]);

        self::assertSame(['birth_act_missing'], $data['warnings']);
        [, $list] = $this->api('GET', '/public/pupils/without-birth-act', 'dir1', 't1', 'e1');
        self::assertSame([$data['person_id']], array_column($list['data'], 'person_id'));
        self::assertSame($data['learner_number'], $list['data'][0]['learner_number']);
    }

    public function testAgeHorsNormeAvertitSansRefuser(): void
    {
        $young = $this->register(['birth_date' => '2021-06-01', 'last_name' => 'SYN-Jeune']);
        self::assertSame(['age_out_of_range'], $young['warnings'], 'Cinq ans au 31/12 en SIL : avertissement, pas de refus.');

        $normal = $this->register(['birth_date' => '2020-06-01', 'last_name' => 'SYN-Normal']);
        self::assertSame([], $normal['warnings']);

        $this->settings->values['public_schools.entry_age.tolerance'] = 1;
        self::assertSame([], $this->register(['birth_date' => '2021-06-02', 'last_name' => 'SYN-Tolere'])['warnings']);
    }

    public function testAlerteDEffectifALInscription(): void
    {
        $this->settings->values['public_schools.class_size.alert'] = 2;
        $this->register(['last_name' => 'SYN-A', 'birth_date' => '2020-01-01']);
        $this->register(['last_name' => 'SYN-B', 'birth_date' => '2020-01-02']);
        $third = $this->register(['last_name' => 'SYN-C', 'birth_date' => '2020-01-03']);

        self::assertSame(['class_size_alert'], $third['warnings']);
    }

    public function testDoublonProbableProposeLaFicheExistante(): void
    {
        $first = $this->register();

        [$conflict, $json] = $this->api('POST', '/public/pupils', 'dir1', 't1', 'e1', $this->body());
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame([$first['person_id']], $json['errors']['possible_duplicates']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM people WHERE tenant_id = ? AND first_name = ?', ['t1', 'Awa']));

        self::assertSame(422, $this->code('POST', '/public/pupils', 'dir1', 't1', 'e1', $this->body(['confirm_different' => true])), 'Le motif est obligatoire pour passer outre.');
        $override = $this->register(['confirm_different' => true, 'override_reason' => 'Jumelle : acte SYN-ACTE-002', 'birth_act_ref' => 'SYN-ACTE-002']);
        self::assertContains('possible_duplicate', $override['warnings']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM audit_log WHERE tenant_id = ? AND action = ?', ['t1', 'public_schools.pupil.duplicate_overridden']));
    }

    public function testLeDoublonEstChercheDansLeDepartementEtJamaisChezUnAutreClient(): void
    {
        $this->register();
        $class3 = $this->createClass('t1', 'e3', 'SIL A SYN3', 'y2026', 'SIL');

        // e3 est dans l'arrondissement A2, même département D1 : le doublon est proposé.
        [$sameDepartment] = $this->api('POST', '/public/pupils', 'dir3', 't1', 'e3', $this->body(['class_id' => $class3]));
        self::assertSame(409, $sameDepartment->getStatusCode());

        // Un autre client enregistre le même enfant sans conflit, avec sa propre séquence.
        $class9 = $this->createClass('t2', 'e9', 'SIL A SYN9', 'y2026', 'SIL');
        $other = $this->register(['class_id' => $class9], 'dir9', 't2', 'e9');
        self::assertSame('SYNRB-2026-0000001', $other['learner_number']);
    }

    public function testInscriptionRejoueeParIdentifiantClientEstIdempotente(): void
    {
        $uuid = '7f3b2c1e-9a4d-4e6f-8b1a-2c3d4e5f6a7b';
        $first = $this->register(['client_uuid' => $uuid]);
        [$replay, $json] = $this->api('POST', '/public/pupils', 'dir1', 't1', 'e1', $this->body(['client_uuid' => $uuid]));

        self::assertSame(200, $replay->getStatusCode());
        self::assertTrue($json['data']['replayed']);
        self::assertSame($first['person_id'], $json['data']['person_id']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM people WHERE tenant_id = ? AND first_name = ?', ['t1', 'Awa']));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM pub_pupil_registrations WHERE tenant_id = ?', ['t1']));
    }

    public function testEcoleFermeeOuClasseDUneAutreEcoleRefusees(): void
    {
        $this->director('dir2', 't1', 'e2', 'public_schools.pupils.write');
        self::assertSame(422, $this->code('POST', '/public/pupils', 'dir2', 't1', 'e2', $this->body(['class_id' => null])), 'École fermée.');
        $class3 = $this->createClass('t1', 'e3', 'SIL A SYN3', 'y2026', 'SIL');
        self::assertSame(422, $this->code('POST', '/public/pupils', 'dir1', 't1', 'e1', $this->body(['class_id' => $class3])), 'Classe d\'une autre école.');
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM people WHERE tenant_id = ?', ['t1']));
    }

    public function testValidationDuCorps(): void
    {
        foreach ([
            ['last_name' => ''],
            ['birth_date' => '2999-01-01'],
            ['birth_date' => '14/03/2020'],
            ['sex' => 'X'],
            ['client_uuid' => 'pas-un-uuid'],
            ['guardians' => [['kind' => 'oncle', 'person_id' => 'x']]],
            ['guardians' => [['kind' => 'parent']]],
        ] as $invalid) {
            self::assertSame(422, $this->code('POST', '/public/pupils', 'dir1', 't1', 'e1', $this->body($invalid)), json_encode($invalid, JSON_THROW_ON_ERROR));
        }
    }

    public function testDroitsEtDroitDUsage(): void
    {
        $this->teacher('maitre', 't1', 'e1', $this->class1, 'public_schools.pupils.read', 'public_schools.pupils.write');
        $this->access->grant('nobody', 'e1');
        $this->supervisor('clerc', 't1', 'e1', 'A1', 'pub_inspection_clerk', 'public_schools.pupils.read', 'public_schools.pupils.write');

        self::assertSame(403, $this->code('POST', '/public/pupils', 'maitre', 't1', 'e1', $this->body()), 'Un enseignant ne gère pas les inscriptions.');
        self::assertSame(403, $this->code('POST', '/public/pupils', 'nobody', 't1', 'e1', $this->body()));
        self::assertSame(201, $this->code('POST', '/public/pupils', 'clerc', 't1', 'e1', $this->body()), 'EX-ELV-07 : l\'agent de saisie de l\'inspection inscrit pour l\'école.');

        $this->applications->set('t1', 'public_primary', ApplicationAccess::SUSPENDED);
        self::assertSame(423, $this->code('POST', '/public/pupils', 'dir1', 't1', 'e1', $this->body(['last_name' => 'SYN-Autre'])));
    }

    public function testUneNotificationEnEchecNAnnulePasLInscription(): void
    {
        $this->messages->failing = true;

        $data = $this->register();

        self::assertSame(['guardian_notification_failed'], $data['warnings']);
        self::assertNotNull($data['learner_number']);
    }

    public function testContratPupilRegistry(): void
    {
        $registry = $this->kernel->container()->get(PupilRegistry::class);
        $data = $this->register();

        $found = $registry->find('t1', $data['person_id']);
        self::assertNotNull($found);
        self::assertSame($data['learner_number'], $found->learnerNumber);
        self::assertSame('2020-03-14', $found->birthDate);
        self::assertFalse($found->birthActVerified);
        self::assertSame($data['person_id'], $registry->findByLearnerNumber('t1', $data['learner_number'])?->personId);
        self::assertNull($registry->find('t2', $data['person_id']), 'Autre client : introuvable.');
        self::assertNull($registry->findByLearnerNumber('t2', $data['learner_number']));
        self::assertCount(1, $registry->possibleDuplicates('t1', 'e1', new PupilDraft('SYN-MBARGA', 'awa', '2020-03-14', 'F')));
        self::assertSame([], $registry->possibleDuplicates('t2', 'e9', new PupilDraft('SYN-Mbarga', 'Awa', '2020-03-14', 'F')));

        $registered = $registry->register('t1', 'e1', new PupilDraft('SYN-Ndongo', 'Paul', '2020-05-05', 'M', null, null), null, 'dir1');
        self::assertSame(['birth_act_missing'], $registered->warnings);
        try {
            $registry->register('t1', 'e1', new PupilDraft('SYN-Ndongo', 'Marc', '2020-05-06', 'M'), $this->class1, 'dir1');
            self::fail('Un élève mineur ne s\'inscrit pas en classe sans garant.');
        } catch (\Scholaris\Core\Http\HttpException $refusal) {
            self::assertSame(422, $refusal->status);
        }
        $this->expectException(\InvalidArgumentException::class);
        $registry->register('t1', 'e2', new PupilDraft('SYN-Ndongo', 'Luc', '2020-05-05', 'M'), null, 'dir1');
    }
}

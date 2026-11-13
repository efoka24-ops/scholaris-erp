<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Pupils;

use Scholaris\Modules\Apps\PublicSchools\Contracts\PupilTransfer;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/** EX-ELV-05, API-03, RG-ELV-4, RG-ELV-5, DOC-03 : demande, accord, clôture à la date d'accueil, dossier utile, exeat. Données SYN-*. */
final class TransferTest extends PublicSchoolsTestCase
{
    private string $classOrigin = '';
    private string $classHost = '';
    private string $pupil = '';

    protected function setUp(): void
    {
        $this->bootPublic();
        foreach ([['t1', 'e1', 'SYN-E1', 'open'], ['t1', 'e2', 'SYN-E2', 'open'], ['t1', 'e3', 'SYN-E3', 'closed'], ['t2', 'e9', 'SYN-E9', 'open']] as [$tenant, $establishment, $code, $status]) {
            $this->seedSchool($tenant, $establishment, $code, $status);
        }
        foreach ([['dir1', 't1', 'e1'], ['dir2', 't1', 'e2'], ['dir3', 't1', 'e3'], ['dir9', 't2', 'e9']] as [$user, $tenant, $establishment]) {
            $this->director($user, $tenant, $establishment, 'public_schools.pupils.read', 'public_schools.pupils.write');
        }
        $this->classOrigin = $this->createClass('t1', 'e1', 'SIL A SYN');
        $this->classHost = $this->createClass('t1', 'e2', 'SIL A SYN HOTE');
        [$response, $json] = $this->api('POST', '/public/pupils', 'dir1', 't1', 'e1', [
            'last_name' => 'SYN-Mbarga', 'first_name' => 'Awa', 'birth_date' => '2020-03-14', 'sex' => 'F', 'birth_act_ref' => 'SYN-ACTE-1', 'class_id' => $this->classOrigin,
            'guardians' => [['kind' => 'parent', 'identity' => ['first_name' => 'Jean', 'last_name' => 'SYN-Mbarga', 'phone' => '677000111']]],
        ]);
        self::assertSame(201, $response->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));
        $this->pupil = $json['data']['person_id'];
    }

    /** DOC-03 : type de document et modèle du certificat de radiation (fournis en production par PublicSchools\Documents). */
    private function seedExeatDocument(): void
    {
        $now = '2026-10-08T00:00:00+01:00';
        $pdo = $this->connections()->pdo();
        $pdo->prepare(
            'INSERT INTO document_types (id, tenant_id, establishment_id, code, label, category, type_letter, number_template, qr_enabled, blockable, variables, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute(['01JZ0000000000000000000091', '*', '', 'public_exeat', 'Certificat de radiation', 'certificate', 'X', '{CODE}-{AA}-{LETTRE}-{N6}', 1, 0, '["learner_number","arrival_on"]', $now, $now]);
        $pdo->prepare(
            'INSERT INTO document_templates (id, tenant_id, establishment_id, type_code, locale, html, version, validated_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)',
        )->execute(['01JZ0000000000000000000092', '*', '', 'public_exeat', 'fr', '<html><body>Radiation {{holder_name}} {{learner_number}} {{arrival_on}} {{number}}</body></html>', 1, $now, $now]);
    }

    /** @return array<string, mixed> */
    private function request(string $to = 'e2'): array
    {
        [$response, $json] = $this->api('POST', '/public/transfers', 'dir1', 't1', 'e1', ['person_id' => $this->pupil, 'to_establishment_id' => $to]);
        self::assertSame(201, $response->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));

        return $json['data'];
    }

    /** @return list<array{0: string, 1: string, 2: ?string}> établissement, statut, date de clôture */
    private function enrollments(): array
    {
        $rows = [];
        foreach ($this->kernel->container()->get(EnrollmentDirectory::class)->forPerson('t1', $this->pupil) as $enrollment) {
            $rows[] = [$enrollment->establishmentId, $enrollment->status, $this->scalar('SELECT closed_on FROM enrollments WHERE id = ?', [$enrollment->id]) ?: null];
        }
        sort($rows);

        return $rows;
    }

    public function testParcoursCompletDeLaDemandeALInscriptionDansLEcoleDAccueil(): void
    {
        $this->seedExeatDocument();
        $transfer = $this->request();
        self::assertSame('requested', $transfer['state']);
        self::assertSame([['e1', 'conditional', null]], $this->enrollments(), 'RG-ELV-4 : aucune inscription n\'est créée avant l\'accord.');

        [, $incoming] = $this->api('GET', '/public/transfers?direction=incoming', 'dir2', 't1', 'e2');
        self::assertSame([$transfer['id']], array_column($incoming['data'], 'id'));
        self::assertSame('Awa SYN-Mbarga', $incoming['data'][0]['pupil_name'] ?? null);

        [$accepted, $json] = $this->api('POST', '/public/transfers/' . $transfer['id'] . '/accept', 'dir2', 't1', 'e2', ['arrival_on' => '2026-11-02', 'class_id' => $this->classHost]);
        self::assertSame(200, $accepted->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));
        self::assertSame('completed', $json['data']['state']);
        self::assertTrue($json['data']['exeat_issued']);
        self::assertSame([['e1', 'transferred', '2026-11-02'], ['e2', 'conditional', null]], $this->enrollments());
        self::assertNotNull($this->scalar('SELECT exeat_document_id FROM pub_transfers WHERE id = ?', [$transfer['id']]));
        self::assertSame($this->classHost, $json['data']['to_structure_id']);
    }

    public function testAccordSansClasseLaisseLEleveSansEcoleJusquALInscription(): void
    {
        $transfer = $this->request();

        [, $json] = $this->api('POST', '/public/transfers/' . $transfer['id'] . '/accept', 'dir2', 't1', 'e2', ['arrival_on' => '2026-11-02']);
        self::assertSame('accepted', $json['data']['state']);
        self::assertFalse($json['data']['exeat_issued'], 'Modèle DOC-03 absent : le certificat est différé.');
        self::assertSame([['e1', 'transferred', '2026-11-02']], $this->enrollments());

        self::assertSame(422, $this->code('POST', '/public/transfers/' . $transfer['id'] . '/complete', 'dir2', 't1', 'e2', ['class_id' => $this->classOrigin]), 'Classe d\'une autre école.');
        [$completed, $done] = $this->api('POST', '/public/transfers/' . $transfer['id'] . '/complete', 'dir2', 't1', 'e2', ['class_id' => $this->classHost]);
        self::assertSame(200, $completed->getStatusCode());
        self::assertSame('completed', $done['data']['state']);
        self::assertSame([['e1', 'transferred', '2026-11-02'], ['e2', 'conditional', null]], $this->enrollments());
    }

    public function testCertificatDeRadiationDifferePuisDelivre(): void
    {
        $transfer = $this->request();
        self::assertSame(422, $this->code('POST', '/public/transfers/' . $transfer['id'] . '/exeat', 'dir1', 't1', 'e1'), 'Pas avant l\'accord.');
        $this->api('POST', '/public/transfers/' . $transfer['id'] . '/accept', 'dir2', 't1', 'e2', ['arrival_on' => '2026-11-02']);

        self::assertSame(409, $this->code('POST', '/public/transfers/' . $transfer['id'] . '/exeat', 'dir1', 't1', 'e1'), 'Modèle de document indisponible.');
        $this->seedExeatDocument();
        [$issued, $json] = $this->api('POST', '/public/transfers/' . $transfer['id'] . '/exeat', 'dir1', 't1', 'e1');
        self::assertSame(201, $issued->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));
        self::assertSame(403, $this->code('POST', '/public/transfers/' . $transfer['id'] . '/exeat', 'dir2', 't1', 'e2'), 'L\'exeat vient de l\'école d\'origine.');
    }

    public function testDossierUtileApresLAccordSeulement(): void
    {
        $transfer = $this->request();
        self::assertSame(422, $this->code('GET', '/public/transfers/' . $transfer['id'] . '/dossier', 'dir2', 't1', 'e2'));
        $this->api('POST', '/public/transfers/' . $transfer['id'] . '/accept', 'dir2', 't1', 'e2', ['arrival_on' => '2026-11-02']);

        [$dossier, $json] = $this->api('GET', '/public/transfers/' . $transfer['id'] . '/dossier', 'dir2', 't1', 'e2');
        self::assertSame(200, $dossier->getStatusCode());
        self::assertSame('SYN-Mbarga', $json['data']['last_name']);
        self::assertMatchesRegularExpression('/^SYNR1-2026-\d{7}$/', (string) $json['data']['learner_number']);
        self::assertSame('2020-03-14', $json['data']['birth_date']);
        self::assertSame('SYN-ACTE-1', $json['data']['birth_act_ref']);
        self::assertSame('e1', $json['data']['schooling'][0]['establishment_id']);
        self::assertNull($json['data']['last_report']);
        self::assertSame(403, $this->code('GET', '/public/transfers/' . $transfer['id'] . '/dossier', 'dir1', 't1', 'e1'), 'L\'origine n\'est pas l\'école d\'accueil.');
        self::assertArrayNotHasKey('situations', $json['data']);
    }

    public function testLEcoleDOrigineGardeLaFicheEnLectureSeule(): void
    {
        $transfer = $this->request();
        $this->api('POST', '/public/transfers/' . $transfer['id'] . '/accept', 'dir2', 't1', 'e2', ['arrival_on' => '2026-11-02', 'class_id' => $this->classHost]);

        [$read, $json] = $this->api('GET', '/public/pupils/' . $this->pupil, 'dir1', 't1', 'e1');
        self::assertSame(200, $read->getStatusCode());
        self::assertTrue($json['data']['read_only']);
        self::assertSame(409, $this->code('PATCH', '/public/pupils/' . $this->pupil, 'dir1', 't1', 'e1', ['home_language' => 'fr']));
        self::assertSame(200, $this->code('PATCH', '/public/pupils/' . $this->pupil, 'dir2', 't1', 'e2', ['home_language' => 'fr']));
        self::assertSame(422, $this->code('POST', '/public/transfers', 'dir1', 't1', 'e1', ['person_id' => $this->pupil, 'to_establishment_id' => 'e2']), 'Demande refusée : l\'élève n\'est plus inscrit à l\'origine.');
    }

    public function testDemandesInvalides(): void
    {
        $post = fn (array $body, string $user = 'dir1', string $establishment = 'e1'): int => $this->code('POST', '/public/transfers', $user, 't1', $establishment, $body);

        self::assertSame(422, $post(['person_id' => $this->pupil, 'to_establishment_id' => 'e1']), 'Même école.');
        self::assertSame(422, $post(['person_id' => $this->pupil, 'to_establishment_id' => 'e3']), 'École d\'accueil fermée.');
        self::assertSame(422, $post(['person_id' => $this->pupil, 'to_establishment_id' => 'inconnue']));
        self::assertSame(422, $post(['person_id' => 'inconnu', 'to_establishment_id' => 'e2']));
        self::assertSame(422, $post(['to_establishment_id' => 'e2']));
        self::assertSame(422, $post(['person_id' => $this->pupil, 'to_establishment_id' => 'e2'], 'dir2', 'e2'), 'L\'élève n\'est pas inscrit dans l\'école qui demande.');
        $this->request();
        self::assertSame(409, $post(['person_id' => $this->pupil, 'to_establishment_id' => 'e2']), 'Un seul transfert en cours.');
    }

    public function testRefusMotive(): void
    {
        $transfer = $this->request();

        self::assertSame(422, $this->code('POST', '/public/transfers/' . $transfer['id'] . '/refuse', 'dir2', 't1', 'e2', []));
        self::assertSame(403, $this->code('POST', '/public/transfers/' . $transfer['id'] . '/refuse', 'dir1', 't1', 'e1', ['reason' => 'Non']), 'Seul l\'accueil décide.');
        [$refused, $json] = $this->api('POST', '/public/transfers/' . $transfer['id'] . '/refuse', 'dir2', 't1', 'e2', ['reason' => 'Effectif complet']);
        self::assertSame(200, $refused->getStatusCode());
        self::assertSame(['refused', 'Effectif complet'], [$json['data']['state'], $json['data']['refusal_reason']]);
        self::assertSame([['e1', 'conditional', null]], $this->enrollments());
        self::assertSame(422, $this->code('POST', '/public/transfers/' . $transfer['id'] . '/accept', 'dir2', 't1', 'e2', ['arrival_on' => '2026-11-02']), 'Plus à l\'état « requested ».');
        self::assertSame('refused', $this->kernel->container()->get(PupilTransfer::class)->stateOf('t1', $transfer['id']));
        $this->request();
    }

    public function testIsolationEntreEcolesClientsEtDroits(): void
    {
        $transfer = $this->request();
        $this->teacher('maitre', 't1', 'e1', $this->classOrigin, 'public_schools.pupils.read', 'public_schools.pupils.write');

        self::assertSame(404, $this->code('GET', '/public/transfers/' . $transfer['id'], 'dir3', 't1', 'e3'), 'École tierce.');
        self::assertSame(404, $this->code('GET', '/public/transfers/' . $transfer['id'], 'dir9', 't2', 'e9'), 'Autre client.');
        self::assertSame(404, $this->code('POST', '/public/transfers/' . $transfer['id'] . '/accept', 'dir9', 't2', 'e9', ['arrival_on' => '2026-11-02']));
        [, $listOther] = $this->api('GET', '/public/transfers', 'dir9', 't2', 'e9');
        self::assertSame([], $listOther['data']);
        self::assertSame(403, $this->code('POST', '/public/transfers', 'maitre', 't1', 'e1', ['person_id' => $this->pupil, 'to_establishment_id' => 'e2']));
        self::assertSame(404, $this->code('GET', '/public/transfers', 'maitre', 't1', 'e1'));
        self::assertSame(403, $this->code('POST', '/public/transfers/' . $transfer['id'] . '/accept', 'dir1', 't1', 'e1', ['arrival_on' => '2026-11-02']), 'L\'origine ne peut pas accepter à la place de l\'accueil.');
        self::assertSame([['e1', 'conditional', null]], $this->enrollments());
        self::assertNull($this->kernel->container()->get(PupilTransfer::class)->stateOf('t2', $transfer['id']));
    }

    public function testContratPupilTransfer(): void
    {
        $contract = $this->kernel->container()->get(PupilTransfer::class);

        $id = $contract->request('t1', $this->pupil, 'e1', 'e2', 'dir1');
        self::assertSame(PupilTransfer::REQUESTED, $contract->stateOf('t1', $id));
        $contract->accept('t1', $id, '2026-11-02', 'dir2');
        self::assertSame(PupilTransfer::ACCEPTED, $contract->stateOf('t1', $id));
        $this->expectException(\InvalidArgumentException::class);
        $contract->refuse('t1', $id, 'Trop tard', 'dir2');
    }

    public function testEcritureBloqueeQuandLApplicationEstSuspendue(): void
    {
        $transfer = $this->request();
        $this->applications->set('t1', 'public_primary', ApplicationAccess::SUSPENDED);

        self::assertSame(423, $this->code('POST', '/public/transfers/' . $transfer['id'] . '/accept', 'dir2', 't1', 'e2', ['arrival_on' => '2026-11-02']));
        self::assertSame(200, $this->code('GET', '/public/transfers/' . $transfer['id'], 'dir2', 't1', 'e2'));
    }
}

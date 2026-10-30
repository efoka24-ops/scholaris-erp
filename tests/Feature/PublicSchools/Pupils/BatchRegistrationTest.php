<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Pupils;

/** T631, EX-ELV-07 : inscription groupée depuis un registre papier (photo jointe), lignes indépendantes, idempotence du lot. Données SYN-*. */
final class BatchRegistrationTest extends PublicSchoolsTestCase
{
    private string $class1 = '';

    protected function setUp(): void
    {
        $this->bootPublic();
        $this->seedSchool('t1', 'e1', 'SYN-E1', 'open');
        $this->director('dir1', 't1', 'e1', 'public_schools.pupils.read', 'public_schools.pupils.write');
        $this->class1 = $this->createClass('t1', 'e1', 'SIL A SYN', 'y2026', 'SIL');
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function row(string $last, string $first, array $changes = []): array
    {
        return $changes + ['last_name' => $last, 'first_name' => $first, 'birth_date' => '2020-05-02', 'sex' => 'M', 'guardians' => [['kind' => 'parent', 'identity' => ['first_name' => 'Parent', 'last_name' => $last, 'phone' => '677000222']]]];
    }

    public function testLeRegistreEstSaisiLigneParLigneSansQuUneErreurBloqueLesAutres(): void
    {
        $body = [
            'client_uuid' => 'syn-registre-2026-01', 'register_photo_ref' => 'media:SYN-PHOTO-REGISTRE-1', 'register_label' => 'Registre matricule SYN p. 12', 'class_id' => $this->class1,
            'rows' => [$this->row('SYN-Atangana', 'Luc'), $this->row('SYN-Bello', 'Aïcha', ['sex' => 'X']), $this->row('SYN-Ndongo', 'Marc', ['apee_paid' => true])],
        ];
        [$response, $json] = $this->api('POST', '/public/pupils/batches', 'dir1', 't1', 'e1', $body);
        [$replay, $replayJson] = $this->api('POST', '/public/pupils/batches', 'dir1', 't1', 'e1', $body);
        [$list, $listJson] = $this->api('GET', '/public/pupils/batches', 'dir1', 't1', 'e1');

        self::assertSame(201, $response->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));
        self::assertSame(1, $json['data']['created_count']);
        self::assertSame(['created', 'rejected', 'rejected'], array_column($json['data']['results'], 'status'));
        self::assertArrayHasKey('apee_paid', $json['data']['results'][2]['errors'], 'TST-01 : aucun champ APEE, même en lot.');
        self::assertSame('media:SYN-PHOTO-REGISTRE-1', $json['data']['register_photo_ref']);
        self::assertSame(200, $replay->getStatusCode());
        self::assertTrue($replayJson['data']['replayed']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM pub_pupil_registrations WHERE tenant_id = ?', ['t1']));
        self::assertSame(200, $list->getStatusCode());
        self::assertCount(1, $listJson['data']);
    }

    public function testValidationDuLotEtDroits(): void
    {
        self::assertSame(422, $this->code('POST', '/public/pupils/batches', 'dir1', 't1', 'e1', ['rows' => []]));
        self::assertContains($this->code('POST', '/public/pupils/batches', 'outsider', 't1', 'e1', ['client_uuid' => 'x', 'rows' => [$this->row('SYN-A', 'B')]]), [401, 403, 404]);
    }
}

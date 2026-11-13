<?php

declare(strict_types=1);

namespace Tests\Feature\Organisation;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationData;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationRegistry;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGate;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGuards;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureDraft;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter;
use Tests\Support\Organisation\OrganisationTestCase;
use Tests\Support\Organisation\RecordingEnrolmentGuard;

/** D2 (registre des autorisations : niveaux couverts, pièce, validité) et D3 (gardes-fous d'inscription enregistrables). */
final class AuthorizationRegistryTest extends OrganisationTestCase
{
    private RecordingEnrolmentGuard $guard;
    private string $track = '';
    private string $l1 = '';
    private string $l2 = '';
    private string $group = '';

    protected function setUp(): void
    {
        $this->guard = new RecordingEnrolmentGuard();
        $this->bootOrganisation(['organisation.enrolment.guards.test' => [$this->guard]]);
        $this->seedEstablishment('t1', 'e1', 'Institut', 'higher', 'alice');
        $this->seedEstablishment('t2', 'x1', 'Autre client', 'higher', 'bob');
        $this->grantAll('alice');
        $w = $this->kernel->container()->get(StructureWriter::class);
        $this->track = $w->createStructure('t1', 'e1', new StructureDraft('track', 'Comptabilité et finance'));
        $this->l1 = $w->createStructure('t1', 'e1', new StructureDraft('level', 'Licence 1', null, $this->track));
        $this->l2 = $w->createStructure('t1', 'e1', new StructureDraft('level', 'Licence 2', null, $this->track));
        $this->group = $w->createStructure('t1', 'e1', new StructureDraft('group', 'Groupe A', null, $this->l1));
    }

    private function registry(): AuthorizationRegistry
    {
        return $this->kernel->container()->get(AuthorizationRegistry::class);
    }

    private static function on(string $day): DateTimeImmutable
    {
        return new DateTimeImmutable($day . ' 12:00:00', new DateTimeZone('Africa/Douala'));
    }

    public function testCrudEtNiveauxCouverts(): void
    {
        $id = $this->registry()->register('t1', 'e1', new AuthorizationData($this->track, 'ARR-2026-001', '2026-01-01', '2028-12-31', 'MINESUP', [$this->l1], 'DOC-0001'), 'alice');

        $info = $this->registry()->find('t1', 'e1', $id);
        self::assertSame([[$this->l1], 'DOC-0001', 'MINESUP'], [$info?->coveredLevels, $info->documentId, $info->authority]);
        self::assertCount(1, $this->registry()->forStructure('t1', 'e1', $this->track));

        $gate = $this->kernel->container()->get(EnrolmentGate::class);
        self::assertTrue($gate->canEnrol('t1', 'e1', $this->group, self::on('2026-10-08')), 'Groupe de L1 couvert par l\'autorisation de la filière.');
        self::assertTrue($this->registry()->covers('t1', 'e1', $this->track, $this->l1, self::on('2026-10-08')));
        self::assertFalse($this->registry()->covers('t1', 'e1', $this->l2, null, self::on('2026-10-08')), 'L2 non couvert.');
        self::assertFalse($this->registry()->covers('t1', 'e1', $this->group, null, self::on('2029-01-01')), 'Expirée.');

        $this->registry()->update('t1', 'e1', $id, new AuthorizationData($this->track, 'ARR-2026-001', '2026-01-01', '2028-12-31', 'MINESUP', null), 'alice');
        self::assertTrue($this->registry()->covers('t1', 'e1', $this->l2, null, self::on('2026-10-08')), 'Sans restriction : tous les niveaux.');

        $this->registry()->remove('t1', 'e1', $id, 'alice');
        self::assertNull($this->registry()->find('t1', 'e1', $id));
        self::assertFalse($gate->canEnrol('t1', 'e1', $this->group, self::on('2026-10-08')));
    }

    public function testValidationEtIsolation(): void
    {
        $bad = [
            new AuthorizationData($this->track, 'ARR', '2026-12-31', '2026-01-01'),
            new AuthorizationData($this->track, 'ARR', '2026-02-30', '2026-12-31'),
            new AuthorizationData($this->track, 'ARR', '2026-01-01', '2026-12-31', null, [$this->group]),
            new AuthorizationData('inconnue', 'ARR', '2026-01-01', '2026-12-31'),
        ];
        foreach ($bad as $data) {
            try {
                $this->registry()->register('t1', 'e1', $data);
                self::fail('Refus attendu.');
            } catch (HttpException $e) {
                self::assertSame(422, $e->status);
            }
        }
        $id = $this->registry()->register('t1', 'e1', new AuthorizationData($this->track, 'ARR', '2026-01-01', '2026-12-31'));
        self::assertNull($this->registry()->find('t2', 'x1', $id));
        self::assertSame([], $this->registry()->forStructure('t2', 'e1', $this->track));
        self::assertFalse($this->registry()->covers('t2', 'e1', $this->track, null, self::on('2026-10-08')));
        $this->expectException(HttpException::class);
        $this->registry()->remove('t2', 'x1', $id);
    }

    public function testNiveauxEtPieceParLApi(): void
    {
        [$response, $body] = $this->api('POST', '/authorizations', 'alice', 't1', 'e1', [
            'structure_id' => $this->track, 'reference' => 'ARR-9', 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31',
            'covered_levels' => [$this->l1], 'document_id' => 'DOC-9',
        ]);
        self::assertSame(201, $response->getStatusCode(), json_encode($body, JSON_THROW_ON_ERROR));
        self::assertSame([[$this->l1], 'DOC-9'], [$body['data']['covered_levels'], $body['data']['document_id']]);
        self::assertSame(422, $this->code('POST', '/authorizations', 'alice', 't1', 'e1', [
            'structure_id' => $this->track, 'reference' => 'ARR-10', 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'covered_levels' => [$this->track],
        ]), 'Un niveau couvert doit être une structure de niveau.');

        [, $classic] = $this->api('POST', '/authorizations', 'alice', 't1', 'e1', ['structure_id' => $this->l2, 'reference' => 'ARR-11', 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31']);
        self::assertNull($classic['data']['covered_levels'], 'Sans niveaux : comportement historique.');
    }

    public function testLesGardesRecoiventNiveauFiliereEtAnneeEtPeuventRefuser(): void
    {
        $guards = $this->kernel->container()->get(EnrolmentGuards::class);

        self::assertNull($guards->refusal('t1', 'e1', $this->group, 'y2026', 'p1', self::on('2026-10-08'), 'en1'));
        $request = $this->guard->seen[0];
        self::assertSame(['higher', $this->l1, $this->track, 'y2026', 'p1', 'en1'], [$request->establishmentType, $request->levelId, $request->trackId, $request->academicYearId, $request->personId, $request->enrollmentId]);

        $this->guard->reason = 'Aucune tutelle pour 2026-2027.';
        self::assertSame('Aucune tutelle pour 2026-2027.', $guards->refusal('t1', 'e1', $this->group, 'y2026', 'p1', self::on('2026-10-08')));
        self::assertNotNull($guards->refusal('t2', 'e1', $this->group, 'y2026', 'p1', self::on('2026-10-08')), 'Autre client : structure introuvable.');
    }
}

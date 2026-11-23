<?php

declare(strict_types=1);

namespace Tests\Feature\Organisation;

use DateTimeImmutable;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGate;
use Tests\Support\Organisation\OrganisationTestCase;

/** Une structure sans autorisation valide ne peut pas recevoir d'inscriptions ; le registre alerte 30 jours avant l'expiration. */
final class EnrolmentGateTest extends OrganisationTestCase
{
    private string $structure = '';

    protected function setUp(): void
    {
        $this->bootOrganisation();
        $this->seedEstablishment('t1', 'e1', 'Institut', 'higher', 'alice');
        $this->seedEstablishment('t1', 'e2', 'Autre institut', 'higher', 'alice');
        $this->seedEstablishment('t2', 'e3', 'Étranger', 'higher', 'bob');
        $this->grantAll('alice');
        $this->grantAll('bob');
        $this->structure = $this->api('POST', '/structures', 'alice', 't1', 'e1', ['kind' => 'track', 'name' => 'Génie logiciel', 'capacity' => 60])[1]['data']['id'];
    }

    /** @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>} */
    private function authorize(string $from, string $to, string $reference = 'ARR-001'): array
    {
        return $this->api('POST', '/authorizations', 'alice', 't1', 'e1', [
            'structure_id' => $this->structure, 'reference' => $reference, 'authority' => 'MINESUP', 'valid_from' => $from, 'valid_to' => $to,
        ]);
    }

    private function gate(): EnrolmentGate
    {
        return $this->kernel->container()->get(EnrolmentGate::class);
    }

    public function testInscriptionRefuseSansAutorisationValide(): void
    {
        $on = new DateTimeImmutable('2026-10-08 10:00:00');
        self::assertFalse($this->gate()->canEnrol('t1', 'e1', $this->structure, $on), 'Aucune autorisation.');

        $this->authorize('2025-01-01', '2026-06-30');
        self::assertFalse($this->gate()->canEnrol('t1', 'e1', $this->structure, $on), 'Autorisation expirée.');

        $this->authorize('2027-01-01', '2028-12-31', 'ARR-002');
        self::assertFalse($this->gate()->canEnrol('t1', 'e1', $this->structure, $on), 'Autorisation pas encore valide.');
    }

    public function testInscriptionAccepteeAvecAutorisationValide(): void
    {
        $this->authorize('2026-01-01', '2026-12-31');

        self::assertTrue($this->gate()->canEnrol('t1', 'e1', $this->structure, new DateTimeImmutable('2026-10-08')));
        self::assertTrue($this->gate()->canEnrol('t1', 'e1', $this->structure, new DateTimeImmutable('2026-12-31 12:00:00', new \DateTimeZone('Africa/Douala'))), 'Le dernier jour est inclus.');
        self::assertFalse($this->gate()->canEnrol('t1', 'e1', $this->structure, new DateTimeImmutable('2027-01-01 12:00:00', new \DateTimeZone('Africa/Douala'))));
    }

    public function testLaBarriereNeCrossePasLesClientsNiLesEtablissements(): void
    {
        $this->authorize('2026-01-01', '2026-12-31');
        $on = new DateTimeImmutable('2026-10-08 12:00:00', new \DateTimeZone('Africa/Douala'));

        self::assertFalse($this->gate()->canEnrol('t2', 'e1', $this->structure, $on), 'Autre client.');
        self::assertFalse($this->gate()->canEnrol('t2', 'e3', $this->structure, $on));
        self::assertFalse($this->gate()->canEnrol('t1', 'e2', $this->structure, $on), 'Autre établissement du client.');
        self::assertFalse($this->gate()->canEnrol('t1', 'e1', 'inconnue', $on));
    }

    public function testParApi(): void
    {
        [, $body] = $this->api('GET', '/structures/' . $this->structure . '/enrolment', 'alice', 't1', 'e1');
        self::assertFalse($body['data']['can_enrol']);

        $this->authorize('2026-01-01', '2026-12-31');
        [, $body] = $this->api('GET', '/structures/' . $this->structure . '/enrolment', 'alice', 't1', 'e1');
        self::assertTrue($body['data']['can_enrol']);
        [, $body] = $this->api('GET', '/structures/' . $this->structure . '/enrolment?on=2027-02-01', 'alice', 't1', 'e1');
        self::assertFalse($body['data']['can_enrol']);
        self::assertSame(422, $this->code('GET', '/structures/' . $this->structure . '/enrolment?on=demain', 'alice', 't1', 'e1'));
        self::assertSame(404, $this->code('GET', '/structures/' . $this->structure . '/enrolment', 'alice', 't1', 'e2'));
    }

    public function testRegistreDesAutorisations(): void
    {
        self::assertSame(422, $this->authorize('2026-12-31', '2026-01-01')[0]->getStatusCode());
        $id = $this->authorize('2026-01-01', '2026-12-31')[1]['data']['id'];
        self::assertSame(409, $this->authorize('2026-02-01', '2026-12-31')[0]->getStatusCode(), 'Même référence pour la même structure.');
        self::assertSame(422, $this->code('POST', '/authorizations', 'alice', 't1', 'e1', [
            'structure_id' => 'inconnue', 'reference' => 'X', 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31',
        ]));
        self::assertSame(422, $this->code('PATCH', '/authorizations/' . $id, 'alice', 't1', 'e1', ['structure_id' => 'autre']), 'La structure est définitive.');
        self::assertSame(200, $this->code('PATCH', '/authorizations/' . $id, 'alice', 't1', 'e1', ['valid_to' => '2027-03-31']));
    }

    public function testAlerteDExpirationA30Jours(): void
    {
        $soon = $this->authorize('2026-01-01', '2026-11-07', 'ARR-A')[1]['data']['id'];   // 30 jours
        $this->authorize('2026-01-01', '2026-11-08', 'ARR-B');                            // 31 jours
        $this->authorize('2026-01-01', '2026-10-08', 'ARR-C');                            // aujourd'hui
        $this->authorize('2026-01-01', '2026-10-07', 'ARR-D');                            // déjà expirée

        [, $body] = $this->api('GET', '/authorizations/expiring', 'alice', 't1', 'e1');
        self::assertSame(['ARR-C', 'ARR-A'], array_column($body['data'], 'reference'));
        self::assertSame([0, 30], array_column($body['data'], 'days_left'));
        self::assertSame('Génie logiciel', $body['data'][1]['structure_name']);
        self::assertSame($soon, $body['data'][1]['id']);

        [, $wide] = $this->api('GET', '/authorizations/expiring?days=31', 'alice', 't1', 'e1');
        self::assertCount(3, $wide['data']);
        self::assertSame(422, $this->code('GET', '/authorizations/expiring?days=0', 'alice', 't1', 'e1'));

        [, $other] = $this->api('GET', '/authorizations/expiring', 'alice', 't1', 'e2');
        self::assertSame([], $other['data'], 'Les autorisations d\'un autre établissement n\'apparaissent pas.');
    }
}

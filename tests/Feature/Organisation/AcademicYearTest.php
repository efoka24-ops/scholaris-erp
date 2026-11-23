<?php

declare(strict_types=1);

namespace Tests\Feature\Organisation;

use Tests\Support\Organisation\OrganisationTestCase;

/** L'année scolaire est propre à chaque établissement ; la clôturer n'affecte pas les autres établissements du groupe. */
final class AcademicYearTest extends OrganisationTestCase
{
    private const YEAR = ['label' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'];

    protected function setUp(): void
    {
        $this->bootOrganisation();
        $this->seedEstablishment('t1', 'e1', 'Lycée de Bonanjo', 'secondary', 'alice', 'bob');
        $this->seedEstablishment('t1', 'e2', 'Collège de Bali', 'secondary', 'alice', 'bob');
        $this->seedEstablishment('t1', 'e3', 'Institut', 'higher', 'alice', 'bob');
        $this->grantAll('alice');
        $this->grantAll('bob');
        $this->secondFactor->allow('bob');
    }

    /** @return string identifiant de l'année créée */
    private function createYear(string $establishment, array $body = self::YEAR): string
    {
        [$response, $json] = $this->api('POST', '/academic-years', 'alice', 't1', $establishment, $body);
        self::assertSame(201, $response->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));

        return $json['data']['id'];
    }

    public function testLaClotureNAffecteQueLEtablissementConcerne(): void
    {
        $year1 = $this->createYear('e1');
        $year2 = $this->createYear('e2');

        [$response, $body] = $this->closeYear($year1, 'e1');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('closed', $body['data']['status']);
        self::assertSame('2026-10-08T09:00:00+01:00', $body['data']['closed_at']);

        self::assertSame('open', $this->api('GET', '/academic-years/' . $year2, 'alice', 't1', 'e2')[1]['data']['status']);
        // Fermer l'année d'un établissement en passant par un autre établissement : introuvable, rien n'est modifié.
        self::assertSame(404, $this->code('POST', '/academic-years/' . $year2 . '/close', 'alice', 't1', 'e1'));
        self::assertSame('open', $this->api('GET', '/academic-years/' . $year2, 'alice', 't1', 'e2')[1]['data']['status']);

        self::assertSame(409, $this->code('POST', '/academic-years/' . $year1 . '/close', 'alice', 't1', 'e1'), 'Déjà clôturée.');
        self::assertSame('academic_year.closed', $this->connections()->pdo()->query("SELECT action FROM audit_log WHERE action LIKE '%closed'")->fetchColumn());
    }

    public function testUneAnneeClotureeNeSeModifiePlus(): void
    {
        $year = $this->createYear('e1');
        $this->closeYear($year, 'e1');

        self::assertSame(409, $this->code('PATCH', '/academic-years/' . $year, 'alice', 't1', 'e1', ['label' => '2026/27']));
    }

    public function testChaqueEtablissementPeutAvoirLaMemeAnnee(): void
    {
        $this->createYear('e1');
        $this->createYear('e2');

        [, $list] = $this->api('GET', '/academic-years', 'alice', 't1', 'e1');
        self::assertCount(1, $list['data']);
        self::assertSame('e1', $list['data'][0]['establishment_id']);
    }

    public function testChevauchementEtDatesIncoherentesRefuses(): void
    {
        $this->createYear('e1');

        self::assertSame(422, $this->code('POST', '/academic-years', 'alice', 't1', 'e1', ['label' => 'Chevauche', 'starts_on' => '2027-06-01', 'ends_on' => '2027-12-31']));
        self::assertSame(422, $this->code('POST', '/academic-years', 'alice', 't1', 'e1', ['label' => 'Inversée', 'starts_on' => '2028-09-01', 'ends_on' => '2028-06-30']));
        self::assertSame(201, $this->code('POST', '/academic-years', 'alice', 't1', 'e1', ['label' => '2027-2028', 'starts_on' => '2027-09-01', 'ends_on' => '2028-06-30']));
        self::assertSame(409, $this->code('POST', '/academic-years', 'alice', 't1', 'e1', ['label' => '2027-2028', 'starts_on' => '2030-09-01', 'ends_on' => '2031-06-30']), 'Libellé déjà employé.');
    }

    public function testLesPeriodesSuiventLeTypeDEtablissementEtLAnnee(): void
    {
        $year = $this->createYear('e1');
        $higherYear = $this->createYear('e3');
        $period = ['academic_year_id' => $year, 'kind' => 'trimester', 'ordinal' => 1, 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-18'];

        [$response, $body] = $this->api('POST', '/periods', 'alice', 't1', 'e1', $period);
        self::assertSame(201, $response->getStatusCode());
        $periodId = $body['data']['id'];

        self::assertSame(409, $this->code('POST', '/periods', 'alice', 't1', 'e1', $period), 'Même rang en double.');
        self::assertSame(422, $this->code('POST', '/periods', 'alice', 't1', 'e1', ['kind' => 'semester', 'ordinal' => 2] + $period), 'Semestre interdit hors supérieur.');
        self::assertSame(422, $this->code('POST', '/periods', 'alice', 't1', 'e3', ['academic_year_id' => $higherYear, 'kind' => 'trimester'] + $period), 'Le supérieur n\'emploie que des semestres.');
        self::assertSame(201, $this->code('POST', '/periods', 'alice', 't1', 'e3', ['academic_year_id' => $higherYear, 'kind' => 'semester'] + $period));
        self::assertSame(422, $this->code('POST', '/periods', 'alice', 't1', 'e1', ['ordinal' => 2, 'ends_on' => '2027-07-30'] + $period), 'Hors de l\'année.');
        self::assertSame(422, $this->code('POST', '/periods', 'alice', 't1', 'e2', $period), 'L\'année d\'un autre établissement est introuvable.');

        self::assertSame(422, $this->code('PATCH', '/periods/' . $periodId, 'alice', 't1', 'e1', ['academic_year_id' => $higherYear]), 'Une période ne change pas d\'année.');
        self::assertSame(200, $this->code('PATCH', '/periods/' . $periodId, 'alice', 't1', 'e1', ['ends_on' => '2026-12-20']));

        [, $list] = $this->api('GET', '/periods?filter[academic_year_id]=' . $year, 'alice', 't1', 'e1');
        self::assertCount(1, $list['data']);

        $this->closeYear($year, 'e1');
        self::assertSame(409, $this->code('PATCH', '/periods/' . $periodId, 'alice', 't1', 'e1', ['ends_on' => '2026-12-21']), 'Année clôturée.');
    }
}

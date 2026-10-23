<?php

declare(strict_types=1);

namespace Tests\Feature\Organisation;

use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodInfo;
use Tests\Support\Organisation\OrganisationTestCase;

/** T800 / T801 : hiérarchie année > trimestre > séquence ; une seule période « année » par année scolaire, refusée au supérieur. */
final class PeriodHierarchyTest extends OrganisationTestCase
{
    private string $schoolYear;

    protected function setUp(): void
    {
        $this->bootOrganisation();
        $this->seedEstablishment('t1', 'e1', 'Lycée de Bonanjo', 'secondary', 'alice');
        $this->seedEstablishment('t1', 'e3', 'Institut', 'higher', 'alice');
        $this->grantAll('alice');
        $this->schoolYear = $this->createYear('e1');
    }

    private function createYear(string $establishment, string $label = '2026-2027', string $from = '2026-09-01', string $to = '2027-06-30'): string
    {
        [$response, $json] = $this->api('POST', '/academic-years', 'alice', 't1', $establishment, ['label' => $label, 'starts_on' => $from, 'ends_on' => $to]);
        self::assertSame(201, $response->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));

        return $json['data']['id'];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function period(string $kind, int $ordinal, string $from, string $to, ?string $parent = null, ?string $year = null, string $establishment = 'e1'): array
    {
        $body = ['academic_year_id' => $year ?? $this->schoolYear, 'kind' => $kind, 'ordinal' => $ordinal, 'starts_on' => $from, 'ends_on' => $to];
        if ($parent !== null) {
            $body['parent_id'] = $parent;
        }
        [$response, $json] = $this->api('POST', '/periods', 'alice', 't1', $establishment, $body);

        return [$response->getStatusCode(), $json];
    }

    private function created(string $kind, int $ordinal, string $from, string $to, ?string $parent = null): string
    {
        [$status, $json] = $this->period($kind, $ordinal, $from, $to, $parent);
        self::assertSame(201, $status, json_encode($json, JSON_THROW_ON_ERROR));

        return $json['data']['id'];
    }

    public function testUneSeulePeriodeAnneeParAnneeScolaire(): void
    {
        $year = $this->created('year', 1, '2026-09-01', '2027-06-30');

        [$status, $json] = $this->period('year', 2, '2026-09-01', '2027-06-30');
        self::assertSame(422, $status, 'Deuxième période « année » refusée, même avec un autre rang.');
        self::assertStringContainsString('Une seule période « année »', $json['errors']['kind'][0]);
        self::assertSame(422, $this->period('year', 1, '2026-09-01', '2027-06-30')[0], 'Même rang : refus de validation avant la contrainte d\'unicité.');
        self::assertSame(200, $this->code('PATCH', '/periods/' . $year, 'alice', 't1', 'e1', ['ends_on' => '2027-06-29']), 'Modifier l\'unique période « année » reste permis.');

        $next = $this->createYear('e1', '2027-2028', '2027-09-01', '2028-06-30');
        self::assertSame(201, $this->period('year', 1, '2027-09-01', '2028-06-30', null, $next)[0], 'Une période « année » par année scolaire.');
    }

    public function testLeSuperieurRefuseLaPeriodeAnnee(): void
    {
        $higherYear = $this->createYear('e3');

        [$status, $json] = $this->period('year', 1, '2026-09-01', '2027-06-30', null, $higherYear, 'e3');
        self::assertSame(422, $status);
        self::assertStringContainsString('semestres', $json['errors']['kind'][0]);
    }

    public function testLeTrimestreSeRattacheALAnneeEtResteDansSesDates(): void
    {
        $year = $this->created('year', 1, '2026-09-01', '2027-06-30');
        $t1 = $this->created('trimester', 1, '2026-09-01', '2026-12-18', $year);
        $this->created('trimester', 2, '2027-01-04', '2027-03-26');

        self::assertSame(422, $this->period('trimester', 3, '2027-04-01', '2027-06-30', $t1)[0], 'Un trimestre ne dépend pas d\'un trimestre.');
        self::assertSame(422, $this->period('year', 1, '2026-09-01', '2027-06-30', $year)[0], 'L\'année n\'a pas de parent.');
        $sequence = $this->created('sequence', 1, '2026-09-01', '2026-10-16', $t1);
        self::assertSame(422, $this->period('trimester', 3, '2027-04-01', '2027-06-30', $sequence)[0], 'Un trimestre ne dépend pas d\'une séquence.');

        $other = $this->createYear('e1', '2027-2028', '2027-09-01', '2028-06-30');
        self::assertSame(422, $this->period('trimester', 1, '2027-09-01', '2027-12-17', $year, $other)[0], 'Parent d\'une autre année scolaire.');
    }

    public function testSequenceHorsDesDatesDuTrimestreRefusee(): void
    {
        $t1 = $this->created('trimester', 1, '2026-09-01', '2026-12-18');

        self::assertSame(422, $this->period('sequence', 1, '2026-09-01', '2026-12-31', $t1)[0], 'Fin après le trimestre.');
        self::assertSame(422, $this->period('sequence', 1, '2026-08-25', '2026-10-16', $t1)[0], 'Début avant le trimestre (et hors année).');
        self::assertSame(422, $this->period('sequence', 1, '2026-09-01', '2026-10-16')[0], 'Séquence sans trimestre.');

        $other = $this->createYear('e1', '2027-2028', '2027-09-01', '2028-06-30');
        self::assertSame(422, $this->period('sequence', 1, '2027-09-01', '2027-10-15', $t1, $other)[0], 'Trimestre d\'une autre année scolaire.');

        $s1 = $this->created('sequence', 1, '2026-09-01', '2026-10-16', $t1);
        self::assertSame(422, $this->code('PATCH', '/periods/' . $s1, 'alice', 't1', 'e1', ['ends_on' => '2026-12-20']), 'Modifier la séquence hors du trimestre.');
        self::assertSame(422, $this->code('PATCH', '/periods/' . $t1, 'alice', 't1', 'e1', ['starts_on' => '2026-09-15']), 'Réduire le trimestre hors de sa séquence.');
        self::assertSame(422, $this->code('PATCH', '/periods/' . $t1, 'alice', 't1', 'e1', ['kind' => 'year']), 'Un parent ne change pas de nature.');
        self::assertSame(200, $this->code('PATCH', '/periods/' . $t1, 'alice', 't1', 'e1', ['ends_on' => '2026-12-19']));
    }

    public function testAllAtRendSequenceTrimestreEtAnnee(): void
    {
        $year = $this->created('year', 1, '2026-09-01', '2027-06-30');
        $t1 = $this->created('trimester', 1, '2026-09-01', '2026-12-18', $year);
        $s1 = $this->created('sequence', 1, '2026-09-01', '2026-10-16', $t1);
        $s2 = $this->created('sequence', 2, '2026-10-19', '2026-12-18', $t1);

        $periods = $this->kernel->container()->get(CurrentPeriod::class);
        $all = $periods->allAt('t1', 'e1', '2026-09-10');
        self::assertSame([$year, $t1, $s1], array_map(static fn (PeriodInfo $p): string => $p->id, $all));
        self::assertSame([null, $year, $t1], array_map(static fn (PeriodInfo $p): ?string => $p->parentId, $all));
        self::assertSame(['year', 'trimester', 'sequence'], array_map(static fn (PeriodInfo $p): string => $p->kind, $all));
        self::assertSame([$year, $t1, $s2], array_map(static fn (PeriodInfo $p): string => $p->id, $periods->allAt('t1', 'e1', '2026-11-02')));

        // at() garde le trimestre ; pendant les vacances, l'année seule ne devient pas « période en cours ».
        self::assertSame($t1, $periods->at('t1', 'e1', '2026-09-10')?->id);
        self::assertNull($periods->at('t1', 'e1', '2026-12-28'));
        self::assertSame([$year], array_map(static fn (PeriodInfo $p): string => $p->id, $periods->allAt('t1', 'e1', '2026-12-28')));
    }
}

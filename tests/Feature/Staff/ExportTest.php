<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use Tests\Support\Exports\FetchesCsv;
use Tests\Support\Exports\ReadsXlsx;
use Tests\Support\Staff\StaffTestCase;

/** Exports CSV du personnel et des heures réalisées : filtres, visibilité identique à la liste, isolation. */
final class ExportTest extends StaffTestCase
{
    use FetchesCsv;
    use ReadsXlsx;

    /** @var array<string, mixed> */
    private array $vac = [];

    protected function setUp(): void
    {
        $this->bootStaff();
        $this->vac = $this->staff('p-prof', 'vacataire', ['category' => 'charge']);
        $other = $this->staff('p-prof2', 'permanent');
        $this->api('PUT', '/vacation-rates', body: ['category' => 'charge', 'hourly_rate' => 6000]);
        $this->activeContract($this->vac['id']);
        $this->declaredHour($this->vac['id'], 'prof', '2026-10-05', 120, 'td');
        $this->declaredHour($other['id'], 'prof2', '2026-10-06', 60, 'cm');
    }

    /** @return array<string, string> */
    private function as(string $user, string $tenant = 't1', string $establishment = 'e1'): array
    {
        return ['Authorization' => 'Bearer ' . $user . ':' . $tenant, 'X-Establishment-Id' => $establishment];
    }

    public function testExportDuPersonnelAvecFiltre(): void
    {
        $all = $this->csvRows('/staff/export', $this->as('admin'));
        self::assertCount(3, $all);
        self::assertSame(['Identifiant', 'Personne', 'Prénom', 'Nom'], array_slice($all[0], 0, 4));
        self::assertContains('Mbarga', array_column($all, 3));

        $vacataires = $this->csvRows('/staff/export?filter[kind]=vacataire', $this->as('admin'));
        self::assertCount(2, $vacataires);
        self::assertSame('Mbarga', $vacataires[1][3]);
        self::assertSame(422, $this->fetch('/staff/export?filter[x]=1', $this->as('admin'))->getStatusCode());
    }

    public function testUnEnseignantNExporteQueSaPropreFiche(): void
    {
        $own = $this->csvRows('/staff/export', $this->as('prof'));
        self::assertCount(2, $own);
        self::assertSame('Mbarga', $own[1][3]);
    }

    public function testExportDesHeuresSelonLaVisibilite(): void
    {
        $all = $this->csvRows('/vacation-hours/export', $this->as('boss'));
        self::assertCount(3, $all);
        self::assertSame(['Identifiant', 'Personnel', 'Date', 'Minutes'], array_slice($all[0], 0, 4));

        $filtered = $this->csvRows('/vacation-hours/export?filter[staff_id]=' . $this->vac['id'], $this->as('boss'));
        self::assertCount(2, $filtered);
        self::assertSame('120', $filtered[1][3]);

        $own = $this->csvRows('/vacation-hours/export', $this->as('prof'));
        self::assertCount(2, $own, 'L\'enseignant ne voit que ses heures.');
        self::assertSame($this->vac['id'], $own[1][1]);
    }

    public function testExportDesContratsDeVacation(): void
    {
        $all = $this->csvRows('/vacation-contracts/export', $this->as('boss'));
        self::assertCount(2, $all);
        self::assertSame(['Identifiant', 'Personnel', 'Référence'], array_slice($all[0], 0, 3));
        self::assertSame($this->vac['id'], $all[1][1]);

        self::assertCount(2, $this->csvRows('/vacation-contracts/export?filter[staff_id]=' . $this->vac['id'], $this->as('boss')));
        self::assertCount(1, $this->csvRows('/vacation-contracts/export?filter[staff_id]=inconnu', $this->as('boss')));
        self::assertSame(422, $this->fetch('/vacation-contracts/export?filter[x]=1', $this->as('boss'))->getStatusCode());
        self::assertCount(2, $this->csvRows('/vacation-contracts/export', $this->as('prof')), 'L\'enseignant voit son contrat.');
        self::assertCount(1, $this->csvRows('/vacation-contracts/export', $this->as('prof2')), 'Un autre enseignant ne voit rien.');
        self::assertSame(403, $this->fetch('/vacation-contracts/export', $this->as('intrus'))->getStatusCode());
    }

    public function testExportDesRelevesDeVacations(): void
    {
        $this->validatedHour($this->vac['id'], 'prof', '2026-10-07', 90, 'td');
        self::assertSame(201, $this->code('POST', '/vacation-statements', body: ['staff_id' => $this->vac['id'], 'period' => '2026-10']));

        $all = $this->csvRows('/vacation-statements/export', $this->as('boss'));
        self::assertCount(2, $all);
        self::assertSame('2026-10', $all[1][3]);
        self::assertCount(1, $this->csvRows('/vacation-statements/export?filter[period]=2026-09', $this->as('boss')));
        self::assertCount(2, $this->csvRows('/vacation-statements/export', $this->as('prof')));
        self::assertCount(1, $this->csvRows('/vacation-statements/export', $this->as('prof2')));
    }

    public function testExportDesConges(): void
    {
        $this->permissions->grant('prof', 'staff.leave.request');
        $this->permissions->grant('boss', 'staff.leave.approve');
        [$response] = $this->api('POST', '/leave-requests', 'prof', body: ['leave_type' => 'annual', 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-16', 'reason' => 'Repos']);
        self::assertSame(201, $response->getStatusCode());

        $all = $this->csvRows('/leave-requests/export', $this->as('boss'));
        self::assertCount(2, $all);
        self::assertSame('annual', $all[1][2]);
        self::assertCount(1, $this->csvRows('/leave-requests/export?filter[leave_type]=sick', $this->as('boss')));
        self::assertCount(2, $this->csvRows('/leave-requests/export', $this->as('prof')), 'Le demandeur voit sa demande.');
        self::assertCount(1, $this->csvRows('/leave-requests/export', $this->as('prof2')));
        self::assertSame(422, $this->fetch('/leave-requests/export?filter[x]=1', $this->as('boss'))->getStatusCode());
    }

    public function testFormatExcelSurTousLesExportsDuModule(): void
    {
        $this->permissions->grant('boss', 'staff.leave.approve');
        foreach (['/staff/export' => 3, '/vacation-hours/export' => 3, '/vacation-contracts/export' => 2, '/leave-requests/export' => 1, '/vacation-statements/export' => 1] as $path => $count) {
            $rows = $this->xlsxRows($path . '?format=xlsx', $this->as('admin'));
            self::assertCount($count, $rows, $path);
        }
        self::assertSame('Mbarga', $this->xlsxRows('/staff/export?format=xlsx&filter[kind]=vacataire', $this->as('admin'))[1][3]);
        self::assertSame(422, $this->fetch('/staff/export?format=pdf', $this->as('admin'))->getStatusCode());
    }

    public function testIsolationEntreEtablissementsEtEntreClients(): void
    {
        $this->access->grant('admin', 'e2');
        $this->access->grant('admin', 'x1');
        foreach (['/staff/export', '/vacation-hours/export', '/vacation-contracts/export', '/vacation-statements/export', '/leave-requests/export'] as $path) {
            self::assertCount(1, $this->csvRows($path, $this->as('admin', 't1', 'e2')), $path . ' : autre établissement');
            self::assertCount(1, $this->csvRows($path, $this->as('admin', 't2', 'x1')), $path . ' : autre client');
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use Tests\Support\Exports\FetchesCsv;
use Tests\Support\Exports\ReadsXlsx;
use Tests\Support\Scheduling\SchedulingTestCase;

/** Exports CSV / Excel des créneaux, du calendrier et des modifications : filtres, droit de lecture, isolation établissement / client. */
final class ExportTest extends SchedulingTestCase
{
    use FetchesCsv;
    use ReadsXlsx;

    /** @var array<string, mixed> */
    private array $first = [];

    protected function setUp(): void
    {
        $this->bootScheduling();
        $this->first = $this->slot();
        $this->slot(['teacher_person_id' => 'p-t2', 'structure_ids' => ['c2'], 'room_id' => 'r2', 'weekday' => 2, 'subject_label' => '=HYPERLINK("x")']);
    }

    /** @return array<string, string> */
    private function as(string $user = 'admin', string $tenant = 't1', string $establishment = 'e1'): array
    {
        return ['Authorization' => 'Bearer ' . $user . ':' . $tenant, 'X-Establishment-Id' => $establishment];
    }

    public function testExportDesCreneauxAvecFiltres(): void
    {
        $all = $this->csvRows('/timetable-slots/export', $this->as());
        self::assertCount(3, $all);
        self::assertSame(['Identifiant', 'Matière', 'Enseignant', 'Salle', 'Classes'], array_slice($all[0], 0, 5));
        self::assertContains('Mathématiques', array_column($all, 1));
        self::assertStringContainsString("'=HYPERLINK", $this->csvText('/timetable-slots/export', $this->as()), 'Formule neutralisée.');

        $filtered = $this->csvRows('/timetable-slots/export?filter[teacher_person_id]=p-t2', $this->as());
        self::assertCount(2, $filtered);
        self::assertSame('p-t2', $filtered[1][2]);
        self::assertSame(422, $this->fetch('/timetable-slots/export?filter[x]=1', $this->as())->getStatusCode());
    }

    public function testLaLectureDeLaListeEstExigee(): void
    {
        $this->access->grant('nobody', 'e1');

        self::assertSame(403, $this->fetch('/timetable-slots/export', $this->as('nobody'))->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function seedCalendarAndChanges(): array
    {
        foreach ([['exam_period', 'Examens du 1er semestre', '2027-01-25', '2027-02-07'], ['holiday', 'Vacances de Noël', '2026-12-20', '2027-01-03']] as [$kind, $title, $from, $to]) {
            [$response] = $this->api('POST', '/calendar-events', body: ['kind' => $kind, 'title' => $title, 'starts_on' => $from, 'ends_on' => $to]);
            self::assertSame(201, $response->getStatusCode());
        }
        $slot = $this->first;
        foreach (['2026-10-12', '2026-10-19'] as $date) {
            [$response] = $this->api('POST', '/timetable-changes', body: ['slot_id' => $slot['id'], 'on_date' => $date, 'kind' => 'cancelled']);
            self::assertSame(201, $response->getStatusCode());
        }

        return $slot;
    }

    public function testExportDuCalendrierAvecFiltres(): void
    {
        $this->seedCalendarAndChanges();
        $all = $this->csvRows('/calendar-events/export', $this->as());
        self::assertCount(3, $all);
        self::assertSame(['Identifiant', 'Nature', 'Titre', 'Début', 'Fin'], array_slice($all[0], 0, 5));
        self::assertContains('Examens du 1er semestre', array_column($all, 2));

        self::assertCount(2, $this->csvRows('/calendar-events/export?filter[kind]=holiday', $this->as()));
        self::assertCount(2, $this->csvRows('/calendar-events/export?filter[from]=2027-01-25&filter[to]=2027-02-07', $this->as()), 'Entrées qui touchent la fenêtre.');
        self::assertSame(422, $this->fetch('/calendar-events/export?filter[x]=1', $this->as())->getStatusCode());
        self::assertSame(403, $this->fetch('/calendar-events/export', $this->as('nobody'))->getStatusCode());
    }

    public function testExportDesModificationsSelonLePerimetre(): void
    {
        $this->seedCalendarAndChanges();
        $all = $this->csvRows('/timetable-changes/export', $this->as());
        self::assertCount(3, $all);
        self::assertSame(['Identifiant', 'Créneau', 'Date', 'Nature'], array_slice($all[0], 0, 4));
        self::assertCount(2, $this->csvRows('/timetable-changes/export?filter[on_date]=2026-10-19', $this->as()));
        self::assertCount(1, $this->csvRows('/timetable-changes/export?filter[kind]=moved', $this->as()));
        self::assertSame(422, $this->fetch('/timetable-changes/export?filter[x]=1', $this->as())->getStatusCode());
        self::assertSame(403, $this->fetch('/timetable-changes/export', $this->as('nobody'))->getStatusCode());
    }

    public function testFormatExcelSurTousLesExportsDuModule(): void
    {
        $this->seedCalendarAndChanges();
        foreach (['/timetable-slots/export' => 3, '/calendar-events/export' => 3, '/timetable-changes/export' => 3] as $path => $count) {
            $rows = $this->xlsxRows($path . '?format=xlsx', $this->as());
            self::assertCount($count, $rows, $path);
        }
        self::assertContains("'=HYPERLINK(\"x\")", array_column($this->xlsxRows('/timetable-slots/export?format=xlsx', $this->as()), 1), 'Formule neutralisée aussi en Excel.');
        self::assertSame(422, $this->fetch('/timetable-slots/export?format=doc', $this->as())->getStatusCode());
    }

    public function testIsolationEntreEtablissementsEtEntreClients(): void
    {
        $this->access->grant('admin', 'e2');
        $this->access->grant('admin', 'x1');

        $this->seedCalendarAndChanges();
        foreach (['/timetable-slots/export', '/calendar-events/export', '/timetable-changes/export'] as $path) {
            self::assertCount(1, $this->csvRows($path, $this->as('admin', 't1', 'e2')), $path . ' : autre établissement.');
            self::assertCount(1, $this->csvRows($path, $this->as('admin', 't2', 'x1')), $path . ' : autre client.');
        }
    }
}

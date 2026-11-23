<?php

declare(strict_types=1);

namespace Tests\Feature\Primary\Dashboards;

use Scholaris\Modules\Apps\Primary\Dashboards\Application\AbsenceAlerts;
use Scholaris\Modules\Apps\Primary\Dashboards\Application\PrimaryTodo;
use Scholaris\Modules\Socle\Assessment\Contracts\ClassResults;
use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Primary\FakeClassResults;
use Tests\Support\Primary\FakePresence;
use Tests\Support\Primary\PrimaryTestCase;
use Tests\Support\Primary\RecordingInvoices;

/** T551, T552, T553, T555 : alertes d'absences (une seule fois), « à traiter », exports de tutelle, services, contrôles de clôture. */
final class PilotageTest extends PrimaryTestCase
{
    private FakePresence $presence;
    private RecordingInvoices $invoices;
    private string $class;

    protected function setUp(): void
    {
        $enrollments = new InMemoryEnrollments();
        $people = new InMemoryPeople();
        $this->presence = new FakePresence();
        $this->invoices = new RecordingInvoices();
        $results = new FakeClassResults();
        $this->bootPrimary([EnrollmentDirectory::class => $enrollments, PersonDirectory::class => $people, DailyPresence::class => $this->presence, InvoiceIssuer::class => $this->invoices, ClassResults::class => $results], '2026-10-21');
        $this->seedYear(self::TA, self::EA);
        $level = $this->makeLevel(self::TA, self::EA, 'CE1', 1);
        $this->ok('POST', '/primary/level-profiles', ['level_structure_id' => $level['level'], 'sort_order' => 1]);
        $this->class = $level['classes'][0];
        foreach (['a1', 'a2', 'a3'] as $id) {
            $people->add(self::TA, $id, 'Prénom', 'Nom' . $id);
            $enrollments->add(self::TA, 'en' . $id, $id, self::EA, $this->class, self::YEAR);
        }
    }

    public function testAlerteDAbsencesRepeteesUneSeuleFoisEtATraiter(): void
    {
        // Lundi 19 et mardi 20 octobre 2026 (matin et après-midi) : 4 demi-journées consécutives pour a1, 1 seule pour a2.
        foreach (['2026-10-19', '2026-10-20'] as $day) {
            foreach (['morning', 'afternoon'] as $part) {
                $this->presence->set(self::TA, self::EA, $day, $this->class, $part, ['a1']);
            }
        }
        $this->presence->set(self::TA, self::EA, '2026-10-20', $this->class, 'afternoon', ['a1', 'a2']);
        $alerts = $this->service(AbsenceAlerts::class);
        self::assertSame(['establishments' => 1, 'alerts' => 1, 'skipped_tenants' => 0], $alerts->run('2026-10-20'));
        self::assertSame(0, $alerts->run('2026-10-20')['alerts'], 'Une seule alerte par série.');
        $open = $this->ok('GET', '/primary/alerts')['items'];
        self::assertSame(['a1', 4], [$open[0]['person_id'], $open[0]['half_days']]);

        $todo = $this->service(PrimaryTodo::class)->forUser(self::TA, self::DIRECTOR);
        self::assertContains('primary.alerts', array_column($todo, 'kind'));
        self::assertSame([], $this->service(PrimaryTodo::class)->forUser(self::TA, 'inconnu'));
        $this->ok('POST', "/primary/alerts/{$open[0]['id']}/resolve", ['note' => 'Parents reçus']);
        self::assertSame([], $this->ok('GET', '/primary/alerts')['items']);

        $this->applications->set(self::TA, ApplicationAccess::SUSPENDED);
        self::assertSame(1, $alerts->run('2026-10-20')['skipped_tenants'], 'Client suspendu sauté.');
    }

    public function testExportsDeTutelleServicesEtControlesDeCloture(): void
    {
        $types = array_column($this->ok('GET', '/primary/tutelage/types')['items'], null, 'key');
        self::assertSame(['primary.enrolment', 'primary.results', 'primary.census', 'primary.exam'], array_keys($types));
        $csv = $this->raw('GET', '/primary/tutelage/exports/primary.enrolment?academic_year_id=' . self::YEAR);
        self::assertSame(200, $csv->getStatusCode());
        self::assertStringContainsString('CE1', (string) $csv->getBody());
        self::assertStringContainsString('60', (string) $csv->getBody(), 'Plafond de départ : 60 élèves par classe (arrêté du 14 août 2026, à confirmer).');
        self::assertSame(200, $this->raw('GET', '/primary/tutelage/exports/primary.results?format=xlsx')->getStatusCode());
        self::assertSame(404, $this->call('GET', '/primary/tutelage/exports/inconnu')[0]);
        $this->assign(self::TA, 'maitre', self::EA, 'primary.tutelage.export', Scope::CLASS_GROUP, $this->class);
        self::assertSame(403, $this->call('GET', '/primary/tutelage/types', null, 'maitre')[0], 'Export réservé à tout l\'établissement.');

        $plan = $this->ok('POST', '/primary/service-plans', ['service_kind' => 'canteen', 'label' => 'Cantine midi', 'fee_code' => 'CANTINE', 'billing_cycle' => 'term', 'capacity' => 2]);
        self::assertSame(422, $this->call('POST', '/primary/service-plans', ['service_kind' => 'piscine', 'label' => 'x', 'fee_code' => 'X', 'billing_cycle' => 'term'])[0]);
        $this->ok('POST', "/primary/service-plans/{$plan['id']}/subscriptions", ['person_id' => 'a1', 'academic_year_id' => self::YEAR, 'starts_on' => '2026-10-01']);
        self::assertSame(409, $this->call('POST', "/primary/service-plans/{$plan['id']}/subscriptions", ['person_id' => 'a1', 'academic_year_id' => self::YEAR, 'starts_on' => '2026-10-01'])[0]);
        $this->ok('POST', "/primary/service-plans/{$plan['id']}/subscriptions", ['person_id' => 'a2', 'academic_year_id' => self::YEAR, 'starts_on' => '2026-10-01']);
        self::assertSame(409, $this->call('POST', "/primary/service-plans/{$plan['id']}/subscriptions", ['person_id' => 'a3', 'academic_year_id' => self::YEAR, 'starts_on' => '2026-10-01'])[0], 'Capacité atteinte.');
        self::assertSame(['invoiced' => 2, 'already' => 0], $this->ok('POST', "/primary/service-plans/{$plan['id']}/invoice", ['cycle_key' => '2026-T1', 'due_on' => '2026-10-31']));
        self::assertSame(['invoiced' => 0, 'already' => 2], $this->ok('POST', "/primary/service-plans/{$plan['id']}/invoice", ['cycle_key' => '2026-T1', 'due_on' => '2026-10-31']), 'Une facture par cycle.');
        self::assertCount(2, $this->invoices->charges);

        $report = $this->ok('GET', '/primary/closure/check?academic_year_id=' . self::YEAR);
        $checks = array_column($report['checks'], null, 'key');
        self::assertSame(['blocking', 3], [$checks['primary.councils']['status'], $checks['primary.councils']['blocking_count']], 'Trois conseils attendus (Q4 : à confirmer).');
        self::assertSame('ok', $checks['primary.report_cards']['status']);
        self::assertFalse($report['ready']);
        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, self::EA, 'primary.closure.require_councils', false, self::DIRECTOR);
        self::assertSame('ok', array_column($this->ok('GET', '/primary/closure/check?academic_year_id=' . self::YEAR)['checks'], null, 'key')['primary.councils']['status']);

        $defaults = array_column($this->ok('GET', '/primary/settings/defaults')['items'], null, 'key');
        self::assertTrue($defaults['primary.report_card.show_rank']['to_confirm']);
        self::assertFalse($defaults['primary.closure.require_councils']['current']);
        self::assertTrue($defaults['primary.closure.require_councils']['customised']);
    }
}

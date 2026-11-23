<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use DI\Container;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Security\Authenticator;
use Scholaris\Core\Security\EstablishmentAccess;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Modules\Socle\Assessment\Contracts\ActorDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\ResultsDirectory;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummaryProvider;
use Scholaris\Modules\Socle\Audit\Contracts\Approvals;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentCatalog;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\ModuleAvailability;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Portals\Contracts\AdultLearnerCheck;
use Scholaris\Modules\Socle\Reporting\Contracts\PendingApprovalLister;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Tests\Support\StubAccess;
use Tests\Support\StubAuthenticator;

/**
 * Base des tests Reporting et Portals : Kernel de test (SQLite en mémoire), horloge figée et doublures de tous les
 * contrats consommés. Jeton de test : « Bearer <utilisateur>:<client> ».
 */
abstract class ReportingTestCase extends TestCase
{
    protected Kernel $kernel;
    protected StubAccess $access;
    protected Permissions $permissions;
    protected Enrollments $enrollments;
    protected People $people;
    protected Establishments $establishments;
    protected Debtors $debtors;
    protected Attendance $attendance;
    protected Results $results;
    protected Documents $documents;
    protected Timetable $timetable;
    protected Staff $staff;
    protected Modules $modules;
    protected Actors $actors;
    protected Consents $consents;
    protected AdultFlags $adults;
    protected PendingIds $pending;
    protected FakeApprovals $approvals;

    /** @param array<string, mixed> $extra définitions supplémentaires (fournisseurs d'indicateurs ou de tâches) */
    protected function bootReporting(array $extra = []): void
    {
        $this->access = new StubAccess();
        $this->permissions = new Permissions();
        $this->enrollments = new Enrollments();
        $this->people = new People();
        $this->establishments = new Establishments();
        $this->debtors = new Debtors();
        $this->attendance = new Attendance();
        $this->results = new Results();
        $this->documents = new Documents();
        $this->timetable = new Timetable();
        $this->staff = new Staff();
        $this->modules = new Modules();
        $this->actors = new Actors();
        $this->consents = new Consents();
        $this->adults = new AdultFlags();
        $this->pending = new PendingIds();
        $this->approvals = new FakeApprovals();
        $this->establishments->add('t1', 'e1', 'IPES Douala', 'higher');
        $this->establishments->add('t1', 'e2', 'Collège Le Pivot');
        $this->establishments->add('t2', 'x1', 'Autre École');

        $this->kernel = Kernel::boot(dirname(__DIR__, 3), Config::fromArray([
            'app' => ['name' => 'SCHOLARIS', 'debug' => false, 'timezone' => 'Africa/Douala', 'api_prefix' => '/api/v1', 'log_path' => 'storage/logs/test.log'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'api' => ['cors_origins' => [], 'idempotent_paths' => [], 'rate_limits' => []],
        ]), $extra + [
            Authenticator::class => new StubAuthenticator(),
            EstablishmentAccess::class => $this->access,
            PermissionChecker::class => $this->permissions,
            Clock::class => new FixedClock(),
            EnrollmentDirectory::class => $this->enrollments,
            PersonDirectory::class => $this->people,
            EstablishmentDirectory::class => $this->establishments,
            DebtorDirectory::class => $this->debtors,
            AttendanceSummaryProvider::class => $this->attendance,
            ResultsDirectory::class => $this->results,
            DocumentCatalog::class => $this->documents,
            TimetableDirectory::class => $this->timetable,
            StaffDirectory::class => $this->staff,
            ModuleAvailability::class => $this->modules,
            ActorDirectory::class => $this->actors,
            ConsentChecker::class => $this->consents,
            AdultLearnerCheck::class => $this->adults,
            PendingApprovalLister::class => $this->pending,
            Approvals::class => $this->approvals,
        ]);
        $this->container()->get(Migrator::class)->migrate();
    }

    protected function container(): Container
    {
        return $this->kernel->container();
    }

    /** @return array{0: ResponseInterface, 1: array<string, mixed>} */
    protected function api(string $path, string $user, string $tenant = 't1', ?string $establishment = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $user . ':' . $tenant];
        if ($establishment !== null) {
            $headers['X-Establishment-Id'] = $establishment;
        }
        $response = $this->kernel->handle(new ServerRequest('GET', '/api/v1' . $path, $headers));
        $decoded = json_decode((string) $response->getBody(), true);

        return [$response, is_array($decoded) ? $decoded : []];
    }

    /** @param array<string, mixed> $dashboard @return array<string, array<string, mixed>> */
    protected function byKey(array $dashboard): array
    {
        $out = [];
        foreach ($dashboard['indicators'] as $indicator) {
            $out[$indicator['key']] = $indicator;
        }

        return $out;
    }
}

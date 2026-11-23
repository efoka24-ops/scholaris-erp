<?php

declare(strict_types=1);

namespace Tests\Support\Attendance;

use DI\Container;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Security\Authenticator;
use Scholaris\Core\Security\EstablishmentAccess;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Scheduling\Contracts\CourseUnitOccurrences;
use Scholaris\Modules\Socle\Scheduling\Contracts\SlotInfo;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\TaughtHoursRecorder;
use Tests\Support\StubAccess;
use Tests\Support\StubAuthenticator;

/**
 * Base des tests du module Attendance : Kernel de test (SQLite en mémoire migrée), horloge figée (jeudi 8 octobre 2026, 09 h)
 * et doublures des contrats des autres modules (emploi du temps, inscriptions, personnes, consentements, messagerie, paramètres, droits).
 *
 * Jeu : classe c1 de l'établissement e1 avec trois élèves (s1, s2, s3) ; créneau « slot1 » de mathématiques tenu le jeudi par
 * l'enseignant (utilisateur « teacher », personne p-teacher) ; s1 et s2 ont un parent (p-parent1, p-parent2).
 */
abstract class AttendanceTestCase extends TestCase
{
    protected Kernel $kernel;
    protected StubAccess $access;
    protected Permissions $permissions;
    protected FixedClock $clock;
    protected InMemoryPeople $people;
    protected InMemoryEnrollments $enrollments;
    protected InMemoryTimetable $timetable;
    protected InMemoryConsents $consents;
    protected RecordingMessenger $messenger;
    protected ArraySettings $settings;
    protected InMemoryEstablishments $establishments;
    protected InMemoryStaff $staff;
    protected RecordingHours $hours;

    protected const DAY = '2026-10-08';

    /** @param array<string, mixed> $settings */
    protected function bootAttendance(array $settings = []): void
    {
        $this->permissions = new Permissions();
        $this->clock = new FixedClock();
        $this->people = new InMemoryPeople();
        $this->enrollments = new InMemoryEnrollments();
        $this->timetable = new InMemoryTimetable();
        $this->consents = new InMemoryConsents();
        $this->messenger = new RecordingMessenger();
        $this->settings = new ArraySettings($settings);
        $this->staff = new InMemoryStaff();
        $this->hours = new RecordingHours();
        $this->establishments = new InMemoryEstablishments();
        $this->establishments->add('t1', 'e1', 'IPES Douala');
        $this->establishments->add('t1', 'e2', 'Collège Le Pivot');
        $this->establishments->add('t2', 'x1', 'Autre École');

        $this->access = new StubAccess();
        $this->kernel = Kernel::boot(dirname(__DIR__, 3), Config::fromArray([
            'app' => ['name' => 'SCHOLARIS', 'debug' => false, 'timezone' => 'Africa/Douala', 'api_prefix' => '/api/v1', 'log_path' => 'storage/logs/test.log'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'api' => ['cors_origins' => [], 'idempotent_paths' => [], 'rate_limits' => []],
        ]), [
            Authenticator::class => new StubAuthenticator(),
            EstablishmentAccess::class => $this->access,
            PermissionChecker::class => $this->permissions,
            Clock::class => $this->clock,
            PersonDirectory::class => $this->people,
            EnrollmentDirectory::class => $this->enrollments,
            ConsentChecker::class => $this->consents,
            Messenger::class => $this->messenger,
            SettingsReader::class => $this->settings,
            EstablishmentDirectory::class => $this->establishments,
            TimetableDirectory::class => $this->timetable,
            CourseUnitOccurrences::class => new InMemoryOccurrences($this->timetable),
            StaffDirectory::class => $this->staff,
            TaughtHoursRecorder::class => $this->hours,
        ]);
        $this->container()->get(Migrator::class)->migrate();

        $this->timetable->add(new SlotInfo('slot1', 't1', 'e1', 'c1', 'p-teacher', 'Mathématiques', 4, '08:00', '09:55', '2026-09-01', null, 'B12'));
        foreach (['s1' => 'Yann', 's2' => 'Nadia', 's3' => 'Paul'] as $id => $first) {
            $this->people->add('t1', $id, $first, 'Foka');
            $this->enrollments->add('t1', 'en-' . $id, $id, 'e1', 'c1');
        }
        $this->people->add('t1', 'p-parent1', 'Jean', 'Foka', '+237670000001');
        $this->people->add('t1', 'p-parent2', 'Marie', 'Foka', '+237670000002');
        $this->people->link('t1', 'p-parent1', 's1');
        $this->people->link('t1', 'p-parent2', 's2');

        $this->user('teacher', 'p-teacher', ['attendance.take']);
        $this->user('sub', 'p-sub', ['attendance.take']);
        $this->user('vs', 'p-vs', ['attendance.read', 'attendance.justify']);
        $this->user('reader', 'p-reader', ['attendance.read']);
        $this->user('surveillant', 'p-sur', ['attendance.read', 'attendance.discipline.manage']);
        $this->user('censeur', 'p-cens', ['attendance.read', 'attendance.discipline.approve']);
        $this->user('parent1', 'p-parent1', []);
        $this->user('parent2', 'p-parent2', []);
        $this->user('student1', 's1', []);
        $this->user('nobody', 'p-nobody', []);
    }

    /**
     * Compte utilisateur lié à une personne, avec droits sur les établissements e1 et e2 du client t1.
     *
     * @param list<string> $permissions
     */
    protected function user(string $userId, string $personId, array $permissions): void
    {
        $this->connections()->pdo()->prepare(
            'INSERT INTO users (id, tenant_id, person_id, locale, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        )->execute([$userId, 't1', $personId, 'fr', 'active', '2026-10-08T00:00:00+01:00', '2026-10-08T00:00:00+01:00']);
        $this->access->grant($userId, 'e1');
        $this->access->grant($userId, 'e2');
        $this->permissions->grant($userId, ...$permissions);
    }

    protected function container(): Container
    {
        return $this->kernel->container();
    }

    protected function connections(): ConnectionFactory
    {
        return $this->container()->get(ConnectionFactory::class);
    }

    /** @param array<string, string> $headers */
    protected function call(string $method, string $path, array $headers = [], string $body = ''): ResponseInterface
    {
        $request = new ServerRequest($method, $path, $headers, $body);
        parse_str($request->getUri()->getQuery(), $query);

        return $this->kernel->handle($request->withQueryParams($query));
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    protected function api(string $method, string $path, string $user, ?array $body = null, string $tenant = 't1', string $establishment = 'e1'): array
    {
        $headers = ['Authorization' => 'Bearer ' . $user . ':' . $tenant, 'X-Establishment-Id' => $establishment];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $response = $this->call($method, '/api/v1' . $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
        $raw = (string) $response->getBody();
        $decoded = $raw !== '' ? json_decode($raw, true) : [];

        return [$response, is_array($decoded) ? $decoded : []];
    }

    protected static function ulid(): string
    {
        return (string) new Ulid();
    }

    /**
     * Lignes d'un appel complet : s1 présent, s2 absent, s3 en retard.
     *
     * @return list<array<string, mixed>>
     */
    protected function fullRecords(string $s1 = 'P', string $s2 = 'A', string $s3 = 'R'): array
    {
        $line = static fn (string $person, string $status): array => ['person_id' => $person, 'status' => $status, 'client_uuid' => self::ulid()]
            + ($status === 'R' ? ['arrived_at' => '08:12'] : []);

        return [$line('s1', $s1), $line('s2', $s2), $line('s3', $s3)];
    }

    /**
     * Ouvre l'appel du créneau slot1 (jeudi 8 octobre 2026) par l'enseignant ; renvoie la séance.
     *
     * @return array<string, mixed>
     */
    protected function openSession(string $user = 'teacher', ?string $clientUuid = null, string $date = self::DAY): array
    {
        [$response, $json] = $this->api('POST', '/attendance-sessions', $user, ['client_uuid' => $clientUuid ?? self::ulid(), 'slot_id' => 'slot1', 'date' => $date]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    /** @return list<array<string, mixed>> */
    protected function rows(string $sql): array
    {
        return $this->connections()->pdo()->query($sql)->fetchAll();
    }
}

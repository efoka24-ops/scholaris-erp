<?php

declare(strict_types=1);

namespace Tests\Support\PublicSchools;

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
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Socle\Identity\Contracts\NamedAccessJournal;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Identity\Contracts\RoleDirectory;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffMover;
use Tests\Support\Attendance\ArraySettings;
use Tests\Support\Attendance\FixedClock;
use Tests\Support\Attendance\InMemoryConsents;
use Tests\Support\Attendance\InMemoryEnrollments;
use Tests\Support\Attendance\InMemoryEstablishments;
use Tests\Support\Attendance\InMemoryPeople;
use Tests\Support\Attendance\Permissions;
use Tests\Support\Attendance\RecordingMessenger;
use Tests\Support\StubAccess;
use Tests\Support\StubAuthenticator;

/**
 * Base des tests des modules Attendance, Staff, Messages et Sync de PublicSchools : noyau de test (SQLite en mémoire migrée) avec les
 * migrations réelles du socle et de l'application, horloge figée au jeudi 8 octobre 2026 à 09 h (Douala) et doublures des contrats
 * des autres modules.
 *
 * Jeu (fictif) : client t1, écoles e1 et e2 ; client t2, école x1. Classes de e1 : c1 « CP1 » (élèves s1, s2, s3 ; titulaire st-teacher)
 * et c2 « CE1 » (élèves s4, s5 ; sans titulaire). Parents p-parent1 (de s1) et p-parent2 (de s2).
 * Acteurs : « teacher » (classe c1), « teacher2 » (classe c2), « director » (toute l'école e1), « inspector » (arrondissement arr-1,
 * qui contient e1), « inspector2 » (arrondissement arr-2, qui ne contient pas e1), « outsider » (aucun droit).
 */
abstract class PublicSchoolsTestCase extends TestCase
{
    protected const DAY = '2026-10-08';

    protected Kernel $kernel;
    protected StubAccess $access;
    protected Permissions $permissions;
    protected FixedClock $clock;
    protected ArraySettings $settings;
    protected InMemoryPeople $people;
    protected InMemoryEnrollments $enrollments;
    protected RecordingMessenger $messenger;
    protected FakeClassRoster $classes;
    protected FakeCurrentPeriod $periods;
    protected FakePermissionScopes $scopes;
    protected FakeStaffDirectory $staff;
    protected FakeStaffMover $mover;
    protected FakeRoleDirectory $roles;
    protected InMemoryTerritories $territories;
    protected RecordingNamedAccessJournal $journal;
    protected FakePublicApplicationAccess $applications;

    /** @param array<string, mixed> $settings */
    protected function bootPublic(array $settings = []): void
    {
        $this->permissions = new Permissions();
        $this->clock = new FixedClock();
        $this->settings = new ArraySettings(['attendance.mode' => 'day_part'] + $settings);
        $this->people = new InMemoryPeople();
        $this->enrollments = new InMemoryEnrollments();
        $this->messenger = new RecordingMessenger();
        $this->classes = new FakeClassRoster($this->enrollments);
        $this->periods = new FakeCurrentPeriod();
        $this->scopes = new FakePermissionScopes();
        $this->staff = new FakeStaffDirectory();
        $this->mover = new FakeStaffMover();
        $this->roles = new FakeRoleDirectory();
        $this->territories = new InMemoryTerritories();
        $this->journal = new RecordingNamedAccessJournal();
        $this->applications = new FakePublicApplicationAccess();
        $this->applications->set('t1', 'public_primary', ApplicationAccess::ACTIVE);
        $this->access = new StubAccess();
        $establishments = new InMemoryEstablishments();
        $establishments->add('t1', 'e1', 'École publique de Bonamoussadi', 'primary');
        $establishments->add('t1', 'e2', 'École publique de Makepe', 'primary');
        $establishments->add('t2', 'x1', 'École publique voisine', 'primary');

        $this->kernel = Kernel::boot(dirname(__DIR__, 3), Config::fromArray([
            'app' => ['name' => 'SCHOLARIS', 'debug' => false, 'timezone' => 'Africa/Douala', 'api_prefix' => '/api/v1', 'log_path' => 'storage/logs/test.log'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'api' => ['cors_origins' => [], 'idempotent_paths' => [], 'rate_limits' => []],
            'sync' => ['secret_key' => 'cle-de-test-ecoles-publiques'],
        ]), [
            Authenticator::class => new StubAuthenticator(),
            EstablishmentAccess::class => $this->access,
            PermissionChecker::class => $this->permissions,
            Clock::class => $this->clock,
            PersonDirectory::class => $this->people,
            EnrollmentDirectory::class => $this->enrollments,
            ConsentChecker::class => new InMemoryConsents(),
            Messenger::class => $this->messenger,
            SettingsReader::class => $this->settings,
            EstablishmentDirectory::class => $establishments,
            StaffDirectory::class => $this->staff,
            StaffMover::class => $this->mover,
            RoleDirectory::class => $this->roles,
            TerritoryDirectory::class => $this->territories,
            NamedAccessJournal::class => $this->journal,
            ApplicationAccess::class => $this->applications,
            PermissionScopes::class => $this->scopes,
            ClassRoster::class => $this->classes,
            CurrentPeriod::class => $this->periods,
        ]);
        $this->container()->get(Migrator::class)->migrate();

        $this->territories->add('t1', 'reg-1', 'region', null);
        $this->territories->add('t1', 'dep-1', 'departement', 'reg-1');
        $this->territories->add('t1', 'arr-1', 'arrondissement', 'dep-1');
        $this->territories->add('t1', 'arr-2', 'arrondissement', 'dep-1');
        $this->territories->add('t1', 'dep-2', 'departement', 'reg-1');
        $this->territories->add('t1', 'arr-3', 'arrondissement', 'dep-2');
        $this->territories->attach('t1', 'e1', 'arr-1');
        $this->territories->attach('t1', 'e2', 'arr-2');

        $this->classes->add('t1', 'e1', 'c1', 'CP1', ['CP'], 'st-teacher');
        $this->classes->add('t1', 'e1', 'c2', 'CE1', ['CE1'], null);
        foreach (['s1' => ['Yann', 'c1'], 's2' => ['Nadia', 'c1'], 's3' => ['Paul', 'c1'], 's4' => ['Awa', 'c2'], 's5' => ['Bello', 'c2']] as $id => [$first, $class]) {
            $this->people->add('t1', $id, $first, 'Foka');
            $this->enrollments->add('t1', 'en-' . $id, $id, 'e1', $class);
        }
        $this->people->add('t1', 'p-parent1', 'Jean', 'Foka', '+237670000001');
        $this->people->add('t1', 'p-parent2', 'Marie', 'Foka', '+237670000002');
        $this->people->link('t1', 'p-parent1', 's1');
        $this->people->link('t1', 'p-parent2', 's2');

        foreach (['p-teacher' => ['Aimé', 'Mballa'], 'p-teacher2' => ['Rose', 'Ngono'], 'p-sub' => ['Luc', 'Tchoua'], 'p-director' => ['Paul', 'Essomba']] as $id => [$first, $last]) {
            $this->people->add('t1', $id, $first, $last);
        }
        $this->staff->add('t1', 'e1', 'p-teacher', 'st-teacher', 'Instituteur');
        $this->staff->add('t1', 'e1', 'p-teacher2', 'st-teacher2', 'Instituteur principal');
        $this->staff->add('t1', 'e1', 'p-sub', 'st-sub', 'Maître des parents');
        $this->roles->add('t1', 'e1', 'pub_school_director', 'p-director');

        $this->actor('teacher', 'p-teacher', ['public_schools.attendance.read', 'public_schools.attendance.write', 'public_schools.teacher_presence.read', 'public_schools.messages.send', 'sync.batches.submit'], new Scope(Scope::CLASS_GROUP, 'c1', 'pub_class_teacher'));
        $this->actor('teacher2', 'p-teacher2', ['public_schools.attendance.read', 'public_schools.attendance.write', 'public_schools.teacher_presence.read', 'public_schools.messages.send', 'sync.batches.submit'], new Scope(Scope::CLASS_GROUP, 'c2', 'pub_class_teacher'));
        $this->actor('director', 'p-director', [
            'public_schools.attendance.read', 'public_schools.attendance.write', 'public_schools.teacher_presence.read', 'public_schools.teacher_presence.write',
            'public_schools.staff.read', 'public_schools.staff.write', 'public_schools.messages.send', 'attendance.correct', 'attendance.correct_late',
            'sync.batches.submit', 'sync.devices.manage',
        ], new Scope(Scope::ESTABLISHMENT, 'e1', 'pub_school_director'));
        $this->actor('inspector', 'p-inspector', [
            'public_schools.attendance.read', 'public_schools.teacher_presence.read', 'public_schools.teacher_presence.validate', 'public_schools.staff.read',
            'public_schools.messages.send', 'public_schools.messages.announce_territory',
        ], Scope::territory('arr-1', 'pub_district_inspector', 't1', 'e1'));
        $this->actor('inspector2', 'p-inspector2', [
            'public_schools.attendance.read', 'public_schools.teacher_presence.read', 'public_schools.teacher_presence.validate', 'public_schools.staff.read',
            'public_schools.messages.announce_territory',
        ], Scope::territory('arr-2', 'pub_district_inspector', 't1', 'e1'));
        $this->actor('outsider', 'p-outsider', [], null);
    }

    /** @param list<string> $permissions */
    protected function actor(string $userId, string $personId, array $permissions, ?Scope $scope): void
    {
        $this->connections()->pdo()->prepare(
            'INSERT INTO users (id, tenant_id, person_id, locale, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        )->execute([$userId, 't1', $personId, 'fr', 'active', '2026-10-08T00:00:00+01:00', '2026-10-08T00:00:00+01:00']);
        $this->access->grant($userId, 'e1');
        $this->access->grant($userId, 'e2');
        $this->permissions->grant($userId, ...$permissions);
        if ($scope !== null) {
            $this->scopes->give($userId, $scope);
        }
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

    /**
     * PUT de l'appel d'une classe (API-05).
     *
     * @param list<array<string, mixed>> $records exceptions seulement
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    protected function putAttendance(string $user, string $class, string $date, string $part, array $records = [], ?string $reason = null, ?string $uuid = null): array
    {
        $body = ['day_part' => $part, 'client_uuid' => $uuid ?? self::ulid(), 'records' => $records];
        if ($reason !== null) {
            $body['reason'] = $reason;
        }

        return $this->api('PUT', '/public/classes/' . $class . '/attendance/' . $date, $user, $body);
    }

    /**
     * Inscrit un appareil au nom du directeur ; la clé n'est rendue qu'ici.
     *
     * @return array{id: string, key: string}
     */
    protected function registerDevice(string $label = 'Téléphone SYN-ECOLE-01'): array
    {
        [$response, $json] = $this->api('POST', '/sync/devices', 'director', ['label' => $label]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return ['id' => (string) $json['data']['id'], 'key' => (string) $json['data']['key']];
    }

    /**
     * Envoie un lot gzip signé (API-04).
     *
     * @param list<array<string, mixed>> $items
     * @param array{id: string, key: string} $device
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    protected function postBatch(array $items, array $device, string $user = 'teacher', ?string $batchId = null): array
    {
        $batchId ??= self::ulid();
        $raw = (string) gzencode(json_encode(['items' => $items], JSON_THROW_ON_ERROR));
        $response = $this->call('POST', '/api/v1/sync/batches', [
            'Authorization' => 'Bearer ' . $user . ':t1',
            'X-Establishment-Id' => 'e1',
            'X-Device-Id' => $device['id'],
            'Idempotency-Key' => $batchId,
            'Content-Type' => 'application/json',
            'Content-Encoding' => 'gzip',
            'X-Batch-Signature' => hash_hmac('sha256', $device['id'] . "\n" . $batchId . "\n" . $raw, $device['key']),
        ], $raw);
        $decoded = json_decode((string) $response->getBody(), true);

        return [$response, is_array($decoded) ? $decoded : []];
    }

    /**
     * Élément de lot « appel d'une classe » (exceptions seulement).
     *
     * @param list<array<string, mixed>> $records
     * @return array{kind: string, client_uuid: string, payload: array<string, mixed>}
     */
    protected static function attendanceItem(string $class, string $date, string $part, array $records = [], ?string $uuid = null): array
    {
        return [
            'kind' => 'public.attendance',
            'client_uuid' => $uuid ?? self::ulid(),
            'payload' => ['structure_id' => $class, 'date' => $date, 'day_part' => $part, 'records' => $records],
        ];
    }

    protected function setNow(string $moment): void
    {
        $this->clock->set($moment);
    }

    protected static function ulid(): string
    {
        return (string) new Ulid();
    }

    /** @return list<array<string, mixed>> */
    protected function rows(string $sql): array
    {
        $statement = $this->connections()->pdo()->prepare($sql);
        $statement->execute();

        return array_values($statement->fetchAll());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Pupils;

use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PublicMessages;
use Scholaris\Modules\Socle\Identity\Contracts\NamedAccessJournal;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGate;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGuards;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;
use Scholaris\Modules\Socle\People\Contracts\TeachingScope;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\AppTestCase;
use Tests\Support\Higher\FakePermissionScopes;
use Tests\Support\Higher\HigherPermissions;
use Tests\Support\People\FixedClock;
use Tests\Support\People\FixedSettings;
use Tests\Support\People\FixedTeaching;
use Tests\Support\People\NoEnrolmentGuards;
use Tests\Support\PublicSchools\FakePublicApplicationAccess;
use Tests\Support\PublicSchools\InMemoryTerritories;
use Tests\Support\PublicSchools\RecordingNamedAccessJournal;

/**
 * Base des tests des modules Schools, Pupils et Territory : Kernel de test avec les vrais modules du socle (People, Organisation),
 * des doublures de droits (permissions et périmètres), du droit d'usage de l'application, des territoires et des messages.
 * Données fictives SYN-* : clients t1 et t2, écoles e1 et e2 (t1), e9 (t2) ; territoires R1 > D1 > A1 (e1, e2) et A2 (e3), client t2 : RB > DB > AB (e9).
 */
abstract class PublicSchoolsTestCase extends AppTestCase
{
    protected HigherPermissions $permissions;
    protected FakePermissionScopes $scopes;
    protected FakePublicApplicationAccess $applications;
    protected FixedSettings $settings;
    protected InMemoryTerritories $territories;
    protected RecordingNamedAccessJournal $journal;
    protected RecordingPublicMessages $messages;
    protected FixedClock $clock;
    protected string $storage = '';

    /** @param array<string, mixed> $extra définitions de conteneur supplémentaires (doublures du test) */
    protected function bootPublic(bool $withMessages = true, array $extra = []): void
    {
        $this->permissions = new HigherPermissions();
        $this->scopes = new FakePermissionScopes();
        $this->applications = new FakePublicApplicationAccess();
        $this->settings = new FixedSettings();
        $this->territories = new InMemoryTerritories();
        $this->journal = new RecordingNamedAccessJournal();
        $this->messages = new RecordingPublicMessages();
        $this->clock = new FixedClock('2026-10-21T09:00:00+01:00');
        $this->storage = sys_get_temp_dir() . '/scholaris-public-schools-' . bin2hex(random_bytes(6));
        $this->applications->set('t1', 'public_primary', ApplicationAccess::ACTIVE);
        $this->applications->set('t2', 'public_primary', ApplicationAccess::ACTIVE);
        foreach (['t1' => ['R1', 'D1', 'A1', 'A2'], 't2' => ['RB', 'DB', 'AB']] as $tenant => [$region, $department, $district]) {
            $this->territories->add($tenant, $region, 'region', null);
            $this->territories->add($tenant, $department, 'departement', $region);
            $this->territories->add($tenant, $district, 'arrondissement', $department);
        }
        $this->territories->add('t1', 'A2', 'arrondissement', 'D1');

        $this->boot($extra + ($withMessages ? [PublicMessages::class => $this->messages] : []) + [
            PermissionChecker::class => $this->permissions,
            PermissionScopes::class => $this->scopes,
            ApplicationAccess::class => $this->applications,
            SettingsReader::class => $this->settings,
            TerritoryDirectory::class => $this->territories,
            NamedAccessJournal::class => $this->journal,
            Clock::class => $this->clock,
            EnrolmentGate::class => new OpenEnrolments(),
            EnrolmentGuards::class => new NoEnrolmentGuards(),
            TeachingScope::class => new FixedTeaching(),
        ], ['people' => ['secret_key' => 'cle-de-test-public-schools', 'storage_path' => $this->storage]]);

        $this->seedEstablishment('t1', 'e1');
        $this->seedEstablishment('t1', 'e2');
        $this->seedEstablishment('t1', 'e3');
        $this->seedEstablishment('t2', 'e9');
        $this->territories->attach('t1', 'e1', 'A1');
        $this->territories->attach('t1', 'e2', 'A1');
        $this->territories->attach('t1', 'e3', 'A2');
        $this->territories->attach('t2', 'e9', 'AB');
    }

    protected function tearDown(): void
    {
        if ($this->storage !== '' && is_dir($this->storage)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->storage);
        }
    }

    protected function seedEstablishment(string $tenant, string $id): void
    {
        $now = '2026-10-08T09:00:00+01:00';
        $branding = ['accent' => '#F26B1D', 'logo' => null, 'initial' => 'E', 'headers' => [], 'signatories' => [], 'authorization_numbers' => []];
        $this->connections()->pdo()->prepare(
            'INSERT INTO establishments (id, tenant_id, name, type, branding, modules_enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $id, $tenant, 'École SYN ' . $id, 'primary', json_encode($branding, JSON_THROW_ON_ERROR),
            json_encode(['people', 'billing', 'messaging', 'documents', 'attendance', 'staff', 'reporting'], JSON_THROW_ON_ERROR), $now, $now,
        ]);
    }

    /** Fiche école ouverte (pub_schools) pour que l'établissement soit une école publique. */
    protected function seedSchool(string $tenant, string $establishment, string $code, string $status = 'open', ?string $actRef = 'SYN-DECRET-1'): void
    {
        $now = '2026-10-08T09:00:00+01:00';
        $this->connections()->pdo()->prepare(
            'INSERT INTO pub_schools (id, tenant_id, establishment_id, official_code, creation_act_ref, creation_act_date, subsystem, cycle, school_group_ref, status, latitude, longitude, phone, email, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, NULL, ?, ?, NULL, ?, NULL, NULL, NULL, NULL, ?, ?)',
        )->execute([(string) new \Scholaris\Core\Support\Ulid(), $tenant, $establishment, $code, $actRef, 'francophone', 'primary', $status, $now, $now]);
    }

    /** Droits d'un utilisateur : permissions de l'école et périmètre « école entière » (directeur). */
    protected function director(string $user, string $tenant, string $establishment, string ...$permissions): void
    {
        $this->access->grant($user, $establishment);
        foreach ($permissions as $permission) {
            $this->permissions->grant($tenant, $user, $permission, $establishment);
            $this->scopes->add($tenant, $user, $establishment, $permission, new Scope(Scope::ESTABLISHMENT, $establishment, 'pub_school_director'));
        }
    }

    /** Droits d'un enseignant : périmètre limité à une classe. */
    protected function teacher(string $user, string $tenant, string $establishment, string $classId, string ...$permissions): void
    {
        $this->access->grant($user, $establishment);
        foreach ($permissions as $permission) {
            $this->permissions->grant($tenant, $user, $permission, $establishment);
            $this->scopes->add($tenant, $user, $establishment, $permission, new Scope(Scope::STRUCTURE, $classId, 'pub_class_teacher'));
        }
    }

    /** Droits d'un superviseur territorial (inspecteur, délégué) sur une école. */
    protected function supervisor(string $user, string $tenant, string $establishment, string $territory, string $role, string ...$permissions): void
    {
        $this->access->grant($user, $establishment);
        foreach ($permissions as $permission) {
            $this->permissions->grant($tenant, $user, $permission, $establishment);
            $this->scopes->add($tenant, $user, $establishment, $permission, Scope::territory($territory, $role, $tenant, $establishment));
        }
    }

    /** Crée une classe publique (structure du socle et profil) et rend l'identifiant de la structure. */
    protected function createClass(string $tenant, string $establishment, string $name, string $yearId = 'y2026', string $level = 'SIL', string $shift = 'full_day', ?int $capacity = null): string
    {
        $writer = $this->kernel->container()->get(StructureWriter::class);
        $unit = $writer->createOrgUnit($tenant, $establishment, 'school', 'École SYN ' . $establishment, null, 'seed');
        $class = $this->kernel->container()->get(\Scholaris\Modules\Apps\PublicSchools\Pupils\Application\ClassProfileService::class)->create(
            $tenant,
            $establishment,
            'seed',
            ['name' => $name, 'academic_year_id' => $yearId, 'org_unit_id' => $unit, 'level_codes' => [$level], 'shift' => $shift] + ($capacity === null ? [] : ['capacity' => $capacity]),
        );
        unset($writer);

        return $class['structure_id'];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    protected function api(string $method, string $path, string $user, string $tenant, ?string $establishment, ?array $body = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $user . ':' . $tenant];
        if ($establishment !== null) {
            $headers['X-Establishment-Id'] = $establishment;
        }
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $response = $this->call($method, '/api/v1' . $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
        $raw = (string) $response->getBody();
        $decoded = $raw !== '' && str_contains($response->getHeaderLine('Content-Type'), 'json') ? json_decode($raw, true) : [];

        return [$response, is_array($decoded) ? $decoded : []];
    }

    /** @param array<string, mixed>|null $body */
    protected function code(string $method, string $path, string $user, string $tenant, ?string $establishment, ?array $body = null): int
    {
        return $this->api($method, $path, $user, $tenant, $establishment, $body)[0]->getStatusCode();
    }

    /**
     * @param list<mixed> $params
     */
    protected function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->connections()->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }
}

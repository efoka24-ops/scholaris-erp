<?php

declare(strict_types=1);

namespace Tests\Support\Staff;

use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Modules\Socle\Audit\Contracts\Approvals;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\AppTestCase;

/**
 * Base des tests du module Staff : Kernel de test (SQLite en mémoire migrée), horloge figée au 8 octobre 2026, doublures de
 * PermissionChecker, PersonDirectory et Approvals. Client « t1 », établissement « e1 ».
 * Utilisateurs : « admin » (staff.read, staff.manage, vacations.manage), « boss » (staff.read, vacations.validate),
 * « boss2 » (vacations.validate), « prof » et « prof2 » (aucun droit : enseignants, personnes p-prof et p-prof2).
 */
abstract class StaffTestCase extends AppTestCase
{
    protected Permissions $permissions;
    protected FixedClock $clock;
    protected FakePersons $persons;
    protected FakeApprovals $approvals;

    protected ArraySettings $settings;

    /**
     * @param array<string, mixed> $settings paramètres (ex. staff.hetd.cm)
     * @param array<string, mixed> $definitions définitions de conteneur supplémentaires (ex. contrôles de payabilité)
     */
    protected function bootStaff(array $settings = [], array $definitions = []): void
    {
        $this->permissions = new Permissions();
        $this->clock = new FixedClock();
        $this->persons = new FakePersons();
        $this->approvals = new FakeApprovals();
        $this->settings = new ArraySettings($settings);
        $this->boot([
            PermissionChecker::class => $this->permissions,
            Clock::class => $this->clock,
            PersonDirectory::class => $this->persons,
            Approvals::class => $this->approvals,
            SettingsReader::class => $this->settings,
        ] + $definitions);

        foreach (['admin', 'boss', 'boss2', 'prof', 'prof2'] as $user) {
            $this->access->grant($user, 'e1');
        }
        $this->permissions->grant('admin', 'staff.read', 'staff.manage', 'vacations.manage');
        $this->permissions->grant('boss', 'staff.read', 'vacations.validate');
        $this->permissions->grant('boss2', 'vacations.validate');

        $this->persons->add('t1', 'p-prof', 'Jean', 'Mbarga');
        $this->persons->add('t1', 'p-prof2', 'Awa', 'Ndiaye');
        $this->persons->add('t1', 'p-vac', 'Paul', 'Essomba');
        $this->persons->add('t2', 'p-autre', 'Zoe', 'Autre');
        $this->account('prof', 'p-prof');
        $this->account('prof2', 'p-prof2');
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    protected function api(string $method, string $path, string $user = 'admin', string $tenant = 't1', ?string $establishment = 'e1', ?array $body = null): array
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

        return [$response, $raw === '' ? [] : (array) json_decode($raw, true, flags: JSON_THROW_ON_ERROR)];
    }

    /** @param array<string, mixed>|null $body */
    protected function code(string $method, string $path, string $user = 'admin', string $tenant = 't1', ?string $establishment = 'e1', ?array $body = null): int
    {
        return $this->api($method, $path, $user, $tenant, $establishment, $body)[0]->getStatusCode();
    }

    /** Compte utilisateur lié à une personne (table users d'Identity). */
    protected function account(string $userId, string $personId, string $tenant = 't1'): void
    {
        $this->connections()->pdo()->prepare(
            'INSERT INTO users (id, tenant_id, person_id, phone, locale, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([$userId, $tenant, $personId, null, 'fr', 'active', '2026-10-08T09:00:00+01:00', '2026-10-08T09:00:00+01:00']);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function staff(string $personId, string $kind = 'permanent', array $extra = []): array
    {
        [$response, $body] = $this->api('POST', '/staff', body: ['person_id' => $personId, 'kind' => $kind] + $extra);
        self::assertSame(201, $response->getStatusCode(), json_encode($body));

        return $body['data'];
    }

    /**
     * Contrat de vacation actif (créé par admin, approuvé par boss).
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function activeContract(string $staffId, array $extra = []): array
    {
        [$response, $body] = $this->api('POST', '/vacation-contracts', body: ['staff_id' => $staffId, 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'] + $extra);
        self::assertSame(201, $response->getStatusCode(), json_encode($body));
        $id = (string) $body['data']['id'];
        self::assertSame(200, $this->code('POST', '/vacation-contracts/' . $id . '/submit'));
        [$response, $body] = $this->api('POST', '/vacation-contracts/' . $id . '/approve', 'boss');
        self::assertSame(200, $response->getStatusCode(), json_encode($body));

        return $body['data'];
    }

    /**
     * Heure émargée par l'enseignant puis validée par « boss ».
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function validatedHour(string $staffId, string $teacherUser, string $date, int $minutes, string $kind, array $extra = []): array
    {
        $hour = $this->declaredHour($staffId, $teacherUser, $date, $minutes, $kind, $extra);
        [$response, $body] = $this->api('POST', '/vacation-hours/' . $hour['id'] . '/validate', 'boss');
        self::assertSame(200, $response->getStatusCode(), json_encode($body));

        return $body['data'];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function declaredHour(string $staffId, string $teacherUser, string $date, int $minutes, string $kind, array $extra = []): array
    {
        [$response, $body] = $this->api('POST', '/vacation-hours', $teacherUser, body: [
            'staff_id' => $staffId, 'held_on' => $date, 'minutes' => $minutes, 'session_kind' => $kind,
            'content' => 'Chapitre 1', 'signed' => true,
        ] + $extra);
        self::assertSame(201, $response->getStatusCode(), json_encode($body));

        return $body['data'];
    }
}

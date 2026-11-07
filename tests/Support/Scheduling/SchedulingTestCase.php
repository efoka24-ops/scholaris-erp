<?php

declare(strict_types=1);

namespace Tests\Support\Scheduling;

use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Scheduling\Contracts\RoomCapacity;
use Tests\Support\AppTestCase;

/**
 * Base des tests du module Scheduling : Kernel de test (SQLite en mémoire migrée), doublures de PermissionChecker,
 * Messenger, EnrollmentDirectory, PersonDirectory, RoomCapacity et EstablishmentDirectory.
 * Client « t1 », établissement « e1 » : « admin » gère le calendrier et les emplois du temps.
 * Personnes : enseignants p-t1, p-t2, p-t3 ; apprenants p-l1 (classe c1), p-l2 (classe c2) ; garant p-g1 de p-l1.
 */
abstract class SchedulingTestCase extends AppTestCase
{
    protected Permissions $permissions;
    protected RecordingMessenger $messenger;
    protected InMemoryEnrollments $enrollments;
    protected InMemoryPeople $people;
    protected ArrayRooms $rooms;

    protected function bootScheduling(): void
    {
        $this->permissions = new Permissions();
        $this->messenger = new RecordingMessenger();
        $this->enrollments = new InMemoryEnrollments();
        $this->people = new InMemoryPeople();
        $this->rooms = new ArrayRooms();
        $this->boot([
            PermissionChecker::class => $this->permissions,
            Clock::class => new FixedClock(),
            Messenger::class => $this->messenger,
            EnrollmentDirectory::class => $this->enrollments,
            PersonDirectory::class => $this->people,
            RoomCapacity::class => $this->rooms,
            EstablishmentDirectory::class => new OneEstablishment(),
        ]);
        $this->access->grant('admin', 'e1');
        $this->permissions->grant('admin', 'scheduling.read', 'scheduling.manage');

        foreach (['p-t1' => 'Paul', 'p-t2' => 'Marie', 'p-t3' => 'Jean', 'p-l1' => 'Nadia', 'p-l2' => 'Eric', 'p-g1' => 'Rose'] as $id => $first) {
            $this->people->add('t1', $id, $first, 'Test');
        }
        $this->people->add('t2', 'p-t1', 'Paul', 'Autre');
        $this->people->link('t1', 'p-g1', 'p-l1');
        $this->enrollments->add('t1', 'en1', 'p-l1', 'e1', 'c1');
        $this->enrollments->add('t1', 'en2', 'p-l2', 'e1', 'c2');
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

    /**
     * Corps valide d'un créneau (lundi 08:00-10:00, enseignant p-t1, classe c1, salle r1), modifiable.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function slotBody(array $overrides = []): array
    {
        return array_replace([
            'subject_label' => 'Mathématiques',
            'teacher_person_id' => 'p-t1',
            'room_id' => 'r1',
            'structure_ids' => ['c1'],
            'weekday' => 1,
            'start_time' => '08:00',
            'end_time' => '10:00',
            'valid_from' => '2026-09-07',
            'valid_to' => '2027-06-30',
        ], $overrides);
    }

    /**
     * Crée un créneau par l'API et renvoie sa représentation.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function slot(array $overrides = []): array
    {
        [$response, $json] = $this->api('POST', '/timetable-slots', body: $this->slotBody($overrides));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    /** Compte utilisateur lié à une personne (table users d'Identity). */
    protected function account(string $userId, string $personId, string $tenant = 't1'): void
    {
        $this->access->grant($userId, 'e1');
        $this->connections()->pdo()->prepare(
            'INSERT INTO users (id, tenant_id, person_id, phone, locale, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([$userId, $tenant, $personId, null, 'fr', 'active', '2026-10-08T09:00:00+01:00', '2026-10-08T09:00:00+01:00']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Primary\Pickup;

use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupInfo;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupRegistry;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Messaging\Contracts\DirectionDirectory;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Billing\RecordingMessenger;
use Tests\Support\Primary\PrimaryTestCase;

/** T554 : sortie sécurisée désactivée par défaut, remise à une personne autorisée en vigueur seulement, refus signalé (NOT-PRI-07). */
final class PickupTest extends PrimaryTestCase
{
    public function testRemiseEtRefus(): void
    {
        $enrollments = new InMemoryEnrollments();
        $people = new InMemoryPeople();
        $messenger = new RecordingMessenger();
        $registry = new class () implements AuthorizedPickupRegistry {
            public function validOn(string $tenantId, string $establishmentId, array $learnerIds, string $date): array
            {
                return array_filter([$this->find($tenantId, $establishmentId, 'auth-1')], static fn (?AuthorizedPickupInfo $i): bool => $i !== null && in_array($i->learnerId, $learnerIds, true) && $i->isValidOn($date));
            }

            public function find(string $tenantId, string $establishmentId, string $id): ?AuthorizedPickupInfo
            {
                return match ($id) {
                    'auth-1' => new AuthorizedPickupInfo('auth-1', 'k1', 'Tante Fictive', 'tante', null, null, AuthorizedPickupInfo::PERMANENT, null, AuthorizedPickupInfo::ACTIVE),
                    'auth-old' => new AuthorizedPickupInfo('auth-old', 'k1', 'Ancien', 'voisin', null, null, AuthorizedPickupInfo::DAY, '2026-10-01', AuthorizedPickupInfo::ACTIVE),
                    default => null,
                };
            }

            public function grant(string $tenantId, string $establishmentId, string $learnerId, array $data, string $actorId): string
            {
                return 'x';
            }

            public function revoke(string $tenantId, string $establishmentId, string $id, string $actorId): void
            {
            }
        };
        $direction = new class () implements DirectionDirectory {
            public function directionPersonIds(string $tenantId, string $establishmentId): array
            {
                return ['dir-1'];
            }
        };
        $this->bootPrimary([EnrollmentDirectory::class => $enrollments, PersonDirectory::class => $people, Messenger::class => $messenger, AuthorizedPickupRegistry::class => $registry, DirectionDirectory::class => $direction]);
        $level = $this->makeLevel(self::TA, self::EA, 'CP', 1);
        $this->ok('POST', '/primary/level-profiles', ['level_structure_id' => $level['level'], 'sort_order' => 1]);
        $class = $level['classes'][0];
        $people->add(self::TA, 'k1', 'Enfant', 'Fictif');
        $people->add(self::TA, 'g1', 'Parent', 'Fictif');
        $people->link(self::TA, 'g1', 'k1');
        $enrollments->add(self::TA, 'en1', 'k1', self::EA, $class, self::YEAR);
        $this->assign(self::TA, 'agent', self::EA, 'primary.pickup.handover', Scope::ESTABLISHMENT);

        self::assertFalse($this->ok('GET', "/primary/classes/$class/pickups", null, 'agent')['enabled'], 'Désactivée par défaut (Q13).');
        self::assertSame(409, $this->call('POST', "/primary/classes/$class/pickups/handover", ['person_id' => 'k1', 'pickup_id' => 'auth-1'], 'agent')[0]);
        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, self::EA, 'primary.pickup.levels', [$level['level']], self::DIRECTOR);

        $day = $this->ok('GET', "/primary/classes/$class/pickups", null, 'agent');
        self::assertSame('Tante Fictive', $day['learners'][0]['authorised'][0]['name']);
        self::assertSame(422, $this->call('POST', "/primary/classes/$class/pickups/handover", ['person_id' => 'k1', 'pickup_id' => 'auth-old'], 'agent')[0], 'Autorisation d\'un autre jour.');
        self::assertSame(201, $this->call('POST', "/primary/classes/$class/pickups/handover", ['person_id' => 'k1', 'pickup_id' => 'auth-1'], 'agent')[0]);
        $refused = $this->ok('POST', "/primary/classes/$class/pickups/refusals", ['person_id' => 'k1', 'presented_name' => 'Inconnu'], 'agent');
        self::assertSame(2, $refused['notified']);
        self::assertSame(['g1'], array_column($messenger->of('pri.not07.pickup_refused.parent'), 'person'));
        self::assertSame(['dir-1'], array_column($messenger->of('pri.not07.pickup_refused.director'), 'person'));
        self::assertSame(['handed', 'refused'], array_column($this->ok('GET', "/primary/classes/$class/pickups", null, 'agent')['learners'][0]['events'], 'outcome'));
        self::assertSame(404, $this->call('POST', "/primary/classes/$class/pickups/handover", ['person_id' => 'inconnu', 'pickup_id' => 'auth-1'], 'agent')[0]);
        $this->grantAll(self::TB, 'directeur-b', self::EB);
        self::assertSame(404, $this->call('GET', "/primary/classes/$class/pickups", null, 'directeur-b', self::TB, self::EB)[0]);
    }
}

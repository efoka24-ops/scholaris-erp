<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Pickup;

use Tests\Support\Nursery\NurseryTestCase;

/** Décor commun de la sortie sécurisée : une école, deux sections, une éducatrice par section, une secrétaire, une directrice. */
abstract class PickupTestCase extends NurseryTestCase
{
    protected string $sectionA;
    protected string $sectionB;
    /** @var array{child: string, parent: string} */
    protected array $ada;
    /** @var array{child: string, parent: string} */
    protected array $bao;
    protected string $directorPerson = 'p-directrice';

    /** @param array<string, mixed> $overrides */
    protected function bootPickup(array $overrides = [], string $now = '2026-10-21T16:20:00+01:00'): void
    {
        $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = 'nursery-test-secret-key';
        $this->bootNursery($overrides, $now);
        $this->sectionA = (string) $this->ok('POST', '/nursery/sections', ['label' => 'Petite section (exemple)'])['id'];
        $this->sectionB = (string) $this->ok('POST', '/nursery/sections', ['label' => 'Moyenne section (exemple)'])['id'];
        $this->ada = $this->world->child(self::TA, self::EA, $this->sectionA, 'Ada');
        $this->bao = $this->world->child(self::TA, self::EA, $this->sectionB, 'Bao');
        $this->world->people->add(self::TA, $this->directorPerson, 'Dora', 'Directrice', null, '+237600000099');
        $this->world->roles->assign(self::TA, self::EA, 'directeur', $this->directorPerson);
        foreach (['nursery.pickup.handover', 'nursery.register.read', 'nursery.child.read', 'attendance.take'] as $permission) {
            $this->grant(self::TA, 'educatrice-a', self::EA, $permission, $this->sectionA);
            $this->grant(self::TA, 'educatrice-b', self::EA, $permission, $this->sectionB);
        }
        foreach (['nursery.pickup.manage', 'nursery.pickup.read', 'nursery.child.read'] as $permission) {
            $this->grant(self::TA, 'secretaire-a', self::EA, $permission);
        }
        $this->access->grant('parent-' . $this->ada['child'], self::EA);
        $this->access->grant('parent-' . $this->bao['child'], self::EA);
    }

    /** Autorisation créée par la secrétaire. */
    protected function authorize(string $childId, string $name = 'Tante Rose (exemple)', string $type = 'permanent', ?string $validOn = null): string
    {
        $body = ['learner_id' => $childId, 'name' => $name, 'relationship' => 'tante', 'phone' => '+237600000010', 'grant_type' => $type];
        if ($validOn !== null) {
            $body['valid_on'] = $validOn;
        }

        return (string) $this->ok('POST', '/nursery/pickup/authorized-persons', $body, 'secretaire-a')['id'];
    }

    /** @return list<array<string, mixed>> */
    protected function events(string $type): array
    {
        $statement = $this->service(\Scholaris\Core\Database\ConnectionFactory::class)->pdo()->prepare('SELECT * FROM nu_gate_events WHERE event_type = ? ORDER BY occurred_at, id');
        $statement->execute([$type]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}

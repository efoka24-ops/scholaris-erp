<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Development;

use Tests\Feature\Nursery\Pickup\PickupTestCase;

/** Décor du suivi du développement : école, deux sections, référentiel exemple copié et activé par la directrice, éducatrices et parents. */
abstract class DevelopmentTestCase extends PickupTestCase
{
    protected string $frameId;
    /** @var array<string, string> code d'item => identifiant */
    protected array $items = [];

    /** @param array<string, mixed> $overrides */
    protected function bootDevelopment(array $overrides = []): void
    {
        $this->bootPickup($overrides, '2026-10-21T10:00:00+01:00');
        foreach (['nursery.dev.observe', 'nursery.dev.read', 'nursery.lifebook.write', 'nursery.lifebook.read', 'nursery.need.report', 'nursery.need.read', 'nursery.allergy.read'] as $permission) {
            $this->grant(self::TA, 'educatrice-a', self::EA, $permission, $this->sectionA);
            $this->grant(self::TA, 'educatrice-b', self::EA, $permission, $this->sectionB);
        }
        $platform = $this->ok('GET', '/nursery/frames');
        $example = null;
        foreach ($platform as $frame) {
            if ($frame['scope'] === 'platform') {
                $example = $frame;
            }
        }
        self::assertNotNull($example);
        $copy = $this->ok('POST', '/nursery/frames/' . $example['id'] . '/copy', []);
        $this->ok('POST', '/nursery/frames/' . $copy['id'] . '/activate', []);
        $this->frameId = (string) $copy['id'];
        foreach ($this->ok('GET', '/nursery/frames/' . $this->frameId)['domains'] as $domain) {
            foreach ($domain['items'] as $item) {
                $this->items[(string) $item['code']] = (string) $item['id'];
            }
        }
    }
}

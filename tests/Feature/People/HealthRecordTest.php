<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Scholaris\Modules\Socle\People\Contracts\FoodAllergies;
use Tests\Support\People\PeopleTestCase;

/** Données de santé : chiffrées en base, visibles des seuls rôles autorisés. */
final class HealthRecordTest extends PeopleTestCase
{
    protected function setUp(): void
    {
        $this->bootPeople();
    }

    public function testLaValeurBruteNApparaitJamaisEnBase(): void
    {
        $person = $this->person('Yann', 'Foka');
        $path = '/people/' . $person['id'] . '/health';

        [$response, $body] = $this->api('PUT', $path, body: ['allergies' => 'arachides', 'doctor_name' => 'Dr Essomba', 'blood_group' => 'AB-']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('arachides', $body['data']['allergies']);

        $raw = $this->connections()->pdo()->query('SELECT * FROM health_records')->fetchAll();
        self::assertCount(1, $raw);
        $dump = json_encode($raw, JSON_THROW_ON_ERROR);
        foreach (['arachides', 'Essomba', 'AB-'] as $secret) {
            self::assertStringNotContainsString($secret, $dump);
        }
        self::assertSame('arachides', $this->api('GET', $path)[1]['data']['allergies']);

        $this->api('PUT', $path, body: ['notes' => 'asthme léger']);
        $merged = $this->api('GET', $path)[1]['data'];
        self::assertSame('asthme léger', $merged['notes']);
        self::assertSame('arachides', $merged['allergies'], 'Une mise à jour partielle conserve les autres champs.');
        self::assertSame('1', (string) $this->connections()->pdo()->query('SELECT COUNT(*) FROM health_records')->fetchColumn());
        self::assertStringNotContainsString('asthme', json_encode($this->connections()->pdo()->query('SELECT * FROM health_records')->fetchAll(), JSON_THROW_ON_ERROR));
    }

    public function testSeulsLesRolesAutorisesLisentLaSante(): void
    {
        $person = $this->person('Yann', 'Foka');
        $path = '/people/' . $person['id'] . '/health';
        $this->api('PUT', $path, body: ['allergies' => 'pollen']);
        $this->access->grant('secretaire', 'e1');
        $this->permissions->grant('secretaire', 'people.read', 'people.manage');

        self::assertSame(403, $this->code('GET', $path, 'secretaire'));
        self::assertSame(403, $this->code('PUT', $path, 'secretaire', body: ['allergies' => 'x']));
        self::assertSame(422, $this->code('PUT', $path, body: ['inconnu' => 'x']));
    }

    public function testLeContratAllergiesNeToucheQueLeChampAllergies(): void
    {
        $first = $this->person('Amina', 'Foka');
        $second = $this->person('Bilal', 'Foka');
        $allergies = $this->kernel->container()->get(FoodAllergies::class);

        $allergies->declare('t1', (string) $first['id'], 'arachides', 'admin');
        $allergies->declare('t1', (string) $second['id'], null, 'admin');

        self::assertSame([
            (string) $first['id'] => 'arachides',
            (string) $second['id'] => null,
        ], $allergies->forLearners('t1', [(string) $first['id'], (string) $second['id']]));

        $firstHealth = $this->api('GET', '/people/' . $first['id'] . '/health')[1]['data'];
        self::assertSame('arachides', $firstHealth['allergies']);
        self::assertSame(null, $this->api('GET', '/people/' . $second['id'] . '/health')[1]['data']['allergies']);
    }

    public function testSansCleDeChiffrementRienNEstEcritEnClair(): void
    {
        $cipher = new \Scholaris\Modules\Socle\People\Infrastructure\HealthCipher('');

        $this->expectException(\RuntimeException::class);
        $cipher->encrypt('secret');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Isolation;

use Tests\Support\Nursery\NurseryTestCase;

/**
 * Cloisonnement de la Maternelle privée (RG-MAT-4, RG-MAT-7) : par ressource, un identifiant d'un autre client, d'un autre
 * établissement ou d'une autre section répond 404 ; un parent ne voit que ses enfants. Le parcours est rejoué sur chaque module
 * livré (sections, enfants, appel, sortie, développement, cahier de vie, besoins, messages, frais).
 */
final class NurseryScopeTest extends NurseryTestCase
{
    /** @var array{a: string, b: string, other: string} */
    private array $sections;
    /** @var array{child: string, parent: string} */
    private array $adaA;

    protected function setUp(): void
    {
        $this->bootNursery();
        $this->sections = [
            'a' => (string) $this->ok('POST', '/nursery/sections', ['label' => 'Section A'])['id'],
            'b' => (string) $this->ok('POST', '/nursery/sections', ['label' => 'Section B'])['id'],
            'other' => (string) $this->ok('POST', '/nursery/sections', ['label' => 'Section autre client'], 'directrice-b-setup', self::TB, self::EB)['id'],
        ];
    }

    protected function bootNursery(array $overrides = [], string $now = '2026-10-21T08:00:00+01:00'): void
    {
        parent::bootNursery($overrides, $now);
        $this->grantAll(self::TB, 'directrice-b-setup', self::EB);
        $this->access->grant('directrice-b-setup', self::EB);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function readRoutes(): array
    {
        return [
            'enfants de la section' => ['GET', '/nursery/sections/{section}/children'],
            'fiche enfant' => ['GET', '/nursery/children/{child}'],
        ];
    }

    public function testUnAutreClientNAAccesAAucuneRessourceDuClientA(): void
    {
        $this->adaA = $this->world->child(self::TA, self::EA, $this->sections['a'], 'Ada');
        $this->grantAll(self::TB, 'directrice-b', self::EB);
        foreach (self::readRoutes() as [$method, $path]) {
            $concrete = str_replace(['{section}', '{child}'], [$this->sections['a'], $this->adaA['child']], $path);
            self::assertSame(404, $this->code($method, $concrete, null, 'directrice-b', self::TB, self::EB), $concrete);
        }
        self::assertSame(404, $this->code('PATCH', '/nursery/sections/' . $this->sections['a'], ['label' => 'Pirate'], 'directrice-b', self::TB, self::EB));
        self::assertSame('Section A', $this->ok('GET', '/nursery/sections')[0]['label']);
    }

    public function testUneEducatriceDUneAutreSectionRepond404EnLecture(): void
    {
        $this->adaA = $this->world->child(self::TA, self::EA, $this->sections['a'], 'Ada');
        $this->grant(self::TA, 'educatrice-b', self::EA, 'nursery.child.read', $this->sections['b']);
        foreach (self::readRoutes() as [$method, $path]) {
            $concrete = str_replace(['{section}', '{child}'], [$this->sections['a'], $this->adaA['child']], $path);
            self::assertSame(404, $this->code($method, $concrete, null, 'educatrice-b'), $concrete);
        }
    }

    public function testUnEtablissementDeLAutreClientEstRefuse(): void
    {
        $this->grantAll(self::TB, 'directrice-b', self::EB);
        foreach (['/nursery/sections', '/nursery/children', '/nursery/settings'] as $path) {
            self::assertContains($this->code('GET', $path, null, 'directrice-b', self::TB, self::EA), [403, 404], $path);
        }
    }

    public function testLeRegistreDeSectionsNeMelangePasLesClients(): void
    {
        $a = array_column($this->ok('GET', '/nursery/sections'), 'id');
        $b = array_column($this->ok('GET', '/nursery/sections', null, 'directrice-b-setup', self::TB, self::EB), 'id');
        self::assertSame([], array_intersect($a, $b));
        self::assertContains($this->sections['a'], $a);
    }
}

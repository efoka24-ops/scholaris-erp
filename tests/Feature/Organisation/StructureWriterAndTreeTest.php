<?php

declare(strict_types=1);

namespace Tests\Feature\Organisation;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureDraft;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter;
use Tests\Support\Organisation\OrganisationTestCase;

/** D1 (StructureWriter) et S15 (OrgUnitTree) : écoles, départements, filière > niveau > groupe, isolation. */
final class StructureWriterAndTreeTest extends OrganisationTestCase
{
    protected function setUp(): void
    {
        $this->bootOrganisation();
        $this->seedEstablishment('t1', 'e1', 'Institut', 'higher', 'alice');
        $this->seedEstablishment('t1', 'e2', 'Collège', 'secondary', 'alice');
        $this->seedEstablishment('t2', 'x1', 'Autre client', 'higher', 'bob');
        $this->grantAll('alice');
    }

    private function writer(): StructureWriter
    {
        return $this->kernel->container()->get(StructureWriter::class);
    }

    private function tree(): OrgUnitTree
    {
        return $this->kernel->container()->get(OrgUnitTree::class);
    }

    /** @return array<string, string> */
    private function ipes(): array
    {
        $w = $this->writer();
        $school = $w->createOrgUnit('t1', 'e1', 'school', 'École de commerce', null, 'alice');
        $dept = $w->createOrgUnit('t1', 'e1', 'department', 'Comptabilité', $school, 'alice');
        $other = $w->createOrgUnit('t1', 'e1', 'school', 'École d\'ingénieurs', null, 'alice');
        $track = $w->createStructure('t1', 'e1', new StructureDraft('track', 'Comptabilité et finance', $dept), 'alice');
        $level = $w->createStructure('t1', 'e1', new StructureDraft('level', 'Licence 1', $dept, $track), 'alice');
        $group = $w->createStructure('t1', 'e1', new StructureDraft('group', 'Groupe A', null, $level, 40), 'alice');
        $foreign = $w->createStructure('t1', 'e1', new StructureDraft('track', 'Génie logiciel', $other), 'alice');

        return compact('school', 'dept', 'other', 'track', 'level', 'group', 'foreign');
    }

    private function rejects(callable $call, int $status = 422): void
    {
        try {
            $call();
            self::fail('Exception attendue.');
        } catch (HttpException $e) {
            self::assertSame($status, $e->status);
        }
    }

    public function testCreationRetourneLIdEtSeVoitParLApi(): void
    {
        $ids = $this->ipes();

        self::assertSame(26, strlen($ids['group']));
        [, $body] = $this->api('GET', '/structures/' . $ids['group'], 'alice', 't1', 'e1');
        self::assertSame(['group', 'Groupe A', $ids['level'], 40], [$body['data']['kind'], $body['data']['name'], $body['data']['parent_id'], $body['data']['capacity']]);
        [, $unit] = $this->api('GET', '/org-units/' . $ids['dept'], 'alice', 't1', 'e1');
        self::assertSame(['department', $ids['school']], [$unit['data']['type'], $unit['data']['parent_id']]);
        $actions = $this->connections()->pdo()->query("SELECT action FROM audit_log WHERE action IN ('org_unit.created', 'structure.created')")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(7, $actions, 'Chaque écriture est journalisée.');
    }

    public function testModificationEtRefus(): void
    {
        $ids = $this->ipes();
        $w = $this->writer();
        $w->updateStructure('t1', 'e1', $ids['group'], ['name' => 'Groupe A (soir)', 'capacity' => 35], 'alice');
        $w->updateOrgUnit('t1', 'e1', $ids['dept'], ['name' => 'Comptabilité-Finance'], 'alice');
        self::assertSame('Groupe A (soir)', $this->tree()->structure('t1', 'e1', $ids['group'])?->name);

        $this->rejects(fn () => $w->updateStructure('t1', 'e1', $ids['track'], ['parent_id' => $ids['group']]), 422);
        $this->rejects(fn () => $w->updateOrgUnit('t1', 'e1', $ids['school'], ['parent_id' => $ids['dept']]), 422);
        $this->rejects(fn () => $w->updateStructure('t1', 'e1', $ids['track'], ['kind' => 'level']), 422);
        $this->rejects(fn () => $w->createStructure('t1', 'e2', new StructureDraft('track', 'Filière')), 422);
        $this->rejects(fn () => $w->createStructure('t1', 'e1', new StructureDraft('level', 'L2', 'inconnue')), 422);
        $this->rejects(fn () => $w->createStructure('t1', 'e1', new StructureDraft('mystere', 'X')), 422);
        $this->rejects(fn () => $w->createStructure('t1', 'e2', new StructureDraft('level', 'L2', null, $ids['track'])), 422);
        $this->rejects(fn () => $w->updateStructure('t1', 'e2', $ids['track'], ['name' => 'X']), 404);
        $this->rejects(fn () => $w->createOrgUnit('t2', 'e1', 'school', 'Pirate'), 404);
    }

    public function testArbreDeveloppeUnitesEtStructures(): void
    {
        $ids = $this->ipes();
        $tree = $this->tree();

        self::assertSame([$ids['school'], $ids['dept']], $tree->descendantsOf('t1', 'e1', $ids['school']));
        $structures = $tree->structuresOf('t1', 'e1', $ids['school']);
        sort($structures);
        $expected = [$ids['track'], $ids['level'], $ids['group']];
        sort($expected);
        self::assertSame($expected, $structures, 'La filière du département, ses niveaux et groupes ; pas la filière de l\'autre école.');
        self::assertSame([$ids['track'], $ids['level'], $ids['group']], $tree->structureDescendantsOf('t1', 'e1', $ids['track']));
        self::assertSame([$ids['school']], $tree->ancestorsOf('t1', 'e1', $ids['dept']));
        self::assertSame($ids['track'], $tree->trackOf('t1', 'e1', $ids['group']));
        self::assertSame($ids['level'], $tree->levelOf('t1', 'e1', $ids['group']));
        self::assertNull($tree->levelOf('t1', 'e1', $ids['track']));
    }

    public function testArbreCloisonneParClientEtEtablissement(): void
    {
        $ids = $this->ipes();
        $tree = $this->tree();

        self::assertSame([], $tree->descendantsOf('t2', 'e1', $ids['school']));
        self::assertSame([], $tree->structuresOf('t1', 'e2', $ids['school']));
        self::assertSame([], $tree->structureDescendantsOf('t2', 'x1', $ids['track']));
        self::assertNull($tree->structure('t2', 'e1', $ids['track']));
        self::assertNull($tree->trackOf('t1', 'e2', $ids['group']));
    }

    public function testParentDeStructureParLApiEtCompatibiliteSansParent(): void
    {
        $ids = $this->ipes();
        [$response, $body] = $this->api('POST', '/structures', 'alice', 't1', 'e1', ['kind' => 'group', 'name' => 'Groupe B', 'parent_id' => $ids['level']]);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame($ids['level'], $body['data']['parent_id']);
        self::assertSame(422, $this->code('POST', '/structures', 'alice', 't1', 'e1', ['kind' => 'group', 'name' => 'Groupe C', 'parent_id' => 'inconnue']));
        self::assertSame(422, $this->code('PATCH', '/structures/' . $ids['track'], 'alice', 't1', 'e1', ['parent_id' => $ids['group']]), 'Cycle refusé.');

        [, $classic] = $this->api('POST', '/structures', 'alice', 't1', 'e2', ['kind' => 'class', 'name' => '6e A', 'capacity' => 50]);
        self::assertNull($classic['data']['parent_id'], 'Secondaire : aucune hiérarchie, comportement inchangé.');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Sections;

use Tests\Support\Nursery\NurseryTestCase;

/** EX-MAT-04 : sections créées dans le socle, libellés de l'école, effectif, périmètre de section. */
final class SectionsTest extends NurseryTestCase
{
    protected function setUp(): void
    {
        $this->bootNursery();
    }

    public function testLaDirectriceCreeUneSectionQuiApparaitAvecSonEffectif(): void
    {
        $created = $this->ok('POST', '/nursery/sections', ['label' => 'Section des Libellules (exemple)', 'capacity' => 20, 'sort_order' => 2, 'subsystem' => 'both']);
        self::assertSame('Section des Libellules (exemple)', $created['label']);
        self::assertSame(20, $created['capacity']);
        self::assertSame(0, $created['enrolled']);
        $this->world->child(self::TA, self::EA, (string) $created['id'], 'Ada');

        $rows = $this->ok('GET', '/nursery/sections');
        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['enrolled']);
        self::assertSame('both', $rows[0]['subsystem']);
    }

    public function testModificationDuLibelleEtDeLEffectifDansLeSocle(): void
    {
        $section = $this->ok('POST', '/nursery/sections', ['label' => 'Petite', 'capacity' => 15]);
        $updated = $this->ok('PATCH', '/nursery/sections/' . $section['id'], ['label' => 'Petite section', 'capacity' => 18, 'sort_order' => 1]);
        self::assertSame('Petite section', $updated['label']);
        self::assertSame(18, $updated['capacity']);
        self::assertSame(422, $this->code('PATCH', '/nursery/sections/' . $section['id'], ['capacity' => 0]));
        self::assertSame(422, $this->code('PATCH', '/nursery/sections/' . $section['id'], ['inconnu' => 1]));
    }

    public function testUneClasseExistanteDuSocleEstAdopteeUneSeuleFois(): void
    {
        $class = $this->makeSection(self::TA, self::EA, 'Grande');
        $adopted = $this->ok('POST', '/nursery/sections', ['label' => 'Grande section', 'structure_id' => $class]);
        self::assertSame($class, $adopted['id']);
        self::assertSame(409, $this->code('POST', '/nursery/sections', ['label' => 'Encore', 'structure_id' => $class]));
        self::assertSame(422, $this->code('POST', '/nursery/sections', ['label' => 'X', 'structure_id' => 'inconnue']));
    }

    public function testLaListeEstPagineeParCurseur(): void
    {
        foreach (['A', 'B', 'C'] as $i => $name) {
            $this->ok('POST', '/nursery/sections', ['label' => 'Section ' . $name, 'sort_order' => $i]);
        }
        [, $first] = $this->call('GET', '/nursery/sections?per_page=2');
        self::assertCount(2, $first['data']);
        self::assertIsString($first['meta']['next_cursor']);
        [, $second] = $this->call('GET', '/nursery/sections?per_page=2&cursor=' . rawurlencode($first['meta']['next_cursor']));
        self::assertCount(1, $second['data']);
        self::assertNull($second['meta']['next_cursor']);
        self::assertSame(422, $this->code('GET', '/nursery/sections?per_page=201'));
    }

    public function testLEducatriceNeVoitQueSaSectionEtUneAutreSectionRepond404(): void
    {
        $a = (string) $this->ok('POST', '/nursery/sections', ['label' => 'Petite'])['id'];
        $b = (string) $this->ok('POST', '/nursery/sections', ['label' => 'Moyenne'])['id'];
        $this->world->child(self::TA, self::EA, $a, 'Ada');
        $this->world->child(self::TA, self::EA, $b, 'Bao');
        $this->grant(self::TA, 'educatrice-a', self::EA, 'nursery.child.read', $a);

        self::assertSame([$a], array_column($this->ok('GET', '/nursery/sections', null, 'educatrice-a'), 'id'));
        self::assertSame(200, $this->code('GET', '/nursery/sections/' . $a . '/children', null, 'educatrice-a'));
        self::assertSame(404, $this->code('GET', '/nursery/sections/' . $b . '/children', null, 'educatrice-a'));
        self::assertSame(['Ada'], array_column($this->ok('GET', '/nursery/sections/' . $a . '/children', null, 'educatrice-a'), 'first_name'));
    }

    public function testSeuleLaDirectriceDeTouteLEtablissementCreeOuModifie(): void
    {
        $this->grant(self::TA, 'secretaire-a', self::EA, 'nursery.child.read');
        self::assertSame(403, $this->code('POST', '/nursery/sections', ['label' => 'X'], 'secretaire-a'));
        $this->grant(self::TA, 'directrice-section', self::EA, 'nursery.section.manage', 'une-section');
        self::assertSame(403, $this->code('POST', '/nursery/sections', ['label' => 'X'], 'directrice-section'));
        self::assertContains('nursery.scope.denied', $this->audit->actions());
        self::assertSame(422, $this->code('POST', '/nursery/sections', []));
    }

    public function testUnAutreClientNeVoitNiNeModifieLesSections(): void
    {
        $section = (string) $this->ok('POST', '/nursery/sections', ['label' => 'Petite'])['id'];
        $this->grantAll(self::TB, 'directrice-b', self::EB);
        self::assertSame([], $this->ok('GET', '/nursery/sections', null, 'directrice-b', self::TB, self::EB));
        self::assertSame(404, $this->code('PATCH', '/nursery/sections/' . $section, ['label' => 'Pirate'], 'directrice-b', self::TB, self::EB));
        self::assertSame(404, $this->code('GET', '/nursery/sections/' . $section . '/children', null, 'directrice-b', self::TB, self::EB));
        // L'établissement du client A n'est pas accessible à la directrice de B.
        self::assertContains($this->code('GET', '/nursery/sections', null, 'directrice-b', self::TB, self::EA), [403, 404]);
    }

    public function testUnEtablissementQuiNEstPasUneMaterneleRepond404(): void
    {
        $this->seedEstablishment(self::TA, 'e-lycee', 'Lycée', 'secondary');
        $this->grant(self::TA, self::DIRECTOR, 'e-lycee', 'nursery.child.read');
        self::assertSame(404, $this->code('GET', '/nursery/sections', null, self::DIRECTOR, self::TA, 'e-lycee'));
    }
}

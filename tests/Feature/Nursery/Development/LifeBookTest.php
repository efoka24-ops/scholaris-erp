<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Development;

use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;

/** EX-MAT-18 (US12), RG-MAT-6, SC-MAT-4 : une photo n'est jamais servie à un parent sans consentement en vigueur de CHAQUE enfant visible, à chaque lecture. */
final class LifeBookTest extends DevelopmentTestCase
{
    private string $parent;
    /** @var array{child: string, parent: string} */
    private array $cleo;

    protected function setUp(): void
    {
        $this->bootDevelopment();
        $this->parent = 'parent-' . $this->ada['child'];
        $this->cleo = $this->world->child(self::TA, self::EA, $this->sectionA, 'Cleo');
    }

    /** @return array<string, mixed> */
    private function entry(): array
    {
        return $this->ok('POST', '/nursery/lifebook/entries', ['section_id' => $this->sectionA, 'title' => 'Sortie au jardin', 'body' => 'Les enfants ont planté des graines.'], 'educatrice-a');
    }

    /** @param list<string> $children */
    private function addPhoto(string $entryId, array $children = [], bool $noChild = false): string
    {
        $entry = $this->ok('POST', '/nursery/lifebook/entries/' . $entryId . '/media', ['content_base64' => base64_encode('jpeg-fictif'), 'children' => $children, 'no_child_visible' => $noChild], 'educatrice-a');

        return (string) end($entry['media'])['id'];
    }

    private function consent(string $childId): string
    {
        return (string) $this->world->people->grant(self::TA, $childId, ConsentChecker::PHOTO, 'parent')['id'];
    }

    /** @return array<string, mixed> */
    private function parentView(): array
    {
        return $this->ok('GET', '/nursery/portal/lifebook?learner_id=' . $this->ada['child'], null, $this->parent)[0] ?? [];
    }

    public function testUnePhotoEtiqueteeNEstServieQuApresLeConsentementDeTousLesEnfantsVisibles(): void
    {
        $entry = $this->entry();
        $media = $this->addPhoto((string) $entry['id'], [$this->ada['child'], $this->cleo['child']]);
        $this->ok('POST', '/nursery/lifebook/entries/' . $entry['id'] . '/publish', [], 'educatrice-a');

        $view = $this->parentView();
        self::assertSame('Sortie au jardin', $view['title']);
        self::assertSame('Les enfants ont planté des graines.', $view['body']);
        self::assertSame([], $view['media']);
        self::assertSame(1, $view['withheld_media']);
        self::assertSame(404, $this->code('GET', '/nursery/portal/lifebook/media/' . $media, null, $this->parent));

        $adaConsent = $this->consent($this->ada['child']);
        self::assertSame([], $this->parentView()['media'], 'Un seul consentement ne suffit pas : Cleo figure aussi.');
        self::assertSame(404, $this->code('GET', '/nursery/portal/lifebook/media/' . $media, null, $this->parent));

        $cleoConsent = $this->consent($this->cleo['child']);
        self::assertSame([$media], array_column($this->parentView()['media'], 'id'));
        $response = $this->raw('GET', '/nursery/portal/lifebook/media/' . $media, null, $this->parent);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/jpeg', explode(';', $response->getHeaderLine('Content-Type'))[0]);
        self::assertSame('jpeg-fictif', (string) $response->getBody());
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));

        // Retrait du consentement : la photo disparaît à la lecture suivante, sur tous les chemins de lecture.
        $this->world->people->revoke(self::TA, $cleoConsent, 'parent');
        self::assertSame([], $this->parentView()['media']);
        self::assertSame(404, $this->code('GET', '/nursery/portal/lifebook/media/' . $media, null, $this->parent));
        self::assertNotSame('', $adaConsent);
    }

    public function testUnePhotoSansEnfantVisibleEstToujoursServieMaisUnePhotoNonEtiqueteeNeLEstJamais(): void
    {
        $entry = $this->entry();
        $landscape = $this->addPhoto((string) $entry['id'], [], true);
        $untagged = $this->addPhoto((string) $entry['id']);
        $this->consent($this->ada['child']);
        $this->consent($this->cleo['child']);
        $this->ok('POST', '/nursery/lifebook/entries/' . $entry['id'] . '/publish', [], 'educatrice-a');

        $view = $this->parentView();
        self::assertSame([$landscape], array_column($view['media'], 'id'));
        self::assertSame(0, $view['withheld_media'], 'La photo non étiquetée n’est pas publiée : elle n’est pas comptée.');
        self::assertSame(404, $this->code('GET', '/nursery/portal/lifebook/media/' . $untagged, null, $this->parent), 'Non étiquetée : jamais publiée.');
        self::assertSame(200, $this->code('GET', '/nursery/portal/lifebook/media/' . $landscape, null, $this->parent));
    }

    public function testUnBrouillonOuUneEntreeRetireeEstInvisibleDuParent(): void
    {
        $entry = $this->entry();
        $media = $this->addPhoto((string) $entry['id'], [], true);
        self::assertSame([], $this->ok('GET', '/nursery/portal/lifebook?learner_id=' . $this->ada['child'], null, $this->parent));
        self::assertSame(404, $this->code('GET', '/nursery/portal/lifebook/media/' . $media, null, $this->parent));
        $this->ok('POST', '/nursery/lifebook/entries/' . $entry['id'] . '/publish', [], 'educatrice-a');
        self::assertCount(1, $this->ok('GET', '/nursery/portal/lifebook?learner_id=' . $this->ada['child'], null, $this->parent));
        self::assertSame(409, $this->code('PATCH', '/nursery/lifebook/entries/' . $entry['id'], ['title' => 'Autre'], 'educatrice-a'), 'Retirez la publication avant de la modifier.');
        $this->ok('POST', '/nursery/lifebook/entries/' . $entry['id'] . '/withdraw', [], 'educatrice-a');
        self::assertSame([], $this->ok('GET', '/nursery/portal/lifebook?learner_id=' . $this->ada['child'], null, $this->parent));
        self::assertSame(404, $this->code('GET', '/nursery/portal/lifebook/media/' . $media, null, $this->parent));
    }

    public function testLEtiquetageCorrigeUnePhotoEnAttente(): void
    {
        $entry = $this->entry();
        $media = $this->addPhoto((string) $entry['id']);
        $tagged = $this->ok('POST', '/nursery/lifebook/media/' . $media . '/tag', ['children' => [$this->ada['child']]], 'educatrice-a');
        self::assertSame('ready', $tagged['media'][0]['status']);
        self::assertSame([$this->ada['child']], $tagged['media'][0]['children']);
        self::assertSame(422, $this->code('POST', '/nursery/lifebook/media/' . $media . '/tag', ['children' => [$this->bao['child']]], 'educatrice-a'), 'Un enfant d’une autre section.');
        self::assertSame(422, $this->code('POST', '/nursery/lifebook/media/' . $media . '/tag', ['children' => [$this->ada['child']], 'no_child_visible' => true], 'educatrice-a'));
        self::assertSame(422, $this->code('POST', '/nursery/lifebook/entries/' . $entry['id'] . '/media', ['content_base64' => '***'], 'educatrice-a'));
    }

    public function testLeParentDUneAutreSectionNeVoitNiLEntreeNiLeMedia(): void
    {
        $entry = $this->entry();
        $media = $this->addPhoto((string) $entry['id'], [], true);
        $this->ok('POST', '/nursery/lifebook/entries/' . $entry['id'] . '/publish', [], 'educatrice-a');
        $other = 'parent-' . $this->bao['child'];
        self::assertSame([], $this->ok('GET', '/nursery/portal/lifebook?learner_id=' . $this->bao['child'], null, $other), 'Il voit le cahier de SA section, pas celui de la section A.');
        self::assertSame(404, $this->code('GET', '/nursery/portal/lifebook/media/' . $media, null, $other));
        self::assertSame(404, $this->code('GET', '/nursery/portal/lifebook?learner_id=' . $this->ada['child'], null, $other));
    }

    public function testLePersonnelNeLitQueLesPublicationsDeSaSectionEtLaPublicationNotifieLesParentsUneFois(): void
    {
        $entry = $this->entry();
        $media = $this->addPhoto((string) $entry['id'], [$this->ada['child']]);
        self::assertSame(200, $this->code('GET', '/nursery/lifebook/media/' . $media, null, 'educatrice-a'), 'Le personnel voit la photo, consentement ou non.');
        self::assertSame(404, $this->code('GET', '/nursery/lifebook/media/' . $media, null, 'educatrice-b'));
        self::assertSame(403, $this->code('POST', '/nursery/lifebook/entries/' . $entry['id'] . '/publish', [], 'educatrice-b'));
        self::assertCount(1, $this->ok('GET', '/nursery/lifebook/entries', null, 'educatrice-a'));
        self::assertCount(0, $this->ok('GET', '/nursery/lifebook/entries', null, 'educatrice-b'));
        self::assertSame(404, $this->code('GET', '/nursery/lifebook/entries?section_id=' . $this->sectionA, null, 'educatrice-b'));

        $this->ok('POST', '/nursery/lifebook/entries/' . $entry['id'] . '/publish', [], 'educatrice-a');
        self::assertEqualsCanonicalizing([$this->ada['parent'], $this->cleo['parent']], $this->messenger->recipients());
        self::assertSame(409, $this->code('POST', '/nursery/lifebook/entries/' . $entry['id'] . '/publish', [], 'educatrice-a'));
    }

    public function testUnAutreClientNeVoitAucuneEntreeNiMedia(): void
    {
        $entry = $this->entry();
        $media = $this->addPhoto((string) $entry['id'], [], true);
        $this->grantAll(self::TB, 'directrice-b', self::EB);
        $this->grant(self::TB, 'directrice-b', self::EB, 'nursery.lifebook.write');
        self::assertSame(404, $this->code('GET', '/nursery/lifebook/media/' . $media, null, 'directrice-b', self::TB, self::EB));
        self::assertSame(404, $this->code('POST', '/nursery/lifebook/entries/' . $entry['id'] . '/publish', [], 'directrice-b', self::TB, self::EB));
        self::assertSame(404, $this->code('POST', '/nursery/lifebook/entries', ['section_id' => $this->sectionA, 'title' => 'Pirate'], 'directrice-b', self::TB, self::EB));
    }
}

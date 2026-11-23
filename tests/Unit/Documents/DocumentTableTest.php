<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Documents\Domain\DocumentTable;
use Scholaris\Modules\Socle\Documents\Domain\TemplateGuard;
use Scholaris\Modules\Socle\Documents\Domain\TemplateRenderer;

/** Tableaux structurés des documents (DOC-BUL) : HTML produit par le moteur, chaque cellule échappée, garde stricte. */
final class DocumentTableTest extends TestCase
{
    public function testLeMoteurProduitUnTableauAvecGroupesTotauxEtFusions(): void
    {
        $json = DocumentTable::encode([
            'columns' => [['label' => 'Matières', 'width' => 40], ['label' => 'Coef', 'align' => 'center'], ['label' => 'Moy', 'align' => 'right']],
            'rows' => [
                ['kind' => 'group', 'cells' => [['text' => 'Groupe 1', 'colspan' => 3]]],
                ['Français', 4, '12,50'],
                ['kind' => 'total', 'cells' => ['TOTAL 1', 4, ['text' => '12,50', 'bold' => true]]],
            ],
        ]);
        $html = TemplateRenderer::render('<div>{{table:subjects}}</div>', ['subjects' => $json]);

        self::assertStringContainsString('<table class="doc-table" cellspacing="0"><colgroup><col width="40%"><col><col></colgroup>', $html);
        self::assertStringContainsString('<thead><tr><th>Matières</th><th class="doc-center">Coef</th><th class="doc-right">Moy</th></tr></thead>', $html);
        self::assertStringContainsString('<tr class="doc-row-group"><td colspan="3">Groupe 1</td></tr>', $html);
        self::assertStringContainsString('<tr><td>Français</td><td class="doc-center">4</td><td class="doc-right">12,50</td></tr>', $html);
        self::assertStringContainsString('<tr class="doc-row-total"><td>TOTAL 1</td><td class="doc-center">4</td><td class="doc-right doc-bold">12,50</td></tr>', $html);
    }

    public function testToutesLesCellulesSontEchappeesAucunHtmlDeLApplication(): void
    {
        $json = DocumentTable::encode([
            'head' => [[['text' => '<script>alert(1)</script>', 'colspan' => 2]]],
            'rows' => [['<img src=x onerror=alert(1)>', "Passable\naverage"], ['kind' => 'summary', 'cells' => ['"><b>gras</b>', '&amp;']]],
            'foot' => [['<a href="javascript:x">lien</a>', '']],
        ]);
        $html = DocumentTable::html($json);

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('<a ', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('Passable<br>' . "\n" . 'average', $html);
        self::assertStringContainsString('&amp;amp;', $html);
        preg_match_all('#</?([a-z0-9]+)#i', $html, $tags);
        self::assertSame([], array_values(array_diff(array_unique($tags[1]), ['table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'br'])), 'Seules les balises du moteur.');
    }

    public function testUnAncienBlocDeTexteResteAfficheEchappe(): void
    {
        $html = TemplateRenderer::render('{{table:block}}|{{block}}', ['block' => "Lecture : A\n<i>Calcul</i> : B"]);

        self::assertSame('<div class="doc-table-text">Lecture : A<br>' . "\n" . '&lt;i&gt;Calcul&lt;/i&gt; : B</div>|Lecture : A' . "\n" . '&lt;i&gt;Calcul&lt;/i&gt; : B', $html);
        self::assertSame('', TemplateRenderer::render('{{table:absent}}', []));
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function malformed(): iterable
    {
        yield 'genre inconnu' => [['rows' => [['kind' => 'evil', 'cells' => ['a']]]]];
        yield 'alignement libre' => [['rows' => [[['text' => 'a', 'align' => 'left;color:red']]]]];
        yield 'fusion hors bornes' => [['rows' => [[['text' => 'a', 'colspan' => 999]]]]];
        yield 'cellule objet' => [['rows' => [[['text' => ['x']]]]]];
        yield 'classe inconnue' => [['class' => 'x" onclick="y', 'rows' => []]];
        yield 'largeur texte' => [['columns' => [['label' => 'a', 'width' => '50%']], 'rows' => []]];
    }

    /**
     * @dataProvider malformed
     * @param array<string, mixed> $table
     */
    public function testUnTableauMalFormeEstRefuse(array $table): void
    {
        $this->expectException(HttpException::class);
        DocumentTable::html(DocumentTable::encode($table));
    }

    public function testLaGardeNAccepteQueDesTableauxDeVariablesDeclarees(): void
    {
        TemplateGuard::assertValid('<p>{{number}}</p>{{table:subjects}}', ['subjects']);

        foreach (['{{table:inconnu}}', '{{table:qr}}', '{{table:}}'] as $html) {
            try {
                TemplateGuard::assertValid($html, ['subjects']);
                self::fail('Refus attendu : ' . $html);
            } catch (HttpException $e) {
                self::assertSame(422, $e->status);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Tests;

use Scholaris\Tenant\Features;
use Scholaris\View\Chart;
use Scholaris\View\Navigation;

/**
 * Navigation et graphiques.
 *
 * Deux familles de defauts que rien d'autre n'attrape : une entree de menu qui
 * pointe vers une route inexistante — on ne le decouvre qu'en cliquant — et un
 * graphique qui ment, ce qui ne se voit jamais.
 */
final class InterfaceTest extends TestCase
{
    private function features(string $type = 'COLLEGE'): Features
    {
        $matrix = require $this->basePath().'/database/feature-matrix.php';

        return new Features($matrix, $type, []);
    }

    public function testChaqueEntreeDeMenuPointeVersUneRouteExistante(): void
    {
        // Une entree vers une route inexistante ne se decouvre qu'en cliquant,
        // et donne un 404 a un utilisateur qui n'a rien fait de mal.
        $paths = [];

        foreach (Navigation::platform() as $section) {
            foreach ($section['items'] as $item) {
                $paths[] = $item['href'];
            }
        }

        foreach (Navigation::school($this->features()) as $section) {
            foreach ($section['items'] as $item) {
                $paths[] = $item['href'];
            }
        }

        $routes = file_get_contents($this->basePath().'/routes.php');
        $missing = [];

        foreach (array_unique($paths) as $path) {
            if (! str_contains((string) $routes, "'".$path."'")) {
                $missing[] = $path;
            }
        }

        $this->assertSame([], $missing, 'Entrees de menu sans route : '.implode(', ', $missing));
    }

    public function testChaqueEntreeDeMenuPorteUneIcone(): void
    {
        // Une icone absente laisserait un trou dans la colonne et decalerait
        // les libelles les uns par rapport aux autres.
        foreach (Navigation::school($this->features()) as $section) {
            foreach ($section['items'] as $item) {
                $svg = Navigation::icon($item['icon']);

                $this->assertTrue(
                    str_starts_with($svg, '<svg') && str_contains($svg, '<path') || str_contains($svg, '<circle'),
                    'L entree '.$item['label'].' doit porter une icone tracee'
                );
            }
        }
    }

    public function testLaRubriqueSuitLeTypeDEtablissement(): void
    {
        // Un centre de formation parle d'apprenants : conserver « Eleves »
        // partout sonnerait faux pour ses utilisateurs.
        $labels = [];

        foreach (Navigation::school($this->features('CENTRE_FORMATION')) as $section) {
            foreach ($section['items'] as $item) {
                $labels[] = $item['label'];
            }
        }

        $joined = implode(' | ', $labels);

        $this->assertTrue(
            str_contains($joined, 'Apprenants'),
            'Le menu d un centre de formation doit parler d apprenants'
        );
    }

    public function testUneRacineNeResteActiveQueSurElleMeme(): void
    {
        // Sans cette regle, « Accueil » resterait allume sur toutes les pages
        // en meme temps que la rubrique reellement ouverte.
        $this->assertTrue(Navigation::isActive('/dashboard', '/dashboard'), 'La racine s allume sur elle-meme');
        $this->assertTrue(! Navigation::isActive('/dashboard', '/students'), 'Mais pas ailleurs');
        $this->assertTrue(! Navigation::isActive('/admin', '/admin/parc'), 'Ni sur ses sous-pages');

        // Un prefixe ne doit pas allumer une rubrique voisine.
        $this->assertTrue(Navigation::isActive('/finance', '/finance/invoices'), 'Une rubrique suit ses sous-pages');
        $this->assertTrue(! Navigation::isActive('/finance', '/finances-autre'), 'Sans deborder sur un chemin voisin');
    }

    public function testLeFilDArianeNommeLaPageLaPlusPrecise(): void
    {
        $sections = Navigation::school($this->features());

        $this->assertSame(
            'Factures',
            Navigation::currentLabel($sections, '/finance/invoices', 'Accueil'),
            'La sous-rubrique prime sur la rubrique qui la contient'
        );
    }

    public function testLesInitialesEtLaCouleurSontStables(): void
    {
        // La couleur d'une personne doit rester la meme d'un ecran a l'autre,
        // sans quoi elle ne sert plus de repere.
        $this->assertSame('NM', Navigation::initials('Ngono Marie-Claire'), 'Deux initiales');
        $this->assertSame('?', Navigation::initials('  '), 'Un nom vide ne casse pas l affichage');

        $this->assertSame(
            Navigation::colorIndex('Ngono Marie-Claire'),
            Navigation::colorIndex('Ngono Marie-Claire'),
            'La couleur derive du nom, elle ne varie pas'
        );
        $this->assertTrue(Navigation::colorIndex('Test') < 7, 'Et reste dans la palette');
    }

    // --- Graphiques -----------------------------------------------------------

    public function testUneCourbeVideNAffichePasUneChuteAZero(): void
    {
        // Un trace plat au ras de l'axe se lit comme un effondrement. Mieux
        // vaut dire qu'il n'y a rien a montrer.
        $svg = Chart::line([]);

        $this->assertStringContains('Pas encore assez de donnees', $svg, 'Le vide est annonce');
        $this->assertTrue(! str_contains($svg, 'chart__line'), 'Et aucune ligne n est tracee');
    }

    public function testLAxeDesOrdonneesPartDeZero(): void
    {
        // Tronquer l'axe exagere visuellement des variations minimes : c'est
        // la maniere la plus courante de mentir avec un graphique honnete.
        $svg = Chart::line([
            ['label' => 'Jan', 'value' => 1000],
            ['label' => 'Fev', 'value' => 1010],
        ]);

        $this->assertStringContains('>0<', $svg, 'La graduation basse vaut zero');
    }

    public function testUnAnneauSansDonneeLeDitPlutotQueDeSeTracerVide(): void
    {
        $svg = Chart::donut([
            ['label' => 'Bien', 'value' => 0, 'color' => '#10b981'],
        ]);

        $this->assertStringContains('Aucune donnee a repartir', $svg, 'Le vide est annonce');
    }

    public function testLesPartsDeLAnneauCouvrentExactementLeCercle(): void
    {
        // Des parts qui ne bouclent pas laisseraient un vide, lu comme une
        // categorie oubliee.
        $slices = [
            ['label' => 'A', 'value' => 30, 'color' => '#10b981'],
            ['label' => 'B', 'value' => 70, 'color' => '#f59e0b'],
        ];

        $svg = Chart::donut($slices, 240);

        preg_match_all('/stroke-dasharray="([0-9.]+) /', $svg, $matches);

        $sum = array_sum(array_map('floatval', $matches[1]));
        $circumference = 2 * M_PI * (240 * 0.36);

        $this->assertTrue(
            abs($sum - $circumference) < 1.0,
            'La somme des parts doit couvrir le cercle entier'
        );
    }

    public function testLaLegendeAccompagneChaquePart(): void
    {
        $html = Chart::legend([
            ['label' => 'Excellent', 'value' => 312, 'color' => '#7c3aed'],
        ]);

        $this->assertStringContains('Excellent', $html, 'Le libelle figure');
        $this->assertStringContains('312', $html, 'Ainsi que l effectif');
    }

    public function testUnLibelleHostileEstEchappe(): void
    {
        // Les libelles peuvent venir de la base : un nom de classe malicieux
        // ne doit pas s executer dans le SVG.
        $svg = Chart::line([
            ['label' => '<script>x</script>', 'value' => 1],
            ['label' => 'B', 'value' => 2],
        ]);

        $this->assertTrue(! str_contains($svg, '<script>'), 'Aucune balise ne doit passer telle quelle');
    }

    // --- Rendu ----------------------------------------------------------------

    public function testLeTableauDeBordDeDirectionAfficheSesGraphiques(): void
    {
        $userId = $this->createUser($this->tenantA, 'directeur@a.cm');
        $this->giveRole($userId, 'DIRECTEUR', [
            'students:read', 'finance-dashboard:read', 'grades:publish', 'grades:read',
        ]);
        $this->actingAs($userId);

        $content = $this->request('GET', '/dashboard')->content();

        $this->assertStringContains('Evolution des effectifs', $content, 'La courbe est presente');
        $this->assertStringContains('Distribution par mention', $content, 'L anneau aussi');
        $this->assertStringContains('hero__chip', $content, 'La banniere resume la journee');
    }

    public function testLaNavigationSAfficheAvecSesRubriques(): void
    {
        $userId = $this->createUser($this->tenantA, 'directeur@a.cm');
        $this->giveRole($userId, 'DIRECTEUR', ['students:read', 'grades:read']);
        $this->actingAs($userId);

        $content = $this->request('GET', '/dashboard')->content();

        $this->assertStringContains('sidebar__section', $content, 'Les rubriques sont regroupees');
        $this->assertStringContains('Academique', $content, 'Avec leurs intertitres');
        $this->assertStringContains('topbar__crumb', $content, 'Le fil d Ariane situe la page');
    }

    public function testUnePageIntrouvableExpliqueEtLaisseUneSortie(): void
    {
        // Un « 404 » sec ne dit rien : l'adresse est-elle fausse, le module
        // absent de l'etablissement, ou l'application cassee ? L'utilisateur
        // ne peut pas les distinguer, et n'a rien a faire.
        $userId = $this->createUser($this->tenantA, 'quelquun@a.cm');
        $this->giveRole($userId, 'AUCUN', []);
        $this->actingAs($userId);

        $response = $this->request('GET', '/patrimoine');
        $content = $response->content();

        $this->assertSame(404, $response->status(), 'Un module non active reste introuvable');
        $this->assertStringContains('Page introuvable', $content, 'La situation est nommee');
        $this->assertStringContains('n est pas active', $content, 'Et la cause probable expliquee');
        $this->assertStringContains('/dashboard', $content, 'Une sortie est proposee');
    }

    public function testUneVariableDeGabaritNEstJamaisEcraseeParLeMoteur(): void
    {
        // extract() en mode EXTR_SKIP refuse d'ecraser une variable existante.
        // Une donnee portant le nom d'un local du moteur etait donc ignoree
        // sans bruit, et le gabarit affichait la valeur interne. Le defaut ne
        // se voit pas : la page s'affiche, avec le mauvais contenu.
        $view = new \Scholaris\View\View($this->basePath().'/tests/fixtures/views');

        @mkdir($this->basePath().'/tests/fixtures/views', 0777, true);
        file_put_contents(
            $this->basePath().'/tests/fixtures/views/collision.php',
            '<?= $path ?>|<?= $template ?>|<?= $data ?>'
        );

        $rendered = $view->render('collision', [
            'path' => '/attendu',
            'template' => 'attendu-template',
            'data' => 'attendu-data',
        ]);

        unlink($this->basePath().'/tests/fixtures/views/collision.php');

        $this->assertSame(
            '/attendu|attendu-template|attendu-data',
            $rendered,
            'Les variables du gabarit priment sur les locales du moteur'
        );
    }

    public function testUnePageIntrouvableEstJournalisee(): void
    {
        // Sans trace, un utilisateur signale « une page introuvable » sans
        // pouvoir dire laquelle, et rien ne permet de savoir ou il a clique.
        $userId = $this->createUser($this->tenantA, 'quelquun@a.cm');
        $this->giveRole($userId, 'AUCUN', []);
        $this->actingAs($userId);

        $this->request('GET', '/patrimoine');

        $entry = $this->db->selectOne(
            "SELECT * FROM audit_logs WHERE action = 'http.not_found'"
        );

        $this->assertTrue($entry !== null, 'L adresse introuvable est journalisee');
        $this->assertSame('/patrimoine', (string) $entry['resource_id'], 'Avec le chemin demande');
        $this->assertSame($userId, (string) $entry['user_id'], 'Et qui l a demande');
    }

    public function testUneRubriqueEntierementMasqueeNeLaissePasSonTitre(): void
    {
        // Un intertitre « Gestion » suivi de rien ferait croire a un ecran
        // casse.
        $userId = $this->createUser($this->tenantA, 'sansdroit@a.cm');
        $this->giveRole($userId, 'AUCUN', []);
        $this->actingAs($userId);

        $content = $this->request('GET', '/dashboard')->content();

        $this->assertTrue(
            ! str_contains($content, 'Vie scolaire'),
            'Une rubrique sans entree visible ne doit pas laisser son intertitre'
        );
    }
}

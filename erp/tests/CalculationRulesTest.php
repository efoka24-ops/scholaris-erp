<?php

declare(strict_types=1);

namespace Scholaris\Tests;

use Scholaris\Service\CalculationRules;

/**
 * Regles de calcul des moyennes.
 *
 * Bareme, arrondi, seuils de mention et traitement des absences etaient
 * ecrits en dur dans le moteur. Ces choix conviennent a un lycee camerounais
 * et a personne d'autre : une ecole primaire note sur dix, et un
 * etablissement peut decider qu'une absence ne compte pas plutot que de valoir
 * zero.
 *
 * Chaque test verifie qu'un reglage change reellement le resultat. Un reglage
 * sans effet serait pire que son absence : l'utilisateur croirait avoir
 * configure quelque chose et chercherait l'erreur ailleurs.
 */
final class CalculationRulesTest extends TestCase
{
    public function testLesValeursParDefautSontCellesQuiEtaientEnDur(): void
    {
        // Un etablissement qui n'a rien configure doit obtenir exactement les
        // memes bulletins qu'avant : une refonte de configuration ne se
        // signale pas par des moyennes qui bougent.
        $rules = CalculationRules::forTenant(['type' => 'COLLEGE', 'config_json' => null]);

        $this->assertSame(20.0, $rules->scale(), 'Bareme sur vingt');
        $this->assertSame(10.0, $rules->passMark(), 'Seuil de reussite a dix');
        $this->assertSame(2, $rules->rounding(), 'Arrondi au centieme');
        $this->assertSame('Tres bien', $rules->mention(17.0), 'Mentions inchangees');
        $this->assertSame('Insuffisant', $rules->mention(8.0), 'Et le libelle d echec aussi');
        $this->assertTrue($rules->countsUnjustifiedAbsenceAsZero(), 'Une absence non justifiee vaut zero');
    }

    public function testUneEcolePrimaireNeDecernePasDeMentionDeLycee(): void
    {
        // « Tres bien a 16 sur 20 » n'a aucun sens au primaire, ou l'on parle
        // d'acquis et de non acquis.
        $rules = CalculationRules::forTenant(['type' => 'PRIMAIRE', 'config_json' => null]);

        $this->assertSame('Acquis', $rules->mention(9.0), 'Le primaire parle d acquisition');
        $this->assertSame('Non acquis', $rules->mention(4.0), 'Et de non acquis');
    }

    public function testLeBaremeRameneLesNotesSaisiesSurUnAutreBareme(): void
    {
        $sur20 = CalculationRules::fromArray(['scale' => 20]);
        $sur10 = CalculationRules::fromArray(['scale' => 10]);

        $this->assertSame(15.0, $sur20->normalize(7.5, 10.0), '7,5 sur 10 vaut 15 sur 20');
        $this->assertSame(7.5, $sur10->normalize(15.0, 20.0), 'Et 15 sur 20 vaut 7,5 sur 10');
    }

    public function testLArrondiChangeLaValeurAffichee(): void
    {
        $centieme = CalculationRules::fromArray(['rounding' => 2]);
        $dixieme = CalculationRules::fromArray(['rounding' => 1]);
        $entier = CalculationRules::fromArray(['rounding' => 0]);

        $this->assertSame(12.35, $centieme->round(12.3456), 'Au centieme');
        $this->assertSame(12.3, $dixieme->round(12.3456), 'Au dixieme');
        $this->assertSame(12.0, $entier->round(12.3456), 'A l entier');
    }

    public function testLesMentionsSeRedefinissent(): void
    {
        $rules = CalculationRules::fromArray([
            'mentions' => [
                ['threshold' => 15, 'label' => 'Excellent'],
                ['threshold' => 12, 'label' => 'Satisfaisant'],
            ],
            'fail_label' => 'A revoir',
        ]);

        $this->assertSame('Excellent', $rules->mention(16.0), 'Le nouveau seuil haut s applique');
        $this->assertSame('Satisfaisant', $rules->mention(13.0), 'Le seuil intermediaire aussi');
        $this->assertSame('A revoir', $rules->mention(9.0), 'Et le libelle d echec');
    }

    public function testDesSeuilsEnDesordreSontRemisEnOrdre(): void
    {
        // Des seuils saisis dans le desordre attribueraient la premiere
        // mention rencontree, donc la mauvaise. C'est le tri qui rend la
        // lecture correcte, pas la saisie.
        $rules = CalculationRules::fromArray([
            'mentions' => [
                ['threshold' => 10, 'label' => 'Passable'],
                ['threshold' => 16, 'label' => 'Tres bien'],
                ['threshold' => 13, 'label' => 'Bien'],
            ],
        ]);

        $this->assertSame('Tres bien', $rules->mention(17.0), 'La mention haute prime');
        $this->assertSame('Bien', $rules->mention(14.0), 'Puis l intermediaire');
        $this->assertSame('Passable', $rules->mention(11.0), 'Puis la basse');
    }

    // --- Valeurs aberrantes ---------------------------------------------------

    public function testUnBaremeNulRetombeSurLaValeurParDefaut(): void
    {
        // A zero, toute note deviendrait une division par zero. Une saisie
        // fautive ne doit pas produire des bulletins faux, seulement des
        // bulletins ordinaires.
        $this->assertSame(20.0, CalculationRules::fromArray(['scale' => 0])->scale(), 'Zero est refuse');
        $this->assertSame(20.0, CalculationRules::fromArray(['scale' => -5])->scale(), 'Negatif aussi');
        $this->assertSame(20.0, CalculationRules::fromArray(['scale' => 'abc'])->scale(), 'Et le non-numerique');
    }

    public function testUnSeuilDeReussiteAuDessusDuBaremeEstRefuse(): void
    {
        // Au-dela du bareme, personne ne passe jamais.
        $rules = CalculationRules::fromArray(['scale' => 20, 'pass_mark' => 25]);

        $this->assertSame(10.0, $rules->passMark(), 'Le seuil aberrant est ecarte');
    }

    public function testUneMentionAuDelaDuBaremeEstEcartee(): void
    {
        $rules = CalculationRules::fromArray([
            'scale' => 20,
            'mentions' => [
                ['threshold' => 30, 'label' => 'Impossible'],
                ['threshold' => 15, 'label' => 'Excellent'],
            ],
        ]);

        $this->assertSame('Excellent', $rules->mention(19.0), 'Une mention inatteignable est retiree');
    }

    public function testUnArrondiInvalideRetombeSurLeCentieme(): void
    {
        $this->assertSame(2, CalculationRules::fromArray(['rounding' => 7])->rounding(), 'Sept decimales est refuse');
    }

    // --- Effet sur le calcul reel ---------------------------------------------

    public function testUneAbsenceNonJustifieePeutNePasCompter(): void
    {
        $zero = CalculationRules::fromArray(['unjustified_absence' => 'ZERO']);
        $ignoree = CalculationRules::fromArray(['unjustified_absence' => 'IGNORED']);

        $this->assertTrue($zero->countsUnjustifiedAbsenceAsZero(), 'Par defaut elle vaut zero');
        $this->assertTrue(! $ignoree->countsUnjustifiedAbsenceAsZero(), 'Elle peut etre ecartee du calcul');
    }

    public function testLeSeuilDeReussiteDecideDeLaReussite(): void
    {
        $strict = CalculationRules::fromArray(['pass_mark' => 12]);

        $this->assertTrue($strict->isPass(12.0), 'Le seuil lui-meme est atteint');
        $this->assertTrue(! $strict->isPass(11.99), 'Juste en dessous, non');
    }

    public function testLesReglagesSurviventAUnAllerRetourEnConfiguration(): void
    {
        // Ils sont ranges dans config_json puis relus a chaque calcul : un
        // reglage perdu au passage se traduirait par des moyennes qui
        // changent sans raison apparente.
        $saved = CalculationRules::fromArray([
            'scale' => 10,
            'pass_mark' => 5,
            'rounding' => 1,
            'unjustified_absence' => 'IGNORED',
            'mentions' => [['threshold' => 8, 'label' => 'Acquis']],
            'fail_label' => 'Non acquis',
        ])->toArray();

        $reloaded = CalculationRules::forTenant([
            'type' => 'COLLEGE',
            'config_json' => json_encode(['calculation' => $saved]),
        ]);

        $this->assertSame(10.0, $reloaded->scale(), 'Le bareme est conserve');
        $this->assertSame(5.0, $reloaded->passMark(), 'Le seuil aussi');
        $this->assertSame(1, $reloaded->rounding(), 'Et l arrondi');
        $this->assertSame('Acquis', $reloaded->mention(9.0), 'Ainsi que les mentions');
        $this->assertTrue(! $reloaded->countsUnjustifiedAbsenceAsZero(), 'Et le traitement des absences');
    }
}

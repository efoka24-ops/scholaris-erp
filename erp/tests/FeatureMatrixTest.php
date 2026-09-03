<?php

declare(strict_types=1);

namespace Scholaris\Tests;

use Scholaris\Tenant\Features;

/**
 * Matrice des fonctionnalites par type d'etablissement.
 *
 * Le principe a tenir : un etablissement ne voit que ce qui le concerne. Un
 * directeur d'ecole primaire ne doit jamais croiser « baccalaureat », « series »
 * ou « credits ECTS » — ces notions n'existent pas chez lui, et les lui montrer
 * (meme grisees) serait une erreur de conception.
 */
final class FeatureMatrixTest extends TestCase
{
    private function features(string $type, array $overrides = []): Features
    {
        $matrix = require $this->basePath().'/database/feature-matrix.php';

        return new Features($matrix, $type, $overrides);
    }

    public function testUneEcolePrimaireNaNiSerieNiBaccalaureat(): void
    {
        $primaire = $this->features('PRIMAIRE');

        $this->assertTrue($primaire->disabled('exams.baccalaureat'), 'Pas de baccalaureat au primaire');
        $this->assertTrue($primaire->disabled('exams.probatoire'), 'Ni de probatoire');
        $this->assertTrue($primaire->disabled('exams.series'), 'Ni de series');
        $this->assertTrue($primaire->disabled('subjects.credits'), 'Ni de credits ECTS');
        $this->assertTrue($primaire->disabled('subjects.teaching_units'), 'Ni d unites d enseignement');
        $this->assertTrue($primaire->disabled('subjects.coefficients'), 'Ni de coefficients');

        // Ce qui la concerne, en revanche, est bien la.
        $this->assertTrue($primaire->enabled('exams.cep'), 'Le CEP concerne le primaire');
        $this->assertTrue($primaire->enabled('evaluation.competencies'), 'L approche par competences aussi');
        $this->assertTrue($primaire->enabled('calc.simple_average'), 'Et la moyenne simple, sans coefficients');
    }

    public function testChaqueTypeAssocieSonExamenOfficiel(): void
    {
        $expected = [
            'PRIMAIRE' => 'exams.cep',
            'COLLEGE' => 'exams.bepc',
            'LYCEE_GENERAL' => 'exams.baccalaureat',
            'LYCEE_TECHNIQUE' => 'exams.cap_bep',
        ];

        foreach ($expected as $type => $exam) {
            $this->assertTrue(
                $this->features($type)->enabled($exam),
                "{$type} doit avoir {$exam}"
            );
        }

        // Et n'a pas celui des autres.
        $this->assertTrue($this->features('COLLEGE')->disabled('exams.cep'), 'Le college n a pas le CEP');
        $this->assertTrue($this->features('LYCEE_GENERAL')->disabled('exams.bepc'), 'Le lycee n a pas le BEPC');
    }

    public function testUneFonctionnaliteInexistanteNePeutPasEtreActivee(): void
    {
        // Le forcage porte sur une fonctionnalite marquee absente pour ce type.
        // L'activer doit rester sans effet, sinon un directeur de primaire
        // pourrait s'ouvrir le baccalaureat en trafiquant un formulaire.
        $primaire = $this->features('PRIMAIRE', ['exams.baccalaureat' => true]);

        $this->assertTrue(
            $primaire->disabled('exams.baccalaureat'),
            'Une fonctionnalite absente du type ne doit jamais s activer'
        );
    }

    public function testUneFonctionnaliteOptionnelleEstMasqueeParDefaut(): void
    {
        $college = $this->features('COLLEGE');

        $this->assertTrue($college->isOptional('life.transport'), 'Le transport est optionnel au college');
        $this->assertTrue($college->disabled('life.transport'), 'Et masque tant qu il n est pas active');

        $avecTransport = $this->features('COLLEGE', ['life.transport' => true]);
        $this->assertTrue($avecTransport->enabled('life.transport'), 'Une fois active, il est disponible');
    }

    public function testLaTerminologieSAdapteAuType(): void
    {
        $college = $this->features('COLLEGE');
        $centre = $this->features('CENTRE_FORMATION');

        $this->assertSame('Eleves', $college->label('students', 'Eleves'), 'Un college parle d eleves');
        $this->assertSame('Apprenants', $centre->label('students', 'Eleves'), 'Un centre de formation d apprenants');
        $this->assertSame('Modules', $centre->label('subjects', 'Matieres'), 'Et de modules, non de matieres');
    }

    public function testLesTypesHistoriquesDuSchemaSontReconnus(): void
    {
        // Les etablissements crees avant la matrice portent les anciens codes :
        // ils doivent continuer de fonctionner sans migration de donnees.
        $this->assertSame('COLLEGE', $this->features('SECONDAIRE')->type(), 'SECONDAIRE devient COLLEGE');
        $this->assertSame('LYCEE_TECHNIQUE', $this->features('TECHNIQUE')->type(), 'TECHNIQUE devient LYCEE_TECHNIQUE');
        $this->assertSame('CENTRE_FORMATION', $this->features('FORMATION_PRO')->type(), 'FORMATION_PRO devient CENTRE_FORMATION');
    }

    public function testUnTypeInconnuRetombeSurUnDefautSur(): void
    {
        $unknown = $this->features('TYPE_QUI_NEXISTE_PAS');

        $this->assertSame('COLLEGE', $unknown->type(), 'Un type inconnu retombe sur le college');
    }

    public function testChaqueFonctionnalitePorteUnLibelleLisible(): void
    {
        // L'ecran Parametres affiche ces intitules a un directeur d'ecole. Lui
        // montrer « exams.cep » ou « bulletins.class_council » ne lui apprend
        // rien : la cle technique est un detail d'implementation.
        $matrix = require $this->basePath().'/database/feature-matrix.php';

        $missing = array_values(array_diff(
            array_keys($matrix['features']),
            array_keys($matrix['names'])
        ));

        $this->assertSame([], $missing, 'Fonctionnalites sans libelle : '.implode(', ', $missing));
    }

    public function testChaqueRoleNeReferenceQueDesPermissionsExistantes(): void
    {
        // Une permission mal orthographiee dans un role ne provoque aucune
        // erreur : le seed l'ignore, et le role se retrouve silencieusement
        // ampute d'un droit. On ne le decouvre qu'en constatant qu'un
        // responsable ne peut pas faire son travail.
        $matrix = require $this->basePath().'/database/rbac-matrix.php';

        $known = [];

        foreach ($matrix['permissions'] as [$resource, $action, $description]) {
            $known[$resource.':'.$action] = true;
        }

        $problems = [];

        foreach ($matrix['roles'] as $role) {
            foreach ($role['permissions'] as $permission) {
                if (! isset($known[$permission])) {
                    $problems[] = $role['name'].' -> '.$permission;
                }
            }
        }

        $this->assertSame(
            [],
            $problems,
            'Permission inexistante referencee par un role : '.implode(', ', $problems)
        );
    }

    public function testChaqueRolePorteUnLibelleEtUneDescription(): void
    {
        // Un role sans description arrive vide dans l'ecran des habilitations,
        // et personne ne sait ce qu'il autorise.
        $matrix = require $this->basePath().'/database/rbac-matrix.php';
        $problems = [];

        foreach ($matrix['roles'] as $role) {
            if (trim((string) ($role['description'] ?? '')) === '') {
                $problems[] = $role['name'];
            }

            if ($role['permissions'] === []) {
                $problems[] = $role['name'].' (aucune permission)';
            }
        }

        $this->assertSame([], $problems, 'Role incomplet : '.implode(', ', $problems));
    }

    public function testChaqueTypeExposeSesFonctionnalitesOptionnelles(): void
    {
        foreach (['PRIMAIRE', 'COLLEGE', 'LYCEE_GENERAL', 'LYCEE_TECHNIQUE', 'CENTRE_FORMATION'] as $type) {
            $optional = $this->features($type)->optional();

            $this->assertTrue($optional !== [], "{$type} doit proposer au moins une option a l Admin");

            foreach ($optional as $key => $entry) {
                $this->assertTrue(
                    $entry['name'] !== $key,
                    "L option {$key} de {$type} doit porter un libelle lisible, pas sa cle technique"
                );
            }
        }
    }
}

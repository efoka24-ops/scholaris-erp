<?php

declare(strict_types=1);

namespace Tests\Feature\Primary\Academic;

use Scholaris\Modules\Apps\Primary\Academic\Contracts\CompetencyDirectory;
use Scholaris\Modules\Apps\Primary\Academic\Contracts\LevelDirectory;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\Primary\PrimaryTestCase;

/** EX-PRI-05, 16, 17 : profils de niveau, domaines, matières par niveau, compétences, modèle `example` (étiqueté), copie d'année. */
final class ReferentialTest extends PrimaryTestCase
{
    protected function setUp(): void
    {
        $this->bootPrimary();
    }

    public function testLeModeleExempleEstEtiquetteEtAppliqueSansDoublon(): void
    {
        $models = $this->ok('GET', '/primary/referentials')['items'];
        $example = null;
        foreach ($models as $model) {
            if ($model['code'] === 'example') {
                $example = $model;
            }
        }
        self::assertNotNull($example);
        self::assertSame('example', $example['origin']);
        self::assertStringContainsString('Exemple', $example['label']);
        self::assertTrue($example['platform']);

        $first = $this->ok('POST', '/primary/referentials/example/apply', ['academic_year_id' => self::YEAR]);
        self::assertSame(['domains' => 3, 'levels' => 3, 'classes' => 3, 'subjects' => 8, 'competencies' => 6], $first['created']);

        $second = $this->ok('POST', '/primary/referentials/example/apply', ['academic_year_id' => self::YEAR]);
        self::assertSame(['domains' => 0, 'levels' => 0, 'classes' => 0, 'subjects' => 0, 'competencies' => 0], $second['created'], 'Deuxième application : aucun doublon.');
        self::assertSame(3, $second['existing']['levels']);

        $profiles = $this->call('GET', '/primary/level-profiles?sort=sort_order')[1]['data'];
        self::assertCount(3, $profiles);
        $final = array_values(array_filter($profiles, static fn (array $p): bool => $p['is_final_level']));
        self::assertCount(1, $final);
        $directory = $this->service(LevelDirectory::class);
        $finalProfile = $directory->profile(self::TA, self::EA, $final[0]['level_structure_id']);
        self::assertTrue($finalProfile !== null && $finalProfile->isExamLevel);
        self::assertCount(3, $this->call('GET', '/primary/domains?filter[academic_year_id]=' . self::YEAR)[1]['data']);

        $competencies = $this->service(CompetencyDirectory::class)->forLevel(self::TA, self::EA, self::YEAR, $profiles[0]['level_structure_id']);
        self::assertSame(['LECT1', 'CALC1', 'EDUC1'], array_map(static fn ($c): string => $c->code, $competencies));
    }

    public function testProfilsDeNiveauUnSeulNiveauFinalParSection(): void
    {
        $a = $this->makeLevel(self::TA, self::EA, 'CM1');
        $b = $this->makeLevel(self::TA, self::EA, 'CM2');
        $this->ok('POST', '/primary/level-profiles', ['level_structure_id' => $a['level'], 'sort_order' => 5, 'is_final_level' => true]);
        [$status, $problem] = $this->call('POST', '/primary/level-profiles', ['level_structure_id' => $b['level'], 'sort_order' => 6, 'is_final_level' => true]);
        self::assertSame(422, $status);
        self::assertArrayHasKey('is_final_level', $problem['errors']);

        $profile = $this->ok('POST', '/primary/level-profiles', ['level_structure_id' => $b['level'], 'sort_order' => 6]);
        self::assertSame(422, $this->call('PATCH', '/primary/level-profiles/' . $profile['id'], ['is_final_level' => true])[0]);
        self::assertSame(409, $this->call('POST', '/primary/level-profiles', ['level_structure_id' => $b['level']])[0], 'Un niveau n\'a qu\'un profil.');
        self::assertSame(422, $this->call('POST', '/primary/level-profiles', ['level_structure_id' => 'inconnu'])[0]);
        self::assertSame(422, $this->call('POST', '/primary/level-profiles', ['sort_order' => 1])[0], 'Niveau ou nom obligatoire.');

        $created = $this->ok('POST', '/primary/level-profiles', ['name' => 'Niveau créé par nom', 'sort_order' => 1]);
        self::assertNotSame('', $created['level_structure_id']);
    }

    public function testMatiereUniqueParNiveauEtCompetencesRattachees(): void
    {
        $level = $this->makeLevel(self::TA, self::EA, 'CE1');
        $this->ok('POST', '/primary/level-profiles', ['level_structure_id' => $level['level']]);
        $domain = $this->ok('POST', '/primary/domains', ['academic_year_id' => self::YEAR, 'code' => 'dom1', 'label_fr' => 'Domaine un']);
        self::assertSame('DOM1', $domain['code']);
        self::assertSame(422, $this->call('POST', '/primary/domains', ['academic_year_id' => self::YEAR, 'code' => 'DOM1', 'label_fr' => 'Doublon'])[0]);

        $subject = ['academic_year_id' => self::YEAR, 'level_structure_id' => $level['level'], 'domain_id' => $domain['id'], 'subject_code' => 'math', 'subject_name' => 'Mathématiques'];
        $created = $this->ok('POST', '/primary/level-subjects', $subject);
        self::assertSame('both', $created['evaluation_mode']);
        [$status, $problem] = $this->call('POST', '/primary/level-subjects', $subject);
        self::assertSame(422, $status);
        self::assertArrayHasKey('subject_code', $problem['errors']);
        self::assertSame(422, $this->call('POST', '/primary/level-subjects', ['domain_id' => 'autre'] + $subject + ['subject_code' => 'x'])[0]);

        $competency = $this->ok('POST', '/primary/competencies', [
            'academic_year_id' => self::YEAR, 'level_structure_id' => $level['level'], 'domain_id' => $domain['id'], 'subject_id' => $created['subject_id'],
            'code' => 'c1', 'label_fr' => 'Compter', 'label_en' => 'Count',
        ]);
        self::assertSame('C1', $competency['code']);
        self::assertSame(422, $this->call('POST', '/primary/competencies', [
            'academic_year_id' => self::YEAR, 'level_structure_id' => $level['level'], 'domain_id' => $domain['id'], 'code' => 'C1', 'label_fr' => 'Doublon',
        ])[0]);
        $off = $this->ok('PATCH', '/primary/competencies/' . $competency['id'], ['active' => false]);
        self::assertFalse($off['active']);
        self::assertSame([], $this->service(CompetencyDirectory::class)->forLevel(self::TA, self::EA, self::YEAR, $level['level']), 'Une compétence désactivée n\'est plus proposée.');
    }

    public function testCopieDAnneeConserveCodesEtOrdresSansDoublon(): void
    {
        $this->ok('POST', '/primary/referentials/example/apply', ['academic_year_id' => self::YEAR]);
        $copy = $this->ok('POST', '/primary/referentials/copy-year', ['from_year_id' => self::YEAR, 'to_year_id' => 'AY-2027']);
        self::assertSame(['domains' => 3, 'subjects' => 8, 'competencies' => 6], $copy['created']);
        $again = $this->ok('POST', '/primary/referentials/copy-year', ['from_year_id' => self::YEAR, 'to_year_id' => 'AY-2027']);
        self::assertSame(['domains' => 0, 'subjects' => 0, 'competencies' => 0], $again['created']);
        $domains = $this->call('GET', '/primary/domains?filter[academic_year_id]=AY-2027&sort=sort_order')[1]['data'];
        self::assertSame(['LANG', 'MATH', 'SOC'], array_column($domains, 'code'));
        self::assertSame(422, $this->call('POST', '/primary/referentials/copy-year', ['from_year_id' => self::YEAR, 'to_year_id' => self::YEAR])[0]);
    }

    public function testExportCsvDuReferentiel(): void
    {
        $this->ok('POST', '/primary/referentials/example/apply', ['academic_year_id' => self::YEAR]);
        $response = $this->raw('GET', '/primary/referential/export?kind=domains&academic_year_id=' . self::YEAR);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Langues et communication', (string) $response->getBody());
        self::assertSame(422, $this->call('GET', '/primary/referential/export?kind=inconnu')[0]);
    }

    public function testIsolationAutreClientEtDroits(): void
    {
        $this->ok('POST', '/primary/referentials/example/apply', ['academic_year_id' => self::YEAR]);
        $profileId = $this->call('GET', '/primary/level-profiles')[1]['data'][0]['id'];

        // Client B : son directeur ne voit rien de A et reçoit 404 sur un identifiant de A.
        $this->grantAll(self::TB, 'directeur-b', self::EB);
        self::assertSame([], $this->call('GET', '/primary/level-profiles', null, 'directeur-b', self::TB, self::EB)[1]['data']);
        self::assertSame(404, $this->call('GET', '/primary/level-profiles/' . $profileId, null, 'directeur-b', self::TB, self::EB)[0]);
        self::assertSame(404, $this->call('PATCH', '/primary/level-profiles/' . $profileId, ['sort_order' => 9], 'directeur-b', self::TB, self::EB)[0]);

        // Un enseignant lit le référentiel mais ne l'écrit pas ; sans permission : 403.
        $this->assign(self::TA, 'maitre', self::EA, 'primary.referential.read', Scope::CLASS_GROUP, 'une-classe');
        self::assertSame(200, $this->call('GET', '/primary/level-profiles', null, 'maitre')[0]);
        self::assertSame(403, $this->call('POST', '/primary/domains', ['academic_year_id' => self::YEAR, 'code' => 'X', 'label_fr' => 'X'], 'maitre')[0]);
        $this->access->grant('intrus', self::EA);
        self::assertSame(403, $this->call('GET', '/primary/level-profiles', null, 'intrus')[0]);

        // Application suspendue : lecture oui, écriture 423.
        $this->applications->set(self::TA, ApplicationAccess::SUSPENDED);
        self::assertSame(200, $this->call('GET', '/primary/level-profiles')[0]);
        self::assertSame(423, $this->call('POST', '/primary/domains', ['academic_year_id' => self::YEAR, 'code' => 'Y', 'label_fr' => 'Y'])[0]);
    }
}

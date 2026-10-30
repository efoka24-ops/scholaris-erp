<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Curriculum;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\CurriculumCatalog;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Tests\Support\Higher\Academic\CurriculumTestCase;

/**
 * RG-MAQ-2 (T215, T220) : maquette LMD publiée seulement avec 30 crédits par semestre (paramètre), aucune UE sans EC, volumes
 * renseignés ; RG-MAQ-3 : référence du programme approuvé et tutelle de l'année ; projection vers le référentiel d'évaluation
 * (D9), idempotente.
 */
final class CurriculumPublishTest extends CurriculumTestCase
{
    protected function setUp(): void
    {
        $this->bootCurriculum();
    }

    public function testMaquetteDeMarieN30CreditsPublieeEtProjetee(): void
    {
        $marie = $this->marie();

        $check = $this->ok('GET', '/higher/curricula/' . $marie['id'] . '/check');
        self::assertSame([[], [], true], [$check['errors'], $check['warnings'], $check['publishable']]);
        self::assertSame(30, $check['semesters'][0]['credits']);
        self::assertSame(450 * 60, $check['semesters'][0]['volume_minutes'], '30 crédits × 15 h présentielles.');

        $published = $this->ok('POST', '/higher/curricula/' . $marie['id'] . '/publish');
        self::assertSame(['published', 1], [$published['status'], $published['version']]);

        $catalog = $this->service(CurriculumCatalog::class);
        $ue2 = $catalog->find(self::T, self::E, 'hi_ue:' . $marie['units']['UE2']);
        self::assertSame([6, 'hi_fundamental', 'Unité UE2', true], [$ue2?->credits, $ue2->kind, $ue2->name, $ue2->active]);
        $elements = $catalog->elementsOf(self::T, self::E, 'hi_ue:' . $marie['units']['UE1']);
        self::assertSame(['Comptabilité générale', 'TD de comptabilité'], array_map(static fn ($e): string => $e->name, $elements));

        $units = $this->service(CurriculumDirectory::class)->units(self::T, self::E, $marie['id']);
        self::assertCount(5, $units);
        self::assertSame($ue2->id, $units[1]->catalogSubjectId);
        self::assertSame($marie['id'], $this->service(CurriculumDirectory::class)->publishedFor(self::T, self::E, $this->org['program'], $this->org['l1'], self::YEAR)?->id);

        $tree = $this->ok('GET', '/higher/curricula/' . $marie['id']);
        self::assertEquals(90, $tree['semesters'][0]['units'][0]['volume_hours'], 'UE1 : CG (CM 40 h + TD 20 h) + TDC (CM 20 h + TD 10 h).');
        $snapshots = $this->service(ConnectionFactory::class)->pdo()->query('SELECT COUNT(*) FROM hi_curriculum_snapshots')->fetchColumn();
        self::assertSame(1, (int) $snapshots);
        self::assertContains('higher.curriculum.published', array_column($this->audit->entries, 'action'));
    }

    public function testRg_maq_2_SemestreDeseQuilibreNonPubliable(): void
    {
        $marie = $this->marie();
        $this->ok('PATCH', '/higher/curriculum-elements/' . $marie['elements']['BUR'], ['credits' => 1]);
        $this->ok('PATCH', '/higher/curriculum-units/' . $marie['units']['UE5'], ['credits' => 4]);

        [$status, $problem] = $this->call('POST', '/higher/curricula/' . $marie['id'] . '/publish');

        self::assertSame(422, $status);
        self::assertContains('S1 : 28 crédits au lieu de 30 (RG-MAQ-2).', $problem['errors']['curriculum']);

        $this->settings->set(self::E, 'higher.curriculum.credits_per_semester', 28, $this->org['school']);
        self::assertSame('published', $this->ok('POST', '/higher/curricula/' . $marie['id'] . '/publish')['status'], 'Paramètre de l\'école : 28 crédits.');
    }

    public function testUeSansEcEtCreditsIncoherentsRefuses(): void
    {
        $marie = $this->marie();
        $this->ok('POST', '/higher/curricula/' . $marie['id'] . '/units', ['semester_ordinal' => 1, 'code' => 'UE6', 'title' => 'Vide', 'unit_type' => 'project', 'credits' => 0]);
        $this->ok('PATCH', '/higher/curriculum-units/' . $marie['units']['UE3'], ['credits' => 7]);
        $this->ok('PATCH', '/higher/curriculum-elements/' . $marie['elements']['IA'], ['cm_hours' => 0, 'td_hours' => 0]);

        [$status, $problem] = $this->call('POST', '/higher/curricula/' . $marie['id'] . '/publish');

        self::assertSame(422, $status);
        $errors = $problem['errors']['curriculum'];
        self::assertContains('UE UE6 sans élément constitutif (EC) : ajoutez au moins un EC.', $errors);
        self::assertContains('UE UE3 : 7 crédits déclarés, 6 crédits dans ses EC.', $errors);
        self::assertContains('EC IA : aucun volume horaire (CM, TD ou TP).', $errors);
        self::assertContains('S1 : 31 crédits au lieu de 30 (RG-MAQ-2).', $errors);
        self::assertNotSame([], $this->ok('GET', '/higher/curricula/' . $marie['id'] . '/check')['warnings'], 'Écart avec 1 crédit = 25 h signalé.');
    }

    public function testRg_maq_3_ProgrammeApprouveEtTutelleExiges(): void
    {
        $marie = $this->marie();
        $this->ok('PATCH', '/higher/curricula/' . $marie['id'], ['approved_programme_ref' => null]);
        self::assertContains('Filière sous tutelle : citez la référence du programme approuvé par l\'université de tutelle (RG-MAQ-3).', $this->call('POST', '/higher/curricula/' . $marie['id'] . '/publish')[1]['errors']['curriculum']);

        $other = $this->marie('AY-2027');
        $this->ok('PATCH', '/higher/curricula/' . $other['id'], ['approved_programme_ref' => 'UD/2027']);
        self::assertContains('Filière LMD sans université de tutelle pour l\'année de la maquette (RG-MAQ-3, RG-ORG-8).', $this->call('POST', '/higher/curricula/' . $other['id'] . '/publish')[1]['errors']['curriculum']);
    }

    public function testUeAChoix(): void
    {
        $marie = $this->marie();
        $this->ok('DELETE', '/higher/curriculum-units/' . $marie['units']['UE5']);
        foreach (['OPT-A' => 'Espagnol', 'OPT-B' => 'Allemand'] as $code => $title) {
            $unit = $this->ok('POST', '/higher/curricula/' . $marie['id'] . '/units', [
                'semester_ordinal' => 1, 'code' => $code, 'title' => $title, 'unit_type' => 'optional', 'credits' => 6, 'choice_group' => 'LV2', 'choice_count' => 1,
            ]);
            $this->ok('POST', '/higher/curriculum-units/' . $unit['id'] . '/elements', ['code' => $code . '-1', 'title' => $title, 'credits' => 6, 'cm_hours' => 60, 'td_hours' => 30, 'personal_hours' => 60]);
        }

        self::assertSame(30, $this->ok('GET', '/higher/curricula/' . $marie['id'] . '/check')['semesters'][0]['credits'], 'Une option sur deux : 24 + 6.');
        self::assertSame('published', $this->ok('POST', '/higher/curricula/' . $marie['id'] . '/publish')['status']);
        $optional = array_values(array_filter($this->service(CurriculumDirectory::class)->units(self::T, self::E, $marie['id']), static fn ($u): bool => $u->choiceGroup === 'LV2'));
        self::assertSame([1, 1], array_map(static fn ($u): ?int => $u->choiceCount, $optional));
    }

    public function testPublicationReserveeALaValidationEtMaquetteUniqueParAnnee(): void
    {
        $marie = $this->marie();
        $this->assign(self::T, 'chef-dept', self::E, 'higher.curriculum.manage', Scope::ORG_UNIT, $this->org['department'], 'higher_chef_dept');
        $this->assign(self::T, 'chef-dept', self::E, 'higher.curriculum.read', Scope::ORG_UNIT, $this->org['department'], 'higher_chef_dept');

        self::assertSame(200, $this->call('PATCH', '/higher/curricula/' . $marie['id'], ['title' => 'L1 CF'], 'chef-dept')[0], 'Le chef de département édite (É).');
        self::assertSame(403, $this->call('POST', '/higher/curricula/' . $marie['id'] . '/publish', null, 'chef-dept')[0], 'Publier = valider (higher.curriculum.approve).');
        self::assertSame(409, $this->call('POST', '/higher/curricula', ['program_id' => $this->org['program'], 'level_id' => $this->org['l1'], 'academic_year_id' => self::YEAR])[0]);
        self::assertSame(422, $this->call('POST', '/higher/curricula', ['program_id' => $this->org['program'], 'level_id' => 'inconnu', 'academic_year_id' => self::YEAR])[0]);
        self::assertCount(1, $this->call('GET', '/higher/curricula?filter[program_id]=' . $this->org['program'], null, 'chef-dept')[1]['data']);
    }
}

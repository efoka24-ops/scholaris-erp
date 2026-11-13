<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Councils;

use Scholaris\Modules\Apps\Secondary\Catalogue\Contracts\StreamDirectory;
use Scholaris\Modules\Apps\Secondary\Councils\Application\SecondaryCouncilLines;
use Scholaris\Modules\Apps\Secondary\Councils\Application\SecondaryReportCardVariables;
use Scholaris\Modules\Apps\Secondary\Councils\Infrastructure\SecondaryRuleScopes;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * Lot E3-A : droits et refus des routes /secondary/councils (brouillon de règles, paramètres des bulletins, aperçu) et neutralité des
 * points d'extension hors d'une classe du secondaire. Données fictives.
 */
final class CouncilsApiTest extends SecondaryTestCase
{
    private const YEAR = 'annee-ca-2026';
    private string $school = '';
    private string $class = '';
    /** @var array<string, array<string, mixed>> */
    private array $levels = [];

    protected function setUp(): void
    {
        $this->bootSecondary();
        $this->school = $this->factory->establishment(self::TA, 'leg');
        $this->grant(self::TA, self::ADMIN, $this->school, ['secondary.profile.manage', 'secondary.settings.manage', 'secondary.profile.read']);
        $this->grant(self::TA, 'censeur-ca', $this->school, ['secondary.council.prepare']);
        $this->grant(self::TA, 'prof-ca', $this->school, ['secondary.reportcard.read']);
        $this->ok('POST', '/secondary/setup/profile/open', ['reason' => 'Ouverture de test'], self::ADMIN, self::TA, $this->school);
        $this->levels = array_column($this->ok('POST', '/secondary/setup/levels/examples', [], self::ADMIN, self::TA, $this->school), null, 'code');
        $this->class = (string) $this->ok('POST', '/secondary/setup/classes', ['label' => '3e A (fictive)', 'level_id' => $this->levels['3E']['id'], 'academic_year_id' => self::YEAR], self::ADMIN, self::TA, $this->school)['structure_id'];
    }

    public function testBrouillonDeReglesExigeLesCoefficientsEtLeDroit(): void
    {
        $path = '/secondary/councils/rule-draft?class_id=' . $this->class;
        self::assertSame(409, $this->call('GET', $path, null, 'censeur-ca', self::TA, $this->school)[0], 'Aucun coefficient en vigueur dans Setup.');
        self::assertSame(403, $this->call('GET', $path, null, 'prof-ca', self::TA, $this->school)[0]);
        self::assertSame(422, $this->call('GET', $path . '&scope=annee', null, 'censeur-ca', self::TA, $this->school)[0]);
        self::assertSame(422, $this->call('GET', '/secondary/councils/rule-draft', null, 'censeur-ca', self::TA, $this->school)[0]);

        $subject = $this->ok('POST', '/secondary/setup/subjects', ['code' => 'MATH', 'label' => 'Mathématiques'], self::ADMIN, self::TA, $this->school);
        $this->ok('POST', '/secondary/setup/coefficients', [
            'academic_year_id' => self::YEAR, 'level_code' => '3E', 'subject_ref' => $subject['id'], 'coefficient' => 4, 'weekly_hours' => 4,
            'subject_role' => 'official', 'origin' => 'example', 'valid_from' => '2026-09-01', 'reason' => 'Valeur d\'exemple',
        ], self::ADMIN, self::TA, $this->school);
        $draft = $this->ok('GET', $path . '&scope=class', null, 'censeur-ca', self::TA, $this->school);
        self::assertSame($this->class, $draft['structure_id']);
        self::assertEquals(4, $draft['definition']['strategy_options']['coefficients'][$draft['coefficients'][0]['subject_id']]['coefficient']);
        self::assertSame('secondary', $draft['definition']['strategy']);

        $scopes = $this->service(SecondaryRuleScopes::class);
        self::assertSame([$this->class, (string) $this->levels['3E']['structure_id']], $scopes->scopesOf(self::TA, $this->school, $this->class), 'Classe puis niveau.');
        self::assertSame([], $scopes->scopesOf(self::TA, $this->school, 'structure-inconnue'));
    }

    /**
     * C-118 : les règles « pour tout le niveau » respectent le coefficient propre à une série : le brouillon du niveau porte, pour
     * chaque classe d'une série à coefficients propres, une version à publier sur la classe (la règle de la classe prime sur celle du
     * niveau). Sans coefficient de niveau, seules ces versions de classe sont proposées (plus de refus 409).
     */
    public function testReglesDuNiveauRespectentLeCoefficientDUneSerie(): void
    {
        $refC = null;
        foreach ($this->service(StreamDirectory::class)->forEstablishment(self::TA, $this->school) as $stream) {
            if ($stream->code === 'C' && $stream->cycleCode === 'second') {
                $refC = $stream->id;
            }
        }
        self::assertNotNull($refC);
        $streamC = $this->ok('POST', '/secondary/setup/streams', ['stream_ref_id' => $refC], self::ADMIN, self::TA, $this->school);
        $classC = (string) $this->ok('POST', '/secondary/setup/classes', ['label' => '2nde C1 (fictive)', 'level_id' => $this->levels['2NDE']['id'], 'stream_id' => $streamC['id'], 'academic_year_id' => self::YEAR], self::ADMIN, self::TA, $this->school)['structure_id'];
        $math = $this->ok('POST', '/secondary/setup/subjects', ['code' => 'MATH', 'label' => 'Mathématiques'], self::ADMIN, self::TA, $this->school);
        $coefficient = static fn (?string $stream, int $value): array => [
            'academic_year_id' => self::YEAR, 'level_code' => '2NDE', 'subject_ref' => $math['id'], 'coefficient' => $value, 'weekly_hours' => 4,
            'subject_role' => 'official', 'origin' => 'example', 'valid_from' => '2026-09-01', 'reason' => 'Valeur d\'exemple', 'stream_id' => $stream,
        ];
        $this->ok('POST', '/secondary/setup/coefficients', $coefficient((string) $streamC['id'], 5), self::ADMIN, self::TA, $this->school);
        $path = '/secondary/councils/rule-draft?class_id=' . $classC . '&scope=level';

        $onlyStream = $this->ok('GET', $path, null, 'censeur-ca', self::TA, $this->school);
        self::assertNull($onlyStream['structure_id'], 'Aucun coefficient pour tout le niveau : rien à publier sur le niveau.');
        self::assertSame([$classC], array_column($onlyStream['stream_overrides'], 'structure_id'));
        self::assertEquals(5, $onlyStream['stream_overrides'][0]['definition']['strategy_options']['coefficients'][$onlyStream['stream_overrides'][0]['coefficients'][0]['subject_id']]['coefficient']);

        $this->ok('POST', '/secondary/setup/coefficients', $coefficient(null, 3), self::ADMIN, self::TA, $this->school);
        $both = $this->ok('GET', $path, null, 'censeur-ca', self::TA, $this->school);
        self::assertSame((string) $this->levels['2NDE']['structure_id'], $both['structure_id']);
        self::assertEquals(3, $both['definition']['strategy_options']['coefficients'][$both['coefficients'][0]['subject_id']]['coefficient']);
        self::assertSame([$classC], array_column($both['stream_overrides'], 'structure_id'), 'La série C garde son coefficient 5.');
        self::assertEquals(5, $both['stream_overrides'][0]['coefficients'][0]['coefficient']);
        self::assertSame([], $this->ok('GET', '/secondary/councils/rule-draft?class_id=' . $classC . '&scope=class', null, 'censeur-ca', self::TA, $this->school)['stream_overrides']);
    }

    public function testPrereglageDesTypesDeBulletinSansEcrasement(): void
    {
        self::assertSame(403, $this->call('POST', '/secondary/councils/report-card-settings/preset', [], 'censeur-ca', self::TA, $this->school)[0]);
        self::assertNull($this->ok('GET', '/secondary/councils/report-card-settings', null, 'prof-ca', self::TA, $this->school)['report_card_types']);
        $first = $this->ok('POST', '/secondary/councils/report-card-settings/preset', [], self::ADMIN, self::TA, $this->school);
        self::assertTrue($first['applied']);
        self::assertSame(['trimester' => 'secondary_report_trimester', 'year' => 'secondary_report_annual'], $first['report_card_types']);
        self::assertFalse($this->ok('POST', '/secondary/councils/report-card-settings/preset', [], self::ADMIN, self::TA, $this->school)['applied'], 'Valeur déjà posée conservée.');
        self::assertContains('secondary.reportcard.settings.preset', $this->audit->actions());
    }

    public function testApercuEtPointsDExtensionNeutresHorsSecondaire(): void
    {
        self::assertSame(404, $this->call('GET', '/secondary/councils/conseil-inconnu/report-cards/eleve', null, 'prof-ca', self::TA, $this->school)[0]);
        self::assertSame(403, $this->call('GET', '/secondary/councils/conseil-inconnu/report-cards/eleve', null, self::ADMIN, self::TA, $this->school)[0]);
        self::assertSame([], $this->service(SecondaryCouncilLines::class)->prefill(self::TA, $this->school, 'classe-primaire', 'T1', ['p1']));
        self::assertSame([], $this->service(SecondaryReportCardVariables::class)->for(self::TA, $this->school, 'conseil-inconnu', 'p1'));
    }
}

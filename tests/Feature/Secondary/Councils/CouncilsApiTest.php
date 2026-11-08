<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Councils;

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

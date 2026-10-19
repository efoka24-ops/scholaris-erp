<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Deliberation;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\MarkSources;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\Strategies\HigherLmdStrategy;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\MarkOverlaySource;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\MarkSheet;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\MarkSource;
use Tests\Support\Higher\Deliberation\DeliberationFixtures as F;

/** Fusion des feuilles de notes des sources (EX-DEL-02) : sources principales en union, sources complémentaires ajoutées sans jamais répondre seules. */
final class MarkSourcesMergeTest extends TestCase
{
    private static function source(?MarkSheet $sheet, bool $overlay = false): MarkSource
    {
        return $overlay
            ? new class ($sheet) implements MarkOverlaySource {
                public function __construct(private readonly ?MarkSheet $sheet)
                {
                }

                public function sheet(string $tenantId, string $establishmentId, string $structureId, string $periodId, string $session, ?string $academicYearId = null): ?MarkSheet
                {
                    return $tenantId === 'T1' ? $this->sheet : null;
                }
            }
        : new class ($sheet) implements MarkSource {
            public function __construct(private readonly ?MarkSheet $sheet)
            {
            }

            public function sheet(string $tenantId, string $establishmentId, string $structureId, string $periodId, string $session, ?string $academicYearId = null): ?MarkSheet
            {
                return $tenantId === 'T1' ? $this->sheet : null;
            }
        };
    }

    /** @param array<string, MarkSource> $sources */
    private static function sources(array $sources): MarkSources
    {
        $container = new Container();
        foreach ($sources as $module => $source) {
            $container->set(MarkSource::PREFIX . $module, [$source]);
        }

        return new MarkSources($container);
    }

    /** UE6 « Stage et mémoire » : l'EC MEM n'a que sa fiche de suivi (CC 12) dans Grades. */
    private static function grades(): MarkSheet
    {
        return new MarkSheet(['p1' => [...F::marie(), F::ue('UE6', [F::ec('MEM', 6, 12, null)])], 'p2' => [F::ue('UE6', [F::ec('MEM', 6, 10, null)])]], ['MF Examen']);
    }

    private static function defense(): MarkSheet
    {
        return new MarkSheet(['p1' => [F::ue('UE6', [F::ec('MEM', 6, null, 14)])], 'p3' => [F::ue('UE6', [F::ec('MEM', 6, null, 15)])]], ['Soutenance à clôturer']);
    }

    public function testLaSoutenanceEntreDansLeCalculDuJuryCasChiffre(): void
    {
        $strategy = new HigherLmdStrategy();
        $without = self::sources(['grades' => self::source(self::grades())])->require('T1', 'E', 'g1', 'S1', 'normal');
        $with = self::sources(['grades' => self::source(self::grades()), 'internships' => self::source(self::defense(), true)])->require('T1', 'E', 'g1', 'S1', 'normal');

        $before = $strategy->computeLearner(F::rules(), $without->units['p1'], 'normal');
        $after = $strategy->computeLearner(F::rules(), $with->units['p1'], 'normal');

        // Avec la soutenance : 0,4 × 12 (suivi) + 0,6 × 14 (soutenance) = 4,80 + 8,40 = 13,20.
        self::assertSame(13.2, $after['subjects']['sub-UE6']);
        self::assertNotSame($before['subjects']['sub-UE6'], $after['subjects']['sub-UE6'], 'Sans PV de soutenance la note du mémoire reste celle de Grades.');
        self::assertSame(array_diff_key($before['subjects'], ['sub-UE6' => 1]), array_diff_key($after['subjects'], ['sub-UE6' => 1]), 'Les autres UE ne bougent pas.');
    }

    public function testSansSoutenanceOuSansNoteDeBaseLaFeuilleNEstPasModifiee(): void
    {
        $alone = self::sources(['grades' => self::source(self::grades())])->require('T1', 'E', 'g1', 'S1', 'normal');
        $empty = self::sources(['grades' => self::source(self::grades()), 'internships' => self::source(null, true)])->require('T1', 'E', 'g1', 'S1', 'normal');
        self::assertEquals($alone, $empty);

        $overlayOnly = self::sources(['internships' => self::source(self::defense(), true)]);
        self::assertNull($overlayOnly->sheet('T1', 'E', 'g1', 'S1', 'normal'), 'Une source complémentaire ne répond jamais seule.');
    }

    public function testPersonnesEnUnionNonVerrouilleesEnUnionEtPasDeLigneOrpheline(): void
    {
        $with = self::sources(['grades' => self::source(self::grades()), 'internships' => self::source(self::defense(), true)])->require('T1', 'E', 'g1', 'S1', 'normal');

        self::assertSame(['p1', 'p2'], array_keys($with->units), 'p3 n\'a aucune note dans Grades : pas de ligne créée par la soutenance.');
        self::assertSame(['MF Examen', 'Soutenance à clôturer'], $with->unlocked);
        self::assertCount(1, $with->units['p2'][0]['groups'][0]['entries'], 'p2 n\'a pas soutenu.');
    }

    public function testDeuxSourcesPrincipalesSontFusionneesEtLesClientsIsoles(): void
    {
        $other = new MarkSheet(['p9' => [F::ue('UE9', [F::ec('XX', 3, 11, 12)])]], ['Autre']);
        $sources = self::sources(['grades' => self::source(self::grades()), 'other' => self::source($other)]);

        $merged = $sources->require('T1', 'E', 'g1', 'S1', 'normal');
        self::assertSame(['p1', 'p2', 'p9'], array_keys($merged->units));
        self::assertNull($sources->sheet('T2', 'E', 'g1', 'S1', 'normal'), 'Aucune note ne fuit vers un autre client.');
    }
}

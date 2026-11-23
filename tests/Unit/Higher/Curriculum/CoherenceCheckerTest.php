<?php

declare(strict_types=1);

namespace Tests\Unit\Higher\Curriculum;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Higher\Curriculum\Domain\CoherenceChecker;

/** EX-MAQ-06, RG-MAQ-2, RG-MAQ-3 : contrôle de cohérence d'une maquette, fonction pure. */
final class CoherenceCheckerTest extends TestCase
{
    private const S1 = [['id' => 's1', 'ordinal' => 1, 'label' => 'S1']];

    /** @return array<string, mixed> */
    private static function unit(string $id, int $credits, ?string $group = null, ?int $count = null): array
    {
        return ['id' => $id, 'semester_id' => 's1', 'code' => strtoupper($id), 'credits' => $credits, 'coefficient' => $credits, 'choice_group' => $group, 'choice_count' => $count, 'national_exam_paper' => null];
    }

    /** @return array<string, mixed> */
    private static function ec(string $unit, string $code, int $credits, int $cmHours = 10): array
    {
        return ['unit_id' => $unit, 'code' => $code, 'credits' => $credits, 'cm_minutes' => $cmHours * 60, 'td_minutes' => 0, 'tp_minutes' => 0, 'personal_minutes' => 0];
    }

    public function testTrenteCreditsAvecGroupeAChoix(): void
    {
        $units = [self::unit('u1', 24), self::unit('o1', 6, 'LV2', 1), self::unit('o2', 6, 'LV2', 1)];
        $elements = [self::ec('u1', 'A', 24), self::ec('o1', 'B', 6), self::ec('o2', 'C', 6)];

        $result = CoherenceChecker::check('lmd', 30, 0, self::S1, $units, $elements, 'REF', true);

        self::assertSame([], $result['errors']);
        self::assertSame(30, $result['semesters'][0]['credits']);
        self::assertSame(30 * 60, $result['semesters'][0]['volume_minutes']);
    }

    public function testErreursBloquantes(): void
    {
        $units = [self::unit('u1', 20), self::unit('u2', 6), self::unit('o1', 3, 'G', 1), self::unit('o2', 4, 'G', 1)];
        $elements = [self::ec('u1', 'A', 18), self::ec('o1', 'B', 3, 0), self::ec('o2', 'C', 4)];

        $errors = CoherenceChecker::check('lmd', 30, 0, self::S1, $units, $elements, null, false)['errors'];

        self::assertSame([
            'Filière sous tutelle : citez la référence du programme approuvé par l\'université de tutelle (RG-MAQ-3).',
            'Filière LMD sans université de tutelle pour l\'année de la maquette (RG-MAQ-3, RG-ORG-8).',
            'S1, groupe à choix G : les options doivent avoir le même nombre de crédits.',
            'UE U1 : 20 crédits déclarés, 18 crédits dans ses EC.',
            'UE U2 sans élément constitutif (EC) : ajoutez au moins un EC.',
            'EC B : aucun volume horaire (CM, TD ou TP).',
            'S1 : 26 crédits au lieu de 30 (RG-MAQ-2).',
        ], $errors);
    }

    public function testCertificatSansControleDeCreditsDeSemestreEtAvertissementDHeures(): void
    {
        $result = CoherenceChecker::check('certificate', 30, 25, self::S1, [self::unit('u1', 2)], [self::ec('u1', 'A', 2, 20)], null, false);

        self::assertSame([], $result['errors']);
        self::assertSame(['EC A : 20 h de travail total pour 2 crédit(s), 50 h attendues (1 crédit = 25 h).'], $result['warnings']);
    }

    public function testMaquetteVideEtSemestreVide(): void
    {
        self::assertSame(['La maquette ne contient aucune UE.', 'S1 : aucune UE.'], CoherenceChecker::check('bts', 30, 25, self::S1, [], [], null, false)['errors']);
    }
}

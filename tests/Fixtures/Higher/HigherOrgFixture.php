<?php

declare(strict_types=1);

namespace Tests\Fixtures\Higher;

use Tests\Support\Higher\InMemoryOrgUnitTree;

/**
 * Organisation type de deux IPES (deux clients) pour les tests Higher. Les agents des sous-modules ajoutent leurs propres
 * fixtures dans ce dossier, préfixées par leur module (ex. GradesMarieFixture.php).
 *
 * Client A (IPES A, établissement E_A) :
 *   école EC > départements EC-CF (filière CF > niveau CF-L1 > groupes CF-L1-G1, CF-L1-G2) et EC-DR (filière DR > DR-L1) ;
 *   école IT > département IT-INFO (filière INFO > INFO-L1).
 * Client B (IPES B, établissement E_B) : école EC (mêmes codes, autre client) > EC-CF > CF.
 */
final class HigherOrgFixture
{
    public const TENANT_A = 'ipes-a';
    public const ESTABLISHMENT_A = 'e-ipes-a';
    public const TENANT_B = 'ipes-b';
    public const ESTABLISHMENT_B = 'e-ipes-b';

    public static function seed(InMemoryOrgUnitTree $tree): void
    {
        $a = [self::TENANT_A, self::ESTABLISHMENT_A];
        $tree->unit(...[...$a, 'EC']);
        $tree->unit(...[...$a, 'EC-CF', 'EC']);
        $tree->unit(...[...$a, 'EC-DR', 'EC']);
        $tree->unit(...[...$a, 'IT']);
        $tree->unit(...[...$a, 'IT-INFO', 'IT']);
        $tree->addStructure(...[...$a, 'CF', 'track', null, 'EC-CF']);
        $tree->addStructure(...[...$a, 'CF-L1', 'level', 'CF', null]);
        $tree->addStructure(...[...$a, 'CF-L1-G1', 'group', 'CF-L1', null]);
        $tree->addStructure(...[...$a, 'CF-L1-G2', 'group', 'CF-L1', null]);
        $tree->addStructure(...[...$a, 'DR', 'track', null, 'EC-DR']);
        $tree->addStructure(...[...$a, 'DR-L1', 'level', 'DR', null]);
        $tree->addStructure(...[...$a, 'INFO', 'track', null, 'IT-INFO']);
        $tree->addStructure(...[...$a, 'INFO-L1', 'level', 'INFO', null]);

        $b = [self::TENANT_B, self::ESTABLISHMENT_B];
        $tree->unit(...[...$b, 'EC']);
        $tree->unit(...[...$b, 'EC-CF', 'EC']);
        $tree->addStructure(...[...$b, 'CF', 'track', null, 'EC-CF']);
    }

    /**
     * Lignes d'une table Higher fictive (une par niveau de l'arbre) : id => [school, department, program, level, group].
     *
     * @return array<string, array{0: string, 1: ?string, 2: ?string, 3: ?string, 4: ?string}>
     */
    public static function records(): array
    {
        return [
            'r-ec' => ['EC', null, null, null, null],
            'r-cf' => ['EC', 'EC-CF', 'CF', null, null],
            'r-cf-g1' => ['EC', 'EC-CF', 'CF', 'CF-L1', 'CF-L1-G1'],
            'r-cf-g2' => ['EC', 'EC-CF', 'CF', 'CF-L1', 'CF-L1-G2'],
            'r-dr' => ['EC', 'EC-DR', 'DR', 'DR-L1', null],
            'r-info' => ['IT', 'IT-INFO', 'INFO', 'INFO-L1', null],
        ];
    }
}

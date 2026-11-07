<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Domain;

/**
 * Répartition proposée des élèves d'un niveau entre plusieurs classes (EX-CLA-04, Could) : effectifs égaux à un élève près, filles et
 * garçons équilibrés, âges équilibrés. Les élèves sont triés par sexe puis par date de naissance et distribués « en serpentin » (aller
 * puis retour sur les classes) : chaque classe reçoit autant de plus âgés que de plus jeunes. Déterministe, sans seuil ni barème.
 */
final class DistributionPlanner
{
    /**
     * @param list<array{id: string, sex: ?string, birth_date: ?string}> $pupils sexe « female » ou « male » (autre ou inconnu : après les garçons)
     * @param list<string> $classIds au moins deux classes
     * @return array<string, list<string>> classe => identifiants des élèves proposés
     */
    public static function plan(array $pupils, array $classIds): array
    {
        $plan = array_fill_keys($classIds, []);
        if ($classIds === []) {
            return $plan;
        }
        usort($pupils, static function (array $left, array $right): int {
            return [self::rank($left['sex']), $left['birth_date'] ?? '9999-12-31', $left['id']] <=> [self::rank($right['sex']), $right['birth_date'] ?? '9999-12-31', $right['id']];
        });
        $count = count($classIds);
        foreach ($pupils as $position => $pupil) {
            $slot = $position % $count;
            if (intdiv($position, $count) % 2 === 1) {
                $slot = $count - 1 - $slot;
            }
            $plan[$classIds[$slot]][] = $pupil['id'];
        }

        return $plan;
    }

    private static function rank(?string $sex): int
    {
        return match ($sex) {
            'female' => 0,
            'male' => 1,
            default => 2,
        };
    }
}

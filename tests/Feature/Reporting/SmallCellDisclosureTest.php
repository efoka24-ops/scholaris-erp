<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Reporting\Contracts\DisclosurePolicy;

final class SmallCellDisclosureTest extends TestCase
{
    public function testLaSuppressionPrimaireEstParametreeEtLeCinqResteUneFixture(): void
    {
        $policy = new FixtureDisclosurePolicy();

        self::assertTrue($policy->suppressPrimary(4, 5));
        self::assertFalse($policy->suppressPrimary(5, 5));
        self::assertFalse($policy->suppressPrimary(4, 3));
    }

    public function testLaSuppressionSecondaireEstDemandeeSansPolitiqueOfficielle(): void
    {
        $policy = new FixtureDisclosurePolicy();

        self::assertSame([], $policy->suppressSecondary(['school-a' => 4, 'school-b' => 8], [], 5));
        self::assertSame(['school-b'], $policy->suppressSecondary(['school-a' => 4, 'school-b' => 8], ['school-a'], 5));
    }
}

/** Politique de démonstration locale aux tests : le seuil 5 n'est pas une valeur de production. */
final class FixtureDisclosurePolicy implements DisclosurePolicy
{
    public function suppressPrimary(int $count, int $minimumCellSize): bool
    {
        return $count < $minimumCellSize;
    }

    public function suppressSecondary(array $counts, array $primarySuppressed, int $minimumCellSize): array
    {
        if ($primarySuppressed === []) {
            return [];
        }

        foreach ($counts as $cellId => $count) {
            if (!in_array($cellId, $primarySuppressed, true) && $count >= $minimumCellSize) {
                return [$cellId];
            }
        }

        return [];
    }
}


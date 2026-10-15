<?php

declare(strict_types=1);

namespace Tests\Unit\Higher\Policies;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Higher\HigherApplication;
use Scholaris\Modules\Apps\Higher\Policies\RoleMatrix;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;

/**
 * Matrice L/E/V du CDC : chaque case est réalisée par les permissions du rôle ; une case « — » refuse toute écriture de la ligne.
 * Les cases sont parcourues dans une boucle (et non un fournisseur de données) : la suite complète reste sous la limite mémoire.
 */
final class RoleMatrixTest extends TestCase
{
    public function testChaqueCaseDeLaMatriceEstAccordee(): void
    {
        $missing = [];
        $count = 0;
        foreach (RoleMatrix::MATRIX as $domain => $row) {
            foreach ($row as $number => [$letter, $permission]) {
                $count++;
                if (!in_array($letter, ['L', 'E', 'V'], true) || !in_array($permission, RoleMatrix::ROLES[$number]['permissions'], true)) {
                    $missing[] = $domain . ' : ' . $number . ' ' . $letter . ' (' . $permission . ')';
                }
            }
        }

        self::assertGreaterThan(70, $count);
        self::assertSame([], $missing);
    }

    public function testUneCaseVideRefuseParDefaut(): void
    {
        $leaks = [];
        foreach (RoleMatrix::MATRIX as $domain => $row) {
            foreach (RoleMatrix::MATRIX_COLUMNS as $number) {
                if (isset($row[$number])) {
                    continue;
                }
                foreach (array_intersect(RoleMatrix::writePermissionsOf($domain), RoleMatrix::ROLES[$number]['permissions']) as $permission) {
                    $leaks[] = $domain . ' : ' . $number . ' — porte ' . $permission;
                }
            }
        }

        self::assertSame([], $leaks);
    }

    public function testLeDirecteurDEcoleValideLesCorrectionsEtLeChefDeDepartementLesHeures(): void
    {
        // C1 : R04 « L » sur Notes alors qu'EX-NOT-06 exige sa validation des corrections.
        self::assertContains('assessment.correction.approve', RoleMatrix::ROLES['R04']['permissions']);
        self::assertContains('higher.hours.validate', RoleMatrix::ROLES['R05']['permissions']);
        self::assertNotContains('assessment.enter', RoleMatrix::ROLES['R01']['permissions'], 'R01 : aucune saisie de notes');
    }

    public function testLePerimetreDAffectationSuitRgOrg2(): void
    {
        self::assertSame(['org_unit'], RoleMatrix::ROLES['R04']['scopes']);
        self::assertSame(['org_unit'], RoleMatrix::ROLES['R05']['scopes']);
        self::assertSame(['structure'], RoleMatrix::ROLES['R06']['scopes']);
        self::assertSame(['structure'], RoleMatrix::ROLES['R22']['scopes']);
        self::assertTrue(RoleMatrix::isExternal('higher_auditeur'));
        self::assertFalse(RoleMatrix::isExternal('higher_jury'));
    }

    public function testLeManifesteDePlateformeEstConformeAuPlan(): void
    {
        $manifest = HigherApplication::manifest();

        self::assertSame(['higher', 'higher', ApplicationManifest::AVAILABLE, '0.1.0', 60000, 90, 15], [
            $manifest->key, $manifest->target, $manifest->status, $manifest->version, $manifest->monthlyPriceXaf, $manifest->trialDays, $manifest->graceDays,
        ]);
        self::assertSame(['people', 'billing', 'messaging', 'documents', 'scheduling', 'attendance', 'assessment', 'staff', 'reporting'], $manifest->requiredModules);
        self::assertSame('étudiant', HigherApplication::descriptor()->vocabulary['learner']);
    }
}

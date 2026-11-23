<?php

declare(strict_types=1);

namespace Tests\Feature\PublicPrimary\Referential;

use Tests\Support\PublicSchools\PublicPrimaryTestCase;

/**
 * FIX-PUB (guide du primaire public) : le directeur (R09) importe le référentiel d'exemple et génère les unités de son école tant
 * que le paramètre `public_primary.referential.school_may_manage` le permet (RG-TER-6 : le ministère peut le réserver au niveau
 * national). Sans unités, l'évaluation par critères reste vide. Données fictives.
 */
final class SchoolReferentialTest extends PublicPrimaryTestCase
{
    protected function setUp(): void
    {
        $this->bootPrimary();
        $this->permissions->grant('director', 'public_primary.referential.school_manage');
    }

    /** @return array<string, mixed> */
    private function generation(): array
    {
        return [
            'year_label' => self::YEAR, 'level_code' => 'CE1',
            'trimesters' => [
                ['period_id' => 'per-t1', 'starts_on' => '2026-09-07', 'ends_on' => '2026-11-27'],
                ['period_id' => 'per-t2', 'starts_on' => '2026-11-30', 'ends_on' => '2027-03-05'],
                ['period_id' => 'per-t3', 'starts_on' => '2027-03-08', 'ends_on' => '2027-06-11'],
            ],
        ];
    }

    public function testLeDirecteurImporteLeReferentielEtGenereLesUnitesDeSonEcole(): void
    {
        [$imported, $frames] = $this->api('POST', '/public-primary/frames/import-example', 'director', ['subsystem' => 'francophone', 'level_code' => 'CE1']);
        self::assertSame(201, $imported->getStatusCode(), (string) $imported->getBody());
        self::assertNotSame([], $frames['data']);

        [$generated, $units] = $this->api('POST', '/public-primary/units/generate', 'director', $this->generation());
        self::assertSame(201, $generated->getStatusCode(), (string) $generated->getBody());
        self::assertCount(8, $units['data']);

        [$read, $list] = $this->api('GET', '/public-primary/units?year_label=' . self::YEAR . '&level_code=CE1', 'teacher2');
        self::assertSame(200, $read->getStatusCode(), (string) $read->getBody());
        self::assertCount(8, $list['data'], 'Le titulaire voit les unités : la saisie par critères n\'est plus vide.');
    }

    public function testLeMinistereReserveLeReferentielAuNiveauNationalParParametre(): void
    {
        $this->settings->values['public_primary.referential.school_may_manage'] = false;

        [$denied, $problem] = $this->api('POST', '/public-primary/units/generate', 'director', $this->generation());
        self::assertSame(403, $denied->getStatusCode());
        self::assertStringContainsString('school_may_manage', (string) ($problem['detail'] ?? ''));

        [$admin] = $this->api('POST', '/public-primary/units/generate', 'admin', $this->generation());
        self::assertSame(201, $admin->getStatusCode(), 'Le niveau national garde la main.');
    }

    public function testLEnseignantNeReglePasLeReferentiel(): void
    {
        [$denied] = $this->api('POST', '/public-primary/units/generate', 'teacher2', $this->generation());

        self::assertSame(403, $denied->getStatusCode());
    }
}

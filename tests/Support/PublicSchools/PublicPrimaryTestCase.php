<?php

declare(strict_types=1);

namespace Tests\Support\PublicSchools;

use Scholaris\Modules\Socle\Identity\Contracts\Scope;

/**
 * Base des tests de la Primaire publique (lot P2) : reprend le jeu de PublicSchoolsTestCase (client t1, écoles e1 et e2, classes c1
 * « CP1 » et c2 « CE1 », titulaires teacher et teacher2, directeur, inspecteurs) et ajoute :
 *  - la fiche de l'école e1 (sous-système francophone, ouverte) ;
 *  - l'élève fictif « Paul E. » (person `paul`) en CE1 (classe c2), garant p-parent3 ;
 *  - les droits du lot P2 : évaluation (teacher2 : write ; director : validate, publish, rectify), passage (teacher2 : write ;
 *    director : validate ; inspector : opinion), référentiel (`admin`, R21, et l'Inspection générale `igen`), APEE et statistiques ;
 *  - un référentiel d'exemple CE1 (Français, Mathématiques, English) et le calendrier 2026-2027 de l'arrêté du 14 août 2026 en
 *    exemple (trois trimestres), avec 8 unités.
 */
abstract class PublicPrimaryTestCase extends PublicSchoolsTestCase
{
    protected const YEAR = '2026-2027';

    /** @var array<string, array{id: string, criteria: array<string, string>}> code de discipline => cadre et critères par code */
    protected array $frames = [];

    /** @var list<array<string, mixed>> */
    protected array $units = [];

    /** @param array<string, mixed> $settings */
    protected function bootPrimary(array $settings = []): void
    {
        $this->bootPublic($settings);
        $this->connections()->pdo()->prepare('INSERT INTO pub_schools (id, tenant_id, establishment_id, official_code, subsystem, cycle, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['01SCHOOLE1000000000000000A', 't1', 'e1', 'SYN-EP-0001', 'francophone', 'primary', 'open', '2026-09-01T08:00:00+01:00', '2026-09-01T08:00:00+01:00']);
        // L'établissement existe aussi en base : les paramètres territoriaux du socle (PP16) le vérifient.
        $this->connections()->pdo()->prepare('INSERT INTO establishments (id, tenant_id, name, type, branding, modules_enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['e1', 't1', 'École publique de Bonamoussadi', 'primary', '{}', '[]', '2026-09-01T08:00:00+01:00', '2026-09-01T08:00:00+01:00']);
        $this->people->add('t1', 'paul', 'Paul', 'Essomba');
        $this->enrollments->add('t1', 'en-paul', 'paul', 'e1', 'c2');
        $this->people->add('t1', 'p-parent3', 'Lucie', 'Essomba', '+237670000003');
        $this->people->link('t1', 'p-parent3', 'paul');

        $this->permissions->grant('teacher2', 'public_primary.evaluation.write', 'public_primary.promotion.write');
        $this->permissions->grant('teacher', 'public_primary.evaluation.write', 'public_primary.promotion.write');
        $this->permissions->grant('director', 'public_primary.evaluation.validate', 'public_primary.evaluation.publish', 'public_primary.evaluation.rectify', 'public_primary.promotion.validate', 'documents.read');
        $this->permissions->grant('inspector', 'public_primary.promotion.opinion');
        $this->permissions->grant('inspector2', 'public_primary.promotion.opinion');
        $this->actor('admin', 'p-admin', ['public_primary.referential.manage', 'settings.manage'], new Scope(Scope::ESTABLISHMENT, 'e1', 'pub_functional_admin'));
        $this->people->add('t1', 'p-admin', 'Ada', 'Admin');
        $this->people->add('t1', 'p-inspector', 'Ines', 'Pecteur');
    }

    /** Importe le référentiel d'exemple CE1 et génère les 8 unités du calendrier 2026-2027 (exemple de test, jamais en dur dans le code). */
    protected function seedReferential(): void
    {
        [$response, $json] = $this->api('POST', '/public-primary/frames/import-example', 'admin', ['subsystem' => 'francophone', 'level_code' => 'CE1', 'valid_from' => '2026-09-01']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        foreach ($json['data'] as $frame) {
            $criteria = [];
            foreach ($frame['criteria'] as $criterion) {
                $criteria[$criterion['code']] = $criterion['id'];
            }
            $this->frames[(string) $frame['discipline_code']] = ['id' => (string) $frame['id'], 'criteria' => $criteria];
        }
        [$units, $unitsJson] = $this->api('POST', '/public-primary/units/generate', 'admin', [
            'year_label' => self::YEAR, 'level_code' => 'CE1',
            'trimesters' => [
                ['period_id' => 'per-t1', 'starts_on' => '2026-09-07', 'ends_on' => '2026-11-27'],
                ['period_id' => 'per-t2', 'starts_on' => '2026-11-30', 'ends_on' => '2027-03-05'],
                ['period_id' => 'per-t3', 'starts_on' => '2027-03-08', 'ends_on' => '2027-06-11'],
            ],
        ]);
        self::assertSame(201, $units->getStatusCode(), (string) $units->getBody());
        $this->units = $unitsJson['data'];
    }

    /** @return string identifiant de l'unité de ce numéro */
    protected function unit(int $number): string
    {
        foreach ($this->units as $unit) {
            if ($unit['unit_no'] === $number) {
                return (string) $unit['id'];
            }
        }
        self::fail('Unité ' . $number . ' absente.');
    }

    protected function criterion(string $discipline, string $code): string
    {
        return $this->frames[$discipline]['criteria'][$code];
    }

    /**
     * Cases d'un élève pour une discipline : critère => [occasion 1, 2, 3] (1 réussi, 0 échoué, null absent).
     *
     * @param array<string, list<int|null>> $grid
     * @return list<array<string, mixed>>
     */
    protected function cellsFor(string $personId, string $discipline, array $grid): array
    {
        $cells = [];
        foreach ($grid as $code => $outcomes) {
            foreach ($outcomes as $index => $outcome) {
                $cells[] = [
                    'person_id' => $personId, 'criterion_id' => $this->criterion($discipline, $code), 'occasion_no' => $index + 1,
                    'outcome' => $outcome === null ? 'absent' : ($outcome === 1 ? 'success' : 'fail'),
                ];
            }
        }

        return $cells;
    }

    /**
     * Cas « Paul E. » du CDC (CE1 B, unité 3) : Français ECA, Mathématiques A, English NA.
     *
     * @return list<array<string, mixed>>
     */
    protected function paulChecks(): array
    {
        return array_merge(
            $this->cellsFor('paul', 'example_francais', ['C1' => [1, 1, 0], 'C2' => [1, 0, 1], 'C3' => [0, 1, 0]]),
            $this->cellsFor('paul', 'example_mathematiques', ['C1' => [1, 1, 1], 'C2' => [1, 1, 0], 'C3' => [1, 0, 1], 'CP' => [1, 1, 0]]),
            $this->cellsFor('paul', 'example_english', ['C1' => [0, 0, 1], 'C2' => [1, 0, 0], 'C3' => [0, 1, 0]]),
        );
    }
}

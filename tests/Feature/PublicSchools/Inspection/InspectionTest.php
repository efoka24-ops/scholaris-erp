<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Inspection;

use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Inspection\Application\VisitBatchHandler;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Sync\Contracts\BatchContext;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItem;
use Tests\Support\PublicSchools\PublicPrimaryTestCase;

/**
 * Lot P3, inspection (T686) : grille d'exemple versionnée (réglée au niveau national), planning, rapport idempotent sans écrasement,
 * comptage comparé au registre (O1), recommandations suivies à la visite suivante, DOC-26 transmis au directeur, lecture par
 * l'enseignant de sa classe seulement, lot hors ligne, signalement de frais illégal. Données fictives.
 */
final class InspectionTest extends PublicPrimaryTestCase
{
    protected function setUp(): void
    {
        $this->bootPrimary();
        $this->permissions->grant('inspector', 'public_schools.inspection.read', 'public_schools.inspection.write', 'documents.read');
        $this->permissions->grant('inspector2', 'public_schools.inspection.read', 'public_schools.inspection.write');
        $this->permissions->grant('director', 'public_schools.inspection.read');
        $this->permissions->grant('teacher', 'public_schools.inspection.read');
        $this->permissions->grant('teacher2', 'public_schools.inspection.read');
        $this->actor('igen', 'p-igen', ['public_schools.inspection.manage'], new Scope(Scope::ESTABLISHMENT, 'e1', 'pub_inspection_general'));
    }

    public function testDeLaGrilleAuSuiviDesRecommandations(): void
    {
        [$denied] = $this->api('POST', '/public/inspection/grids/import-example', 'inspector');
        self::assertSame(403, $denied->getStatusCode(), 'Grilles réglées au niveau national.');
        [$imported, $grid] = $this->api('POST', '/public/inspection/grids/import-example', 'igen');
        self::assertSame(201, $imported->getStatusCode(), (string) $imported->getBody());
        self::assertSame(1, $grid['data']['version']);
        self::assertSame(['preparation', 'delivery', 'evaluation', 'registers'], array_values(array_unique(array_column($grid['data']['items'], 'section'))));
        [, $second] = $this->api('POST', '/public/inspection/grids/import-example', 'igen');
        self::assertSame(2, $second['data']['version'], 'Nouvelle version, la précédente reste attachée aux visites.');
        $gridId = (string) $second['data']['id'];

        [$outsider] = $this->api('POST', '/public/inspection/visits', 'inspector2', ['planned_on' => '2026-10-20']);
        self::assertSame(403, $outsider->getStatusCode(), 'L\'inspecteur d\'un autre arrondissement est refusé.');
        [$planned, $visit] = $this->api('POST', '/public/inspection/visits', 'inspector', ['planned_on' => '2026-10-20', 'structure_id' => 'c1', 'teacher_person_id' => 'p-teacher', 'grid_id' => $gridId]);
        self::assertSame(201, $planned->getStatusCode(), (string) $planned->getBody());
        $visitId = (string) $visit['data']['id'];
        [, $planning] = $this->api('GET', '/public/inspection/planning', 'inspector');
        self::assertCount(1, $planning['data']);

        $report = [
            'client_uuid' => 'SYN-VISIT-0001', 'done_on' => '2026-10-08', 'answers' => ['prep_journal' => 'S', 'reg_attendance' => 'NS'],
            'counted_present' => 2, 'observations' => 'Classe calme (fictif).', 'recommendations' => ['Tenir le cahier d\'appel chaque jour.'],
        ];
        [$badScale] = $this->api('PUT', '/public/inspection/visits/' . $visitId . '/report', 'inspector', ['answers' => ['prep_journal' => 'Z']] + $report);
        self::assertSame(422, $badScale->getStatusCode());
        [$done, $doneJson] = $this->api('PUT', '/public/inspection/visits/' . $visitId . '/report', 'inspector', $report);
        self::assertSame(200, $done->getStatusCode(), (string) $done->getBody());
        self::assertSame(3, $doneJson['data']['registry_count'], 'Effectif du registre de la classe CP1 à la date de visite.');
        self::assertSame(1, $doneJson['data']['headcount_gap']);
        self::assertEquals(33.3, $doneJson['data']['headcount_gap_rate']);
        [, $replay] = $this->api('PUT', '/public/inspection/visits/' . $visitId . '/report', 'inspector', $report);
        self::assertTrue($replay['data']['replayed']);
        [$conflict] = $this->api('PUT', '/public/inspection/visits/' . $visitId . '/report', 'inspector', ['client_uuid' => 'SYN-VISIT-0002'] + $report);
        self::assertSame(409, $conflict->getStatusCode(), 'Un rapport existant n\'est jamais écrasé.');

        [$doc] = $this->api('POST', '/public/inspection/visits/' . $visitId . '/document', 'inspector', []);
        self::assertSame(201, $doc->getStatusCode(), (string) $doc->getBody());

        // L'enseignant visité lit son rapport ; le titulaire d'une autre classe ne le voit pas.
        [, $mine] = $this->api('GET', '/public/inspection/visits', 'teacher');
        self::assertCount(1, $mine['data']);
        [, $other] = $this->api('GET', '/public/inspection/visits', 'teacher2');
        self::assertSame([], $other['data']);
        [$hidden] = $this->api('GET', '/public/inspection/visits/' . $visitId, 'teacher2');
        self::assertSame(404, $hidden->getStatusCode());

        // Visite suivante, hors ligne : la recommandation ouverte est suivie.
        [, $open] = $this->api('GET', '/public/inspection/recommendations?state=open', 'director');
        self::assertCount(1, $open['data']);
        $handler = $this->container()->get(VisitBatchHandler::class);
        $item = new BatchItem('public.inspection_visit', 'SYN-VISIT-0003', ['done_on' => '2026-10-08', 'structure_id' => 'c1', 'grid_id' => $gridId, 'answers' => ['reg_attendance' => 'S'], 'followups' => [['id' => $open['data'][0]['id'], 'state' => 'done', 'note' => 'Cahier tenu (fictif).']]]);
        $context = new BatchContext(new Principal('inspector', 't1'), 'e1', 'device-syn', 'batch-syn');
        self::assertSame('applied', $handler->handle($item, $context)->status);
        self::assertSame('duplicate', $handler->handle($item, $context)->status, 'Un lot rejoué n\'est appliqué qu\'une fois.');
        [, $closed] = $this->api('GET', '/public/inspection/recommendations?state=done', 'director');
        self::assertCount(1, $closed['data']);
    }

    public function testUnFraisIllegalEstSignaleEtTraiteParLInspection(): void
    {
        [$byDirector] = $this->api('POST', '/public/inspection/fee-reports', 'director', ['reported_on' => '2026-10-08']);
        self::assertSame(403, $byDirector->getStatusCode());
        [$created, $json] = $this->api('POST', '/public/inspection/fee-reports', 'inspector', [
            'reported_on' => '2026-10-08', 'reporter_kind' => 'parent', 'fee_label' => 'Frais d\'inscription', 'amount' => 2000, 'description' => 'Somme exigée à l\'inscription (signalement fictif).',
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        [$handled, $handledJson] = $this->api('POST', '/public/inspection/fee-reports/' . $json['data']['id'] . '/handle', 'inspector', ['state' => 'confirmed', 'resolution' => 'Remboursement ordonné (fictif).']);
        self::assertSame(200, $handled->getStatusCode(), (string) $handled->getBody());
        self::assertSame('confirmed', $handledJson['data']['state']);
        [$again] = $this->api('POST', '/public/inspection/fee-reports/' . $json['data']['id'] . '/handle', 'inspector', ['state' => 'dismissed', 'resolution' => 'x']);
        self::assertSame(409, $again->getStatusCode());
    }
}

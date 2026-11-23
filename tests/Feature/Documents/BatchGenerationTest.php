<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use Scholaris\Modules\Socle\Documents\Application\BatchService;
use Tests\Support\Documents\DocumentsTestCase;

final class BatchGenerationTest extends DocumentsTestCase
{
    private const DATA = ['academic_year' => '2026-2027', 'class_name' => '3eA'];

    protected function setUp(): void
    {
        $this->bootDocuments();
        $this->people->add('t1', 'p4', 'Luc', 'Bella');
        $this->people->add('t1', 'p5', 'Rose', 'Tchoua');
    }

    private function types(): void
    {
        // Le type de test est bloquable pour impayé dans cet établissement : on le redéclare (copie du type de la plateforme).
        $this->api('POST', '/document-types', 'alice', 't1', 'e1', [
            'code' => 'attestation_simple', 'label' => 'Attestation de scolarité', 'category' => 'certificate', 'type_letter' => 'A',
            'blockable' => true, 'variables' => ['academic_year', 'class_name'],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function items(string ...$subjects): array
    {
        return array_map(static fn (string $s): array => ['subject_person_id' => $s, 'data' => self::DATA], $subjects);
    }

    public function testLotAvecProgressionParMorceauxEtNumerosUniques(): void
    {
        [$created, $json] = $this->api('POST', '/document-batches', 'alice', 't1', 'e1', ['type' => 'attestation_simple', 'items' => $this->items('p1', 'p2', 'p3', 'p4', 'p5')]);
        $batch = $json['data'];

        self::assertSame(202, $created->getStatusCode());
        self::assertSame('pending', $batch['status']);
        self::assertSame(['processed' => 0, 'total' => 5, 'percent' => 0], $batch['progress']);

        /** @var BatchService $service */
        $service = $this->kernel->container()->get(BatchService::class);
        $step = $service->process('t1', $batch['id'], 2);
        self::assertSame('processing', $step['status']);
        self::assertSame(2, (int) $step['processed']);
        self::assertSame(40, $this->api('GET', '/document-batches/' . $batch['id'], 'alice', 't1')[1]['data']['progress']['percent']);

        $service->process('t1', $batch['id'], 2);
        $done = $service->process('t1', $batch['id'], 2);
        self::assertSame('done', $done['status']);
        self::assertSame(5, (int) $done['processed']);
        self::assertSame(0, (int) $done['failed']);

        $numbers = $this->connections()->pdo()->query("SELECT number FROM documents WHERE issued_via = 'batch' ORDER BY number")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['CLP-26-A-000001', 'CLP-26-A-000002', 'CLP-26-A-000003', 'CLP-26-A-000004', 'CLP-26-A-000005'], $numbers);

        // Retraiter un lot terminé ne produit rien de plus.
        $service->process('t1', $batch['id']);
        self::assertSame(5, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM documents')->fetchColumn());
    }

    public function testLesImpayesSontExclusAvecMotifEtLeLotSeTermine(): void
    {
        $this->types();
        $this->debtors->block('p2');
        $this->debtors->block('p4');

        $batch = $this->api('POST', '/document-batches', 'alice', 't1', 'e1', ['type' => 'attestation_simple', 'items' => $this->items('p1', 'p2', 'p3', 'p4')])[1]['data'];
        self::assertSame(2, $batch['total']);
        self::assertSame(2, $batch['excluded']);

        [$res, $excluded] = $this->api('GET', '/document-batches/' . $batch['id'] . '/excluded', 'alice', 't1');
        self::assertSame(200, $res->getStatusCode());
        self::assertSame(['p2', 'p4'], array_column($excluded['data'], 'subject_person_id'));
        self::assertSame(2, $excluded['meta']['count']);
        self::assertStringContainsString('impayée', $excluded['data'][0]['reason']);

        $done = $this->api('POST', '/document-batches/' . $batch['id'] . '/process', 'alice', 't1')[1]['data'];
        self::assertSame('done', $done['status']);
        self::assertSame(100, $done['progress']['percent']);
        $holders = $this->connections()->pdo()->query('SELECT subject_person_id FROM documents ORDER BY subject_person_id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['p1', 'p3'], $holders);
    }

    public function testTypeNonBloquableNExclutPersonne(): void
    {
        $this->debtors->block('p2');
        $batch = $this->api('POST', '/document-batches', 'alice', 't1', 'e1', ['type' => 'attestation_simple', 'items' => $this->items('p1', 'p2')])[1]['data'];

        self::assertSame(2, $batch['total']);
        self::assertSame(0, $batch['excluded']);
    }

    public function testUneLigneEnEchecNArretePasLeLot(): void
    {
        $batch = $this->api('POST', '/document-batches', 'alice', 't1', 'e1', ['type' => 'attestation_simple', 'items' => $this->items('p1', 'p2', 'p3')])[1]['data'];
        $this->qr->fail = true;
        $this->kernel->container()->get(BatchService::class)->process('t1', $batch['id'], 1);
        $this->qr->fail = false;
        $done = $this->kernel->container()->get(BatchService::class)->process('t1', $batch['id']);

        self::assertSame('done', $done['status']);
        self::assertSame(1, (int) $done['failed']);
        self::assertSame(3, (int) $done['processed']);
        self::assertSame(['CLP-26-A-000001', 'CLP-26-A-000002'], $this->connections()->pdo()->query('SELECT number FROM documents ORDER BY number')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testLotInvalideRefuse(): void
    {
        self::assertSame(422, $this->api('POST', '/document-batches', 'alice', 't1', 'e1', ['type' => 'attestation_simple', 'items' => []])[0]->getStatusCode());
        self::assertSame(422, $this->api('POST', '/document-batches', 'alice', 't1', 'e1', ['type' => 'attestation_simple', 'items' => $this->items('p1', 'p1')])[0]->getStatusCode());
        self::assertSame(422, $this->api('POST', '/document-batches', 'alice', 't1', 'e1', ['type' => 'attestation_simple', 'items' => $this->items('p9')])[0]->getStatusCode());
        self::assertSame(422, $this->api('POST', '/document-batches', 'alice', 't1', 'e1', ['type' => 'attestation_simple', 'items' => [['subject_person_id' => 'p1', 'data' => ['moyenne' => 12]]]])[0]->getStatusCode());
        self::assertSame(422, $this->api('POST', '/document-batches', 'alice', 't1', 'e1', ['type' => 'inconnu', 'items' => $this->items('p1')])[0]->getStatusCode());
    }

    public function testDemandeEnLigneCreeUneDemandeEnAttentePuEstTraitee(): void
    {
        $this->access->grant('parent1', 'e1');
        $this->permissions->grant('parent1', 'documents.request');

        [$res, $json] = $this->api('POST', '/document-requests', 'parent1', 't1', 'e1', ['type' => 'attestation_simple', 'subject_person_id' => 'p1', 'note' => 'Pour la bourse']);
        self::assertSame(201, $res->getStatusCode());
        self::assertSame('pending', $json['data']['status']);
        self::assertSame(0, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM documents')->fetchColumn());
        self::assertSame(403, $this->api('GET', '/document-requests', 'parent1', 't1')[0]->getStatusCode());

        $fulfilled = $this->api('POST', '/document-requests/' . $json['data']['id'] . '/fulfill', 'alice', 't1', 'e1', ['data' => self::DATA])[1]['data'];
        self::assertSame('fulfilled', $fulfilled['status']);
        self::assertNotNull($fulfilled['document_id']);
        self::assertSame('request', $this->api('GET', '/documents/' . $fulfilled['document_id'], 'alice', 't1')[1]['data']['issued_via']);
        self::assertSame(409, $this->api('POST', '/document-requests/' . $json['data']['id'] . '/reject', 'alice', 't1')[0]->getStatusCode());
        self::assertSame(422, $this->api('POST', '/document-requests', 'parent1', 't1', 'e1', ['type' => 'attestation_simple', 'subject_person_id' => 'p9'])[0]->getStatusCode());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Domain\DocumentKind;
use Tests\Support\People\PeopleTestCase;

/** Inscription : brouillon, autorisation de la structure, garant, pièces, numéro interne, statuts. */
final class EnrollmentTest extends PeopleTestCase
{
    /** @var array<string, mixed> */
    private array $learner = [];

    protected function setUp(): void
    {
        $this->bootPeople();
        $this->learner = $this->person('Nadia', 'Foka', [
            'birth_date' => '2010-05-14', 'birth_place' => 'Douala', 'gender' => 'female', 'nationality' => 'Camerounaise', 'phone' => '677123456',
        ]);
    }

    private function guarantor(): void
    {
        $parent = $this->person('Jean', 'Foka');
        $this->api('POST', '/people/' . $this->learner['id'] . '/relations', body: ['related_person_id' => $parent['id'], 'kind' => 'financial_guarantor']);
    }

    private function allDocuments(): void
    {
        foreach (DocumentKind::required() as $kind) {
            $this->upload('/people/' . $this->learner['id'] . '/documents', $this->pdfContent(), ['kind' => $kind]);
        }
    }

    /** @return array<string, mixed> */
    private function draft(?string $structure = 's1', ?string $year = 'y2026'): array
    {
        return $this->api('POST', '/enrollments', body: ['person_id' => $this->learner['id'], 'structure_id' => $structure, 'academic_year_id' => $year])[1]['data'];
    }

    public function testLeBrouillonSEnregistreEnContinuAvecSonTauxDeCompletude(): void
    {
        [$response, $body] = $this->api('POST', '/enrollments', body: ['person_id' => $this->learner['id']]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('draft', $body['data']['status']);
        self::assertNull($body['data']['internal_number']);
        self::assertSame(25, $body['data']['completeness'], 'Identité complète, ni garant, ni filière, ni pièce.');

        $this->guarantor();
        [, $saved] = $this->api('PATCH', '/enrollments/' . $body['data']['id'], body: ['structure_id' => 's1', 'academic_year_id' => 'y2026']);
        self::assertSame(75, $saved['data']['completeness']);
        self::assertSame(200, $this->code('GET', '/enrollments/' . $body['data']['id']));
        self::assertSame(204, $this->code('DELETE', '/enrollments/' . $body['data']['id']));
    }

    public function testInscriptionRefuseSiLaStructureNAPasDAutorisationValide(): void
    {
        [$response, $body] = $this->api('POST', '/enrollments', body: ['person_id' => $this->learner['id'], 'structure_id' => 'fermee']);
        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('structure_id', $body['errors']);

        $this->guarantor();
        $draft = $this->draft();
        $this->structures->open = [];
        [$validation, $refused] = $this->api('POST', '/enrollments/' . $draft['id'] . '/validate');
        self::assertSame(422, $validation->getStatusCode(), 'L\'autorisation a expiré entre-temps.');
        self::assertArrayHasKey('structure_id', $refused['errors']);
        self::assertSame('draft', $this->api('GET', '/enrollments/' . $draft['id'])[1]['data']['status']);
        self::assertSame(0, $this->numbers());
    }

    private function numbers(): int
    {
        return (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM enrollments WHERE internal_number IS NOT NULL')->fetchColumn();
    }

    public function testUnMineurExigeUnGarantAvantValidation(): void
    {
        $draft = $this->draft();

        [$response, $body] = $this->api('POST', '/enrollments/' . $draft['id'] . '/validate');

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('person_id', $body['errors']);
        self::assertSame(422, $this->code('POST', '/enrollments/' . $this->draft(null, null)['id'] . '/validate'));
    }

    public function testValidationSousReserveSiDesPiecesManquentPuisActive(): void
    {
        $this->guarantor();
        $draft = $this->draft();

        [$response, $body] = $this->api('POST', '/enrollments/' . $draft['id'] . '/validate');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('conditional', $body['data']['status']);
        self::assertSame('MAT-2026-00001', $body['data']['internal_number']);
        self::assertSame('2026-11-07', $body['data']['documents_due_on'], '30 jours pour fournir les pièces.');
        self::assertSame(409, $this->code('POST', '/enrollments/' . $draft['id'] . '/validate'));
        self::assertSame(409, $this->code('PATCH', '/enrollments/' . $draft['id'], body: ['structure_id' => 's1']));
        self::assertSame(409, $this->code('DELETE', '/enrollments/' . $draft['id']));

        $identifiers = $this->api('GET', '/people/' . $this->learner['id'] . '/identifiers')[1]['data'];
        self::assertSame('MAT-2026-00001', $identifiers[0]['value']);
        self::assertSame('e1', $identifiers[0]['establishment_id']);
        $history = $this->api('GET', '/people/' . $this->learner['id'] . '/schooling-history')[1]['data'];
        self::assertSame('Collège Les Palmiers', $history[0]['school_name']);

        $this->allDocuments();
        self::assertSame(200, $this->code('POST', '/enrollments/' . $draft['id'] . '/status', body: ['status' => 'active']));
        self::assertSame(100, $this->api('GET', '/enrollments/' . $draft['id'])[1]['data']['completeness']);
    }

    public function testDossierCompletDirectementActifEtNumerotationContinue(): void
    {
        $this->guarantor();
        $this->allDocuments();
        $first = $this->api('POST', '/enrollments/' . $this->draft()['id'] . '/validate')[1]['data'];
        self::assertSame('active', $first['status']);
        self::assertNull($first['documents_due_on']);

        $other = $this->person('Paul', 'Ndi', ['birth_date' => '1999-01-01']);
        $second = $this->api('POST', '/enrollments', body: ['person_id' => $other['id'], 'structure_id' => 's1', 'academic_year_id' => 'y2026'])[1]['data'];
        self::assertSame('MAT-2026-00002', $this->api('POST', '/enrollments/' . $second['id'] . '/validate')[1]['data']['internal_number'], 'Majeur : pas de garant exigé.');
    }

    public function testUneSeuleInscriptionEnCoursParAnneeEtEtablissement(): void
    {
        $this->draft();

        self::assertSame(409, $this->code('POST', '/enrollments', body: ['person_id' => $this->learner['id'], 'academic_year_id' => 'y2026']));
        self::assertSame(201, $this->code('POST', '/enrollments', body: ['person_id' => $this->learner['id'], 'academic_year_id' => 'y2027']));
    }

    public function testStatutsEtAnnuaireDInscriptions(): void
    {
        $this->guarantor();
        $this->allDocuments();
        $enrollment = $this->api('POST', '/enrollments/' . $this->draft()['id'] . '/validate')[1]['data'];
        /** @var EnrollmentDirectory $directory */
        $directory = $this->kernel->container()->get(EnrollmentDirectory::class);

        $info = $directory->find('t1', (string) $enrollment['id']);
        self::assertInstanceOf(EnrollmentInfo::class, $info);
        self::assertSame(['s1', 'y2026', 'active'], [$info->structureId, $info->academicYearId, $info->status]);
        self::assertSame([(string) $this->learner['id']], $directory->personIdsInStructure('t1', 's1'));
        self::assertSame([(string) $this->learner['id']], $directory->personIdsInEstablishment('t1', 'e1'));
        self::assertSame([], $directory->personIdsInStructure('t2', 's1'));
        self::assertNull($directory->find('t2', (string) $enrollment['id']));

        self::assertSame(200, $this->code('POST', '/enrollments/' . $enrollment['id'] . '/status', body: ['status' => 'withdrawn']));
        self::assertSame(409, $this->code('POST', '/enrollments/' . $enrollment['id'] . '/status', body: ['status' => 'active']));
        self::assertSame([], $directory->personIdsInStructure('t1', 's1'), 'Une inscription retirée n\'est plus active.');
        self::assertCount(1, $directory->forPerson('t1', (string) $this->learner['id']));
        self::assertSame(1, count($this->api('GET', '/enrollments?filter[status]=withdrawn')[1]['data']));
        self::assertSame(0, count($this->api('GET', '/enrollments?filter[status]=active')[1]['data']));
    }
}

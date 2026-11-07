<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

/**
 * Mise en place d'un client complet PAR L'API (aucune insertion SQL) : client et super administrateur (outil d'installation),
 * connexion avec 2FA, établissement, année, trimestres, classe et son autorisation administrative, comptes du personnel
 * activés par SMS. Chaque identifiant renvoyé par un appel sert à l'appel suivant.
 *
 * @mixin RealStackTestCase
 */
trait BuildsSchool
{
    protected string $tenant = '';
    protected string $admin = '';
    protected string $school = '';
    protected string $year = '';
    /** @var list<string> */
    protected array $periods = [];
    protected string $class = '';

    protected function buildSchool(string $clientName = 'Groupe scolaire Recette', string $schoolName = 'Collège Recette'): void
    {
        $client = $this->createClient($clientName, 'Awa', 'Admin');
        $this->tenant = $client['tenant_id'];
        $this->admin = $this->signIn($client['login'], $client['password']);

        $this->school = (string) $this->ok('POST', '/establishments', $this->admin, ['name' => $schoolName, 'type' => 'secondary'], null, [], 201)['data']['id'];
        $this->year = (string) $this->ok('POST', '/academic-years', $this->admin, ['label' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'], $this->school, [], 201)['data']['id'];
        $this->periods = [];
        foreach ([[1, '2026-09-01', '2026-12-18'], [2, '2027-01-04', '2027-03-26'], [3, '2027-04-06', '2027-06-30']] as [$ordinal, $from, $to]) {
            $this->periods[] = (string) $this->ok('POST', '/periods', $this->admin, [
                'academic_year_id' => $this->year, 'kind' => 'trimester', 'ordinal' => $ordinal, 'starts_on' => $from, 'ends_on' => $to,
            ], $this->school, [], 201)['data']['id'];
        }
        $this->class = (string) $this->ok('POST', '/structures', $this->admin, ['kind' => 'class', 'name' => '3e A', 'capacity' => 50], $this->school, [], 201)['data']['id'];
        $this->ok('POST', '/authorizations', $this->admin, [
            'structure_id' => $this->class, 'reference' => 'ARR-3E-2026', 'authority' => 'MINESEC', 'valid_from' => '2026-01-01', 'valid_to' => '2029-12-31',
        ], $this->school, [], 201);
    }

    /**
     * Inscrit un apprenant et son garant financier : fiche, relation, inscription, validation (numéro interne).
     *
     * @return array{learner: string, guarantor: string, enrollment: string}
     */
    protected function enrollLearner(string $first, string $last, string $birth, ?string $guarantorPerson = null, bool $completeFile = true, string $gender = 'female'): array
    {
        $learner = (string) $this->ok('POST', '/people', $this->admin, ['first_name' => $first, 'last_name' => $last, 'birth_date' => $birth, 'gender' => $gender], $this->school, [], 201)['data']['id'];
        $guarantor = $guarantorPerson ?? (string) $this->ok('POST', '/people', $this->admin, ['first_name' => 'Garant', 'last_name' => $last, 'phone' => $this->nextPhone()], $this->school, [], 201)['data']['id'];
        $this->ok('POST', '/people/' . $learner . '/relations', $this->admin, ['related_person_id' => $guarantor, 'kind' => 'financial_guarantor'], $this->school);
        // Consentement aux communications du garant, recueilli à l'inscription (sans lui, aucun SMS ne part).
        [$consent] = $this->api('POST', '/consents', $this->admin, ['person_id' => $guarantor, 'purpose' => 'communication'], $this->school);
        self::assertContains($consent->getStatusCode(), [201, 409], (string) $consent->getBody());
        if ($completeFile) {
            foreach (['birth_certificate', 'id_photo', 'previous_report', 'medical_certificate', 'vaccination_card', 'guardian_id', 'residence_proof'] as $kind) {
                $this->uploadDocument($learner, $kind);
            }
        }
        $enrollment = (string) $this->ok('POST', '/enrollments', $this->admin, ['person_id' => $learner, 'structure_id' => $this->class, 'academic_year_id' => $this->year], $this->school, [], 201)['data']['id'];
        $validated = $this->ok('POST', '/enrollments/' . $enrollment . '/validate', $this->admin, [], $this->school);
        self::assertSame($completeFile ? 'active' : 'conditional', $validated['data']['status'], json_encode($validated));

        return ['learner' => $learner, 'guarantor' => $guarantor, 'enrollment' => $enrollment];
    }

    /** Pièce du dossier téléversée en multipart (PDF minimal). */
    protected function uploadDocument(string $personId, string $kind): void
    {
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF";
        $request = (new \Nyholm\Psr7\ServerRequest('POST', '/api/v1/people/' . $personId . '/documents', [
            'Authorization' => 'Bearer ' . $this->admin, 'X-Establishment-Id' => $this->school,
        ]))->withParsedBody(['kind' => $kind, 'expires_on' => '2028-12-31'])
            ->withUploadedFiles(['file' => new \Nyholm\Psr7\UploadedFile(\Nyholm\Psr7\Stream::create($pdf), strlen($pdf), UPLOAD_ERR_OK, $kind . '.pdf', 'application/pdf')]);
        [$response] = $this->send($request);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }
}

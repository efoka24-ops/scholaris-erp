<?php

declare(strict_types=1);

namespace Tests\E2E;

use Nyholm\Psr7\ServerRequest;
use Tests\E2E\Support\BuildsSchool;
use Tests\E2E\Support\RealStackTestCase;
use ZipArchive;

/**
 * Parcours 4 (US6, US5, US10, FR-016) : document officiel PDF avec QR (Dompdf et QR réels), vérification publique puis
 * révocation, lot de documents ; messagerie interne parent-enseignant ; clôture d'année demandée puis approuvée par une
 * autre personne ; export complet du client sans aucune donnée d'un autre client.
 */
final class DocumentsMessagingClosureJourneyTest extends RealStackTestCase
{
    use BuildsSchool;

    protected function setUp(): void
    {
        $this->bootRealStack();
        $this->buildSchool();
    }

    public function testDocumentPdfAvecQrVerificationPubliqueRevocationEtLot(): void
    {
        $learners = [];
        foreach (['Ada', 'Bea', 'Cléo'] as $i => $first) {
            $learners[] = $this->enrollLearner($first, 'Nkolo', '2011-02-0' . ($i + 1))['learner'];
        }
        $body = ['type' => 'attestation_simple', 'subject_person_id' => $learners[0], 'data' => ['academic_year' => '2026-2027', 'class_name' => '3e A']];

        [$noKey] = $this->api('POST', '/documents', $this->admin, $body, $this->school);
        self::assertSame(400, $noKey->getStatusCode(), 'Idempotency-Key obligatoire sur POST /documents.');
        $doc = $this->ok('POST', '/documents', $this->admin, $body, $this->school, ['Idempotency-Key' => 'recette-doc-0001'], 201)['data'];
        $again = $this->ok('POST', '/documents', $this->admin, $body, $this->school, ['Idempotency-Key' => 'recette-doc-0001'])['data'];
        self::assertSame($doc['id'], $again['id'], 'Rejeu : même document, pas de second numéro.');

        // PDF réel (Dompdf) contenant l'image du QR.
        [$pdf] = $this->api('GET', '/documents/' . $doc['id'] . '/preview', $this->admin, null, $this->school);
        self::assertSame(200, $pdf->getStatusCode(), (string) $pdf->getBody());
        $bytes = (string) $pdf->getBody();
        self::assertStringStartsWith('%PDF', $bytes);
        // Le QR (SVG) est rendu en vecteurs : des centaines de rectangles « re » dans le flux de la page.
        preg_match_all('#stream?
(.*?)?
endstream#s', $bytes, $streams);
        $rectangles = 0;
        foreach ($streams[1] as $raw) {
            $inflated = @gzuncompress($raw);
            $rectangles += $inflated === false ? 0 : substr_count($inflated, ' re');
        }
        self::assertGreaterThan(100, $rectangles, 'Le PDF contient le code QR.');

        // Vérification publique sans authentification, puis révocation.
        $token = (string) $doc['verification_token'];
        [$public, $valid] = $this->send(new ServerRequest('GET', '/api/v1/public/verify/' . $token));
        self::assertSame(200, $public->getStatusCode());
        self::assertSame('valid', $valid['data']['status']);
        self::assertStringNotContainsString('birth', json_encode($valid, JSON_THROW_ON_ERROR), 'La page publique ne divulgue pas l\'état civil.');
        $this->ok('POST', '/documents/' . $doc['id'] . '/revoke', $this->admin, ['reason' => 'Erreur de classe'], $this->school);
        [, $revoked] = $this->send(new ServerRequest('GET', '/api/v1/public/verify/' . $token));
        self::assertSame('revoked', $revoked['data']['status']);
        [$unknown] = $this->send(new ServerRequest('GET', '/api/v1/public/verify/' . str_repeat('x', strlen($token))));
        self::assertSame(404, $unknown->getStatusCode());

        // Lot de la classe : traité, numéros uniques, chaque document vérifiable.
        $items = array_map(static fn (string $p): array => ['subject_person_id' => $p, 'data' => ['academic_year' => '2026-2027', 'class_name' => '3e A']], $learners);
        $batch = $this->ok('POST', '/document-batches', $this->admin, ['type' => 'attestation_simple', 'items' => $items], $this->school, [], 202)['data'];
        $done = $this->ok('POST', '/document-batches/' . $batch['id'] . '/process', $this->admin, [], $this->school)['data'];
        self::assertSame('done', $done['status'], json_encode($done));
        $registry = $this->ok('GET', '/documents?per_page=10', $this->admin, null, $this->school)['data'];
        $numbers = array_column($registry, 'number');
        self::assertCount(4, $numbers);
        self::assertSame($numbers, array_unique($numbers), 'Numéros uniques.');
        [$xlsx] = $this->api('GET', '/documents/export?format=xlsx', $this->admin, null, $this->school);
        self::assertSame(200, $xlsx->getStatusCode(), (string) $xlsx->getBody());
        self::assertStringStartsWith('PK', (string) $xlsx->getBody());
    }

    public function testMessagerieInterneParentEnseignant(): void
    {
        $parent = $this->provisionUser($this->admin, $this->school, 'Marie', 'Abega', 'parent');
        $learner = $this->enrollLearner('Ada', 'Abega', '2011-01-10', $parent['person_id'])['learner'];
        $this->ok('POST', '/people/' . $learner . '/relations', $this->admin, ['related_person_id' => $parent['person_id'], 'kind' => 'parent'], $this->school);
        $teacher = $this->provisionUser($this->admin, $this->school, 'Tano', 'Prof', 'enseignant');
        $this->ok('POST', '/timetable-slots', $this->admin, ['subject_label' => 'Français', 'teacher_person_id' => $teacher['person_id'], 'structure_ids' => [$this->class], 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '10:00', 'valid_from' => '2026-09-01'], $this->school, [], 201);

        // Dossier du personnel (RH) : l'enseignant appartient à l'établissement.
        $this->ok('POST', '/staff', $this->admin, ['person_id' => $teacher['person_id'], 'kind' => 'permanent'], $this->school, [], 201);

        $parentToken = $this->signIn($parent['login'], $parent['password']);
        $contacts = $this->ok('GET', '/conversation-contacts?learner_id=' . $learner, $parentToken, null, $this->school);
        self::assertStringContainsString($teacher['person_id'], json_encode($contacts, JSON_THROW_ON_ERROR), 'L\'enseignant de l\'enfant est un contact du parent.');
        $conversation = $this->ok('POST', '/conversations', $parentToken, [
            'subject' => 'Suivi d\'Ada', 'kind' => 'teacher_parent', 'learner_id' => $learner, 'participant_ids' => [$teacher['person_id']], 'body' => 'Bonjour, puis-je vous rencontrer ?',
        ], $this->school, [], 201)['data'];

        $teacherToken = $this->signIn($teacher['login'], $teacher['password']);
        $inbox = $this->ok('GET', '/conversations', $teacherToken, null, $this->school)['data'];
        self::assertSame([$conversation['id']], array_column($inbox, 'id'));
        $this->ok('POST', '/conversations/' . $conversation['id'] . '/messages', $teacherToken, ['body' => 'Oui, jeudi à 15 h.'], $this->school, [], 201);
        $thread = $this->ok('GET', '/conversations/' . $conversation['id'] . '/messages', $parentToken, null, $this->school)['data'];
        self::assertEqualsCanonicalizing(['Bonjour, puis-je vous rencontrer ?', 'Oui, jeudi à 15 h.'], array_column($thread, 'body'), 'Fil complet (plus récent en premier).');

        // Un autre parent ne lit pas la conversation.
        $stranger = $this->provisionUser($this->admin, $this->school, 'Zoé', 'Autre', 'parent');
        [$denied] = $this->api('GET', '/conversations/' . $conversation['id'] . '/messages', $this->signIn($stranger['login'], $stranger['password']), null, $this->school);
        self::assertContains($denied->getStatusCode(), [403, 404]);
    }

    public function testClotureDemandeeEtApprouveeParUneAutrePersonnePuisExportCompletDuClient(): void
    {
        $this->enrollLearner('Ada', 'Abega', '2011-01-10');
        $next = (string) $this->ok('POST', '/academic-years', $this->admin, ['label' => '2027-2028', 'starts_on' => '2027-09-01', 'ends_on' => '2028-06-30'], $this->school, [], 201)['data']['id'];
        $check = $this->ok('GET', '/academic-years/' . $this->year . '/closure-check', $this->admin, null, $this->school)['data'];
        self::assertArrayHasKey('checks', $check);

        $requested = $this->ok('POST', '/academic-years/' . $this->year . '/close', $this->admin, ['next_academic_year_id' => $next], $this->school);
        self::assertNotSame('closed', $requested['data']['status'] ?? null, 'Une demande seule ne clôture pas.');
        [$self] = $this->api('POST', '/academic-years/' . $this->year . '/close/approve', $this->admin, [], $this->school);
        self::assertSame(403, $self->getStatusCode(), 'Le demandeur ne peut pas approuver sa propre demande.');

        $director = $this->provisionUser($this->admin, $this->school, 'Dina', 'Directrice', 'directeur');
        $approved = $this->ok('POST', '/academic-years/' . $this->year . '/close/approve', $this->signIn($director['login'], $director['password']), [], $this->school);
        self::assertSame('closed', $approved['data']['status'], json_encode($approved));

        // Un autre client existe avec ses propres données.
        $other = $this->createClient('Autre groupe', 'Boris', 'Intrus');
        $otherToken = $this->signIn($other['login'], $other['password']);
        $otherSchool = (string) $this->ok('POST', '/establishments', $otherToken, ['name' => 'École Intruse', 'type' => 'primary'], null, [], 201)['data']['id'];
        $this->ok('POST', '/people', $otherToken, ['first_name' => 'Secret', 'last_name' => 'Intrus', 'birth_date' => '2012-01-01'], $otherSchool, [], 201);

        // Export complet du client : archive ZIP de JSON, uniquement ses données.
        [$zip] = $this->api('POST', '/privacy/tenant-export', $this->admin, []);
        self::assertSame(200, $zip->getStatusCode(), substr((string) $zip->getBody(), 0, 500));
        $file = $this->storage . '/export.zip';
        file_put_contents($file, (string) $zip->getBody());
        $archive = new ZipArchive();
        self::assertTrue($archive->open($file) === true, 'Archive ZIP lisible.');
        $all = '';
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $all .= (string) $archive->getFromIndex($i);
        }
        $archive->close();
        self::assertStringContainsString('Abega', $all, 'L\'export contient les personnes du client.');
        self::assertStringNotContainsString('Intrus', $all, 'Aucune donnée d\'un autre client.');
        self::assertStringNotContainsString($other['tenant_id'], $all);
        self::assertStringNotContainsString('password_hash', $all, 'Aucun secret dans l\'export.');
    }
}

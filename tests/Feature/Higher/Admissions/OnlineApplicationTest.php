<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Admissions;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Scholaris\Modules\Socle\Identity\Contracts\ApplicantRegistration;
use Scholaris\Modules\Socle\People\Contracts\AttachmentStore;
use Scholaris\Modules\Socle\People\Infrastructure\FileAttachmentStore;
use Scholaris\Modules\Socle\People\Infrastructure\FileStorage;
use Scholaris\Modules\Socle\People\Infrastructure\HeuristicFileScanner;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\Higher\Admissions\EntryTestCase;

/** Candidature en ligne : lien public, inscription, pièces téléversées, frais, suivi ; isolation du candidat. */
final class OnlineApplicationTest extends EntryTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF";
    private const CAND2 = 'cand2';
    private const PAUL = 'p-paul';

    private string $storage = '';
    /** @var list<array<string, string>> */
    private array $registrations = [];

    protected function setUp(): void
    {
        $this->bootEntry();
        $this->storage = sys_get_temp_dir() . '/scholaris-adm-' . bin2hex(random_bytes(6));
        $this->kernel->container()->set(AttachmentStore::class, new FileAttachmentStore(new FileStorage($this->storage), new HeuristicFileScanner()));
        $test = $this;
        $this->kernel->container()->set(ApplicantRegistration::class, new class ($test) implements ApplicantRegistration {
            public function __construct(private readonly OnlineApplicationTest $test)
            {
            }

            public function register(string $tenantId, string $establishmentId, string $firstName, string $lastName, string $identifier, string $locale = 'fr'): array
            {
                $this->test->record(['tenant' => $tenantId, 'establishment' => $establishmentId, 'first' => $firstName, 'identifier' => $identifier]);

                return ['channel' => 'sms', 'masked_to' => '+237 6•• •• 00 01', 'expires_in' => 300, 'resend_in' => 60];
            }
        });
        $this->people->add(self::T, self::PAUL, 'Paul', 'Eto', '+237690000002');
        $this->links->link(self::T, self::CAND2, self::PAUL);
        $this->assign(self::T, self::CAND2, self::E, 'higher.application.submit', 'establishment');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storage)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file instanceof \SplFileInfo && $file->isDir() ? rmdir($file->getPathname()) : unlink((string) $file);
            }
            rmdir($this->storage);
        }
        parent::tearDown();
    }

    /** @param array<string, string> $entry */
    public function record(array $entry): void
    {
        $this->registrations[] = $entry;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    private function publicCall(string $method, string $path, ?array $body = null): array
    {
        $response = $this->kernel->handle(new ServerRequest($method, '/api/v1/public/admissions/' . $path, $body === null ? [] : ['Content-Type' => 'application/json'], $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR)));
        $decoded = json_decode((string) $response->getBody(), true);

        return [$response, is_array($decoded) ? $decoded : []];
    }

    private function slug(): string
    {
        return (string) $this->ok('PUT', '/higher/admissions/public-link', ['enabled' => true], self::SCOL)['slug'];
    }

    private function upload(string $applicationId, string $documentId, string $content, string $user = self::CAND): ResponseInterface
    {
        $request = (new ServerRequest('POST', '/api/v1/higher/admissions/applications/' . $applicationId . '/documents/' . $documentId . '/file', [
            'Authorization' => 'Bearer ' . $user . ':' . self::T, 'X-Establishment-Id' => self::E,
        ]))->withUploadedFiles(['file' => new UploadedFile(Stream::create($content), strlen($content), UPLOAD_ERR_OK, 'diplome.exe', 'application/pdf')]);

        return $this->kernel->handle($request);
    }

    public function testLeLienPublicSeGereParLaScolariteEtSeRenouvelle(): void
    {
        $empty = $this->ok('GET', '/higher/admissions/public-link', null, self::SCOL);
        self::assertFalse($empty['exists']);
        $created = $this->ok('PUT', '/higher/admissions/public-link', [], self::SCOL);
        self::assertTrue($created['enabled']);
        self::assertMatchesRegularExpression('/^ipesa-[a-z0-9]{10}$/', (string) $created['slug']);
        self::assertSame('/candidater/' . $created['slug'], $created['path']);

        [$status] = $this->call('PUT', '/higher/admissions/public-link', [], self::CAND);
        self::assertSame(403, $status, 'Un candidat ne gère pas le lien.');

        $rotated = $this->ok('PUT', '/higher/admissions/public-link', ['rotate' => true], self::SCOL);
        self::assertNotSame($created['slug'], $rotated['slug']);
        self::assertSame(404, $this->publicCall('GET', (string) $created['slug'])[0]->getStatusCode(), 'L\'ancien lien est mort.');
        self::assertSame(200, $this->publicCall('GET', (string) $rotated['slug'])[0]->getStatusCode());

        $this->ok('PUT', '/higher/admissions/public-link', ['enabled' => false], self::SCOL);
        self::assertSame(404, $this->publicCall('GET', (string) $rotated['slug'])[0]->getStatusCode(), 'Lien désactivé.');
    }

    public function testPageSansAuthentificationNExposeQueLeStrictNecessaireEtLes404SontNeutres(): void
    {
        $campaign = $this->openCampaign(5, 10000);
        $slug = $this->slug();
        [$response, $body] = $this->publicCall('GET', $slug);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('IPES A', $body['data']['institute']['name']);
        self::assertSame(['institute', 'campaigns'], array_keys($body['data']));
        $item = $body['data']['campaigns'][0];
        self::assertSame($campaign, $item['id']);
        self::assertEqualsCanonicalizing(['id', 'title', 'school', 'program', 'level', 'opens_on', 'closes_on', 'seats', 'application_fee_xaf', 'selection_mode', 'required_documents'], array_keys($item));
        self::assertSame([5, 10000], [$item['seats'], $item['application_fee_xaf']]);
        self::assertNotEmpty($item['required_documents']);
        $json = (string) $response->getBody();
        self::assertStringNotContainsString(self::T, $json, 'Aucun identifiant de client.');
        self::assertStringNotContainsString(self::E, $json);
        self::assertStringNotContainsString('jury', $json);

        $unknown = $this->publicCall('GET', 'inconnu-abcdefghij');
        $malformed = $this->publicCall('GET', 'X');
        foreach ([$unknown, $malformed] as [$r, $problem]) {
            self::assertSame(404, $r->getStatusCode());
            self::assertStringContainsString('problem+json', $r->getHeaderLine('Content-Type'));
            self::assertSame(404, $problem['status']);
        }
        self::assertSame($unknown[1]['detail'], $malformed[1]['detail'], 'Même message pour un lien inconnu ou mal formé.');
    }

    public function testCampagneNonOuverteOuHorsDatesNEstPasListee(): void
    {
        $slug = $this->slug();
        $this->ok('POST', '/higher/admissions/campaigns', [
            'level_id' => $this->org['l1'], 'academic_year_id' => self::YEAR, 'opens_on' => '2026-03-01', 'closes_on' => '2026-10-31', 'seats' => 3, 'application_fee_xaf' => 0,
        ], self::SCOL);
        self::assertSame([], $this->publicCall('GET', $slug)[1]['data']['campaigns'], 'Brouillon : invisible.');
        $this->openCampaign();
        self::assertCount(1, $this->publicCall('GET', $slug)[1]['data']['campaigns']);
        $this->admissionsClock->freeze('2026-11-02');
        self::assertSame([], $this->publicCall('GET', $slug)[1]['data']['campaigns'], 'Après la clôture.');
    }

    public function testApplicationSuspendueOuFermeeRendLeLienNeutre(): void
    {
        $this->openCampaign();
        $slug = $this->slug();
        $this->applications->set(self::T, ApplicationAccess::SUSPENDED);
        self::assertSame(404, $this->publicCall('GET', $slug)[0]->getStatusCode());
    }

    public function testInscriptionDuCandidatLimiteValideeEtPiegeAAbeilles(): void
    {
        $slug = $this->slug();
        $valid = ['first_name' => 'Awa', 'last_name' => 'Candidate', 'identifier' => '+237690000001', 'locale' => 'fr'];
        [$response, $body] = $this->publicCall('POST', $slug . '/register', $valid);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('sms', $body['data']['channel']);
        self::assertSame([['tenant' => self::T, 'establishment' => self::E, 'first' => 'Awa', 'identifier' => '+237690000001']], $this->registrations);

        [$bad, $problem] = $this->publicCall('POST', $slug . '/register', ['first_name' => '', 'last_name' => 'X', 'identifier' => 'a', 'role' => 'directeur']);
        self::assertSame(422, $bad->getStatusCode());
        self::assertArrayHasKey('first_name', $problem['errors']);
        self::assertArrayHasKey('role', $problem['errors'], 'Aucun champ de droits n\'est accepté.');

        $this->publicCall('POST', $slug . '/register', $valid + ['website' => 'http://spam.test']);
        self::assertCount(1, $this->registrations, 'Champ piège rempli : rien n\'est envoyé.');

        self::assertSame(404, $this->publicCall('POST', 'inconnu-abcdefghij/register', $valid)[0]->getStatusCode());
        self::assertCount(1, $this->registrations);
    }

    public function testLInscriptionEstLimiteeParAdresse(): void
    {
        $slug = $this->slug();
        $last = 0;
        for ($i = 0; $i < 11; $i++) {
            $last = $this->publicCall('POST', $slug . '/register', ['first_name' => 'Awa', 'last_name' => 'Candidate', 'identifier' => '+23769000' . sprintf('%04d', $i)])[0]->getStatusCode();
        }

        self::assertSame(429, $last);
        self::assertCount(10, $this->registrations);
    }

    public function testParcoursCompletPiecesTeleverseesFraisEtSuivi(): void
    {
        $campaign = $this->openCampaign(5, 10000);
        $open = $this->ok('GET', '/higher/admissions/open-campaigns', null, self::CAND);
        self::assertSame($campaign, $open[0]['id']);
        [$denied] = $this->call('GET', '/higher/admissions/open-campaigns', null, self::DIR);
        self::assertSame(403, $denied, 'Réservé aux détenteurs du droit de candidature.');

        $application = $this->ok('POST', '/higher/admissions/applications', [
            'campaign_id' => $campaign, 'diploma' => 'bac', 'diploma_series' => 'D', 'diploma_year' => 2026, 'diploma_mention' => 'bien',
        ], self::CAND);
        $id = (string) $application['id'];
        $first = $application['documents'][0];

        // Types et contenus refusés, aucune pièce enregistrée.
        self::assertSame(422, $this->upload($id, $first['id'], "MZ\x90\x00 exécutable")->getStatusCode());
        self::assertSame(422, $this->upload($id, $first['id'], '%PDF-1.4 ' . str_repeat('x', AttachmentStore::MAX_BYTES))->getStatusCode());
        self::assertSame(422, $this->upload($id, $first['id'], "%PDF-1.4\n<< /JavaScript (app.alert(1)) >>")->getStatusCode());
        $followUp = $this->ok('GET', '/higher/admissions/applications/' . $id . '/follow-up', null, self::CAND);
        self::assertSame('missing', $followUp['documents'][0]['status']);

        foreach ($application['documents'] as $document) {
            $response = $this->upload($id, $document['id'], self::PDF);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        }
        $submitted = $this->ok('POST', '/higher/admissions/applications/' . $id . '/submit', null, self::CAND);
        self::assertSame('submitted', $submitted['status']);

        $pending = $this->ok('POST', '/higher/admissions/applications/' . $id . '/fee-payment', ['channel' => 'mtn_momo', 'payer_phone' => '+237670000001'], self::CAND);
        $again = $this->ok('POST', '/higher/admissions/applications/' . $id . '/fee-payment', ['channel' => 'mtn_momo', 'payer_phone' => '+237670000001'], self::CAND);
        self::assertSame($pending['fee']['payment_id'], $again['fee']['payment_id'], 'Double envoi : un seul paiement (idempotence).');
        self::assertCount(1, $this->billing->payments);
        $this->billing->confirm((string) $pending['fee']['payment_id']);
        $this->ok('POST', '/higher/admissions/applications/' . $id . '/fee-payment', [], self::CAND);

        $followUp = $this->ok('GET', '/higher/admissions/applications/' . $id . '/follow-up', null, self::CAND);
        self::assertSame(['complete', 'paid', 10000], [$followUp['status'], $followUp['fee']['status'], $followUp['fee']['amount_xaf']]);
        self::assertNotNull($followUp['fee']['receipt_number'] ?? 'x');
        self::assertSame(0, $followUp['missing_documents']);
        self::assertTrue($followUp['documents'][0]['has_file']);
        self::assertSame('application/pdf', $followUp['documents'][0]['mime']);
        self::assertArrayNotHasKey('score_hundredths', $followUp);
        self::assertArrayNotHasKey('person_id', $followUp);
        self::assertNull($followUp['result']);

        // Pièce refusée par la scolarité : motif visible, dossier à compléter.
        $this->ok('POST', '/higher/admissions/applications/' . $id . '/documents/' . $first['id'] . '/reject', ['reason' => 'Scan illisible.'], self::SCOL);
        $followUp = $this->ok('GET', '/higher/admissions/applications/' . $id . '/follow-up', null, self::CAND);
        self::assertSame(['rejected', 'Scan illisible.', 1, 'submitted'], [$followUp['documents'][0]['status'], $followUp['documents'][0]['rejection_reason'], $followUp['missing_documents'], $followUp['status']]);
        self::assertSame(200, $this->upload($id, $first['id'], self::PDF)->getStatusCode(), 'Le candidat remplace la pièce refusée.');

        // Le fichier est lisible par le candidat et la scolarité.
        foreach ([self::CAND, self::SCOL] as $user) {
            $download = $this->kernel->handle(new ServerRequest('GET', '/api/v1/higher/admissions/applications/' . $id . '/documents/' . $first['id'] . '/file', ['Authorization' => 'Bearer ' . $user . ':' . self::T, 'X-Establishment-Id' => self::E]));
            self::assertSame(200, $download->getStatusCode(), $user);
            self::assertSame(self::PDF, (string) $download->getBody());
            self::assertStringContainsString('attachment;', $download->getHeaderLine('Content-Disposition'));
        }

        // Décision et convocation.
        $this->admit($id);
        $decided = $this->ok('GET', '/higher/admissions/applications/' . $id . '/follow-up', null, self::CAND);
        self::assertSame(['admitted', 'Dossier conforme.'], [$decided['result']['decision'], $decided['result']['reason']]);
        self::assertSame($id, $this->ok('GET', '/higher/admissions/my-applications', null, self::CAND)[0]['id']);
    }

    public function testUnCandidatNeVoitNiNeModifieQueSonDossier(): void
    {
        $campaign = $this->openCampaign();
        $application = $this->ok('POST', '/higher/admissions/applications', ['campaign_id' => $campaign, 'diploma' => 'bac'], self::CAND);
        $id = (string) $application['id'];
        $document = (string) $application['documents'][0]['id'];
        $this->upload($id, $document, self::PDF);

        self::assertSame([], $this->ok('GET', '/higher/admissions/my-applications', null, self::CAND2));
        self::assertSame(404, $this->call('GET', '/higher/admissions/applications/' . $id . '/follow-up', null, self::CAND2)[0]);
        self::assertSame(404, $this->call('GET', '/higher/admissions/applications/' . $id, null, self::CAND2)[0]);
        self::assertSame(404, $this->upload($id, $document, self::PDF, self::CAND2)->getStatusCode());
        self::assertSame(404, $this->call('POST', '/higher/admissions/applications/' . $id . '/submit', null, self::CAND2)[0]);
        self::assertSame(404, $this->call('POST', '/higher/admissions/applications/' . $id . '/withdraw', ['reason' => 'x'], self::CAND2)[0]);
        self::assertSame(404, $this->call('POST', '/higher/admissions/applications/' . $id . '/fee-payment', ['channel' => 'orange_money', 'payer_phone' => '+237690000002'], self::CAND2)[0]);
        $download = $this->kernel->handle(new ServerRequest('GET', '/api/v1/higher/admissions/applications/' . $id . '/documents/' . $document . '/file', ['Authorization' => 'Bearer ' . self::CAND2 . ':' . self::T, 'X-Establishment-Id' => self::E]));
        self::assertSame(404, $download->getStatusCode());

        // Autre client et autre établissement : refusé ou introuvable.
        $other = $this->kernel->handle(new ServerRequest('GET', '/api/v1/higher/admissions/applications/' . $id . '/follow-up', ['Authorization' => 'Bearer ' . self::CAND . ':' . self::TB, 'X-Establishment-Id' => self::EB]));
        self::assertContains($other->getStatusCode(), [403, 404]);
    }
}

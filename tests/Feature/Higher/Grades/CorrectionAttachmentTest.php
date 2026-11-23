<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Grades;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Scholaris\Modules\Socle\People\Contracts\AttachmentStore;
use Scholaris\Modules\Socle\People\Infrastructure\FileAttachmentStore;
use Scholaris\Modules\Socle\People\Infrastructure\FileStorage;
use Scholaris\Modules\Socle\People\Infrastructure\HeuristicFileScanner;
use Tests\Support\Higher\Grades\GradesTestCase;

/** API-12 : pièce justificative téléversée (type réel, taille, analyse), liée à la demande, jamais lisible hors périmètre ni par un autre client. */
final class CorrectionAttachmentTest extends GradesTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF";

    private string $storage = '';
    private AttachmentStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGrades();
        $this->storage = sys_get_temp_dir() . '/scholaris-hg-' . bin2hex(random_bytes(6));
        $this->store = new FileAttachmentStore(new FileStorage($this->storage), new HeuristicFileScanner());
        $this->kernel->container()->set(AttachmentStore::class, $this->store);
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
        unset($this->store);
        parent::tearDown();
    }

    /**
     * @param array<string, string> $fields
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function upload(string $markId, string $content, array $fields = ['score' => '11', 'reason' => 'Erreur de report.']): array
    {
        $request = (new ServerRequest('POST', '/api/v1/higher/grade-marks/' . $markId . '/changes', [
            'Authorization' => 'Bearer ' . self::TEACHER . ':' . self::T, 'X-Establishment-Id' => self::E,
        ]))->withParsedBody($fields)
            ->withUploadedFiles(['attachment' => new UploadedFile(Stream::create($content), strlen($content), UPLOAD_ERR_OK, 'copie.exe', 'application/pdf')]);
        $response = $this->kernel->handle($request);
        $decoded = json_decode((string) $response->getBody(), true);

        return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
    }

    private function download(string $correctionId, string $user, string $tenant = self::T, string $establishment = self::E): ResponseInterface
    {
        return $this->kernel->handle(new ServerRequest('GET', '/api/v1/higher/grade-corrections/' . $correctionId . '/attachment', [
            'Authorization' => 'Bearer ' . $user . ':' . $tenant, 'X-Establishment-Id' => $establishment,
        ]));
    }

    public function testEnvoiStockeLaPieceEtLaRendTelechargeablePourLesRolesQuiVoientLaDemande(): void
    {
        $a = (string) $this->lockedAssessment(8.0)['id'];
        [$status, $body] = $this->upload($this->line($a, 'p1')['mark_id'], self::PDF);

        self::assertSame(201, $status, json_encode($body, JSON_UNESCAPED_UNICODE) ?: '');
        $correction = $body['data'];
        self::assertSame(['application/pdf', strlen(self::PDF)], [$correction['attachment']['mime'], $correction['attachment']['size']]);
        self::assertStringEndsWith('.pdf', $correction['attachment_ref']);
        self::assertSame(11.0, (float) $correction['new']['score']);

        foreach ([self::TEACHER, self::CHEF, self::DIRECTOR] as $user) {
            $response = $this->download($correction['id'], $user);
            self::assertSame(200, $response->getStatusCode(), $user);
            self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
            self::assertSame(self::PDF, (string) $response->getBody());
            self::assertStringContainsString('attachment;', $response->getHeaderLine('Content-Disposition'));
        }
        self::assertSame(404, $this->download($correction['id'], self::OTHER_TEACHER)->getStatusCode(), 'Hors périmètre : la demande est invisible.');
    }

    public function testTypeTailleEtContenuActifRefuses(): void
    {
        $a = (string) $this->lockedAssessment()['id'];
        $markId = $this->line($a, 'p1')['mark_id'];

        [$status, $problem] = $this->upload($markId, "MZ\x90\x00 exécutable déguisé");
        self::assertSame(422, $status);
        self::assertStringContainsString('Type de fichier refusé', (string) $problem['errors']['attachment'][0]);

        self::assertSame(422, $this->upload($markId, '%PDF-1.4 ' . str_repeat('x', AttachmentStore::MAX_BYTES))[0], 'Plus de 5 Mo.');
        [$status, $problem] = $this->upload($markId, "%PDF-1.4\n<< /JavaScript (app.alert(1)) >>");
        self::assertSame(422, $status);
        self::assertStringContainsString('analyse de sécurité', (string) $problem['errors']['attachment'][0]);
        self::assertSame(422, $this->upload($markId, self::PDF, ['score' => '11'])[0], 'Motif obligatoire.');
        self::assertSame([], $this->ok('GET', '/higher/grade-corrections', null, self::CHEF), 'Aucune demande créée par un envoi refusé.');
        self::assertDirectoryDoesNotExist($this->storage . '/' . self::T, 'Aucun fichier écrit pour un envoi refusé.');
    }

    public function testJamaisLuePourUnAutreClient(): void
    {
        $a = (string) $this->lockedAssessment()['id'];
        $correction = $this->upload($this->line($a, 'p1')['mark_id'], self::PDF)[1]['data'];
        $this->assign(self::TB, 'reader-b', self::EB, 'higher.grades.read', 'establishment', null, 'higher_scolarite');
        $this->assign(self::TB, 'reader-b', self::EB, 'assessment.correction.approve', 'establishment', null, 'higher_dir_ecole');

        self::assertSame(404, $this->download($correction['id'], 'reader-b', self::TB, self::EB)->getStatusCode());
        $files = glob($this->storage . '/' . self::T . '/higher-grades/*/*') ?: [];
        self::assertCount(1, $files);
        $path = substr((string) $files[0], strlen($this->storage) + 1);
        self::assertNull($this->store->read(self::TB, $path), 'Le dépôt refuse le chemin d\'un autre client.');
        self::assertNull($this->store->read(self::TB, self::TB . '/../' . $path));
        self::assertSame(self::PDF, $this->store->read(self::T, $path));
    }

    public function testDemandeSansFichierEnJsonResteAcceptee(): void
    {
        $a = (string) $this->lockedAssessment()['id'];
        $correction = $this->ok('POST', '/higher/grade-marks/' . $this->line($a, 'p1')['mark_id'] . '/changes', [
            'score' => 11.0, 'reason' => 'Erreur de report.', 'attachment_ref' => 'PV papier n° 12',
        ], self::TEACHER);

        self::assertNull($correction['attachment']);
        self::assertSame(404, $this->download($correction['id'], self::CHEF)->getStatusCode());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentReissuer;
use Tests\Support\Documents\DocumentsTestCase;

/** PP15 : réédition « Rectificatif » sous le même numéro, ancienne version révoquée. */
final class ReissueTest extends DocumentsTestCase
{
    protected function setUp(): void
    {
        $this->bootDocuments();
    }

    public function testLeRectificatifConserveLeNumeroEtRevoqueLAncienneVersion(): void
    {
        $old = $this->issueViaApi('p1', data: ['academic_year' => '2026-2027', 'class_name' => '3eA']);

        $new = $this->reissuer()->reissue('t1', 'e1', $old['id'], ['academic_year' => '2026-2027', 'class_name' => '3eB'], 'Mauvaise classe', 'alice');

        self::assertSame($old['number'], $new->number);
        self::assertNotSame($old['id'], $new->id);
        self::assertNotSame($old['verification_token'], $new->verificationToken);

        $oldResponse = $this->publicVerify($old['verification_token']);
        $oldData = $this->json($oldResponse)['data'];
        self::assertSame('revoked', $oldData['status']);
        self::assertSame(\Scholaris\Modules\Socle\Documents\Application\VerificationService::RECTIFIED_REASON, $oldData['revoke_reason'], 'RG-DOC-2 : motif libre non publié');
        foreach ([$oldResponse, $this->publicVerify($new->verificationToken)] as $page) {
            $body = (string) $page->getBody();
            foreach (['Mauvaise classe', '3eA', '3eB', '2026-2027', 'address', 'adresse', 'mention'] as $hidden) {
                self::assertStringNotContainsString($hidden, $body, 'RG-DOC-2 : ni mention, ni adresse, ni donnée du document sur la page de vérification');
            }
        }
        self::assertSame($old['number'], $oldData['replaced_by_number']);
        self::assertSame('valid', $this->json($this->publicVerify($new->verificationToken))['data']['status']);

        $html = $this->kernel->container()->get(\Scholaris\Modules\Socle\Documents\Application\DocumentService::class)->content('t1', 'e1', $new->id);
        self::assertStringContainsString('Rectificatif', $html);
        self::assertStringContainsString('3eB', $html);
        self::assertSame(2, (int) $this->connections()->pdo()->query("SELECT revision FROM documents WHERE id = '" . $new->id . "'")->fetchColumn());
        self::assertSame($old['id'], $this->connections()->pdo()->query("SELECT reissue_of FROM documents WHERE id = '" . $new->id . "'")->fetchColumn());
    }

    public function testSansNouvellesDonneesLesValeursDOrigineSontConservees(): void
    {
        $old = $this->issueViaApi('p1', data: ['academic_year' => '2026-2027', 'class_name' => '3eA']);

        $new = $this->reissuer()->reissue('t1', 'e1', $old['id'], [], 'Réimpression corrigée', 'alice');

        self::assertStringContainsString('3eA', $this->kernel->container()->get(\Scholaris\Modules\Socle\Documents\Application\DocumentService::class)->content('t1', 'e1', $new->id));
    }

    public function testUnDocumentRevoqueOuSansMotifOuInconnuEstRefuse(): void
    {
        $doc = $this->issueViaApi();
        $this->expectStatus(422, fn () => $this->reissuer()->reissue('t1', 'e1', $doc['id'], [], '  ', 'alice'));
        $this->expectStatus(404, fn () => $this->reissuer()->reissue('t1', 'e1', 'inconnu', [], 'Motif', 'alice'));
        $this->expectStatus(404, fn () => $this->reissuer()->reissue('t2', 'e3', $doc['id'], [], 'Motif', 'bob'));

        $this->reissuer()->reissue('t1', 'e1', $doc['id'], [], 'Premier rectificatif', 'alice');
        $this->expectStatus(409, fn () => $this->reissuer()->reissue('t1', 'e1', $doc['id'], [], 'Second', 'alice'));
    }

    private function reissuer(): DocumentReissuer
    {
        return $this->kernel->container()->get(DocumentReissuer::class);
    }

    private function expectStatus(int $status, callable $call): void
    {
        try {
            $call();
        } catch (HttpException $e) {
            self::assertSame($status, $e->status);

            return;
        }
        self::fail('HttpException ' . $status . ' attendue.');
    }
}

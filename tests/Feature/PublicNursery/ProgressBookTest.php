<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\PublicNursery\Development\Application\ProgressBookService;
use Tests\Support\Organisation\OrganisationTestCase;

final class ProgressBookTest extends OrganisationTestCase
{
    public function testUnCarnetNonValideResteInvisibleEtUnTermeInterditBloqueLaValidation(): void
    {
        $this->bootOrganisation();
        $service = $this->kernel->container()->get(ProgressBookService::class);
        $draft = $service->saveDraft('tenant-dev', 'est-dev', 'learner-1', 'section-a', 'period-1', '2026-2027', [
            ['domain' => 'Langage', 'label' => 'S’exprimer', 'level' => 'acquired', 'comment' => 'Beau progrès'],
        ], 'Beau progrès, pas encore totalement autonome.');

        self::assertSame('draft', $draft['status']);
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);
        $service->pdf('tenant-dev', 'est-dev', (string) $draft['id']);
    }

    public function testLaValidationRefuseUneFormulationDeconseilleePuisLePdfResteCompactEtLaReeditionMentionneLEtat(): void
    {
        $this->bootOrganisation();
        $service = $this->kernel->container()->get(ProgressBookService::class);
        $draft = $service->saveDraft('tenant-dev', 'est-dev', 'learner-1', 'section-a', 'period-1', '2026-2027', [
            ['domain' => 'Langage', 'label' => 'S’exprimer', 'level' => 'acquired', 'comment' => 'Phrase positive'],
            ['domain' => 'Repères', 'label' => 'Se situer', 'level' => 'in_progress', 'comment' => 'Encouragement'],
        ], 'Beau progrès, pas encore totalement autonome.');

        try {
            $service->validate('tenant-dev', 'est-dev', (string) $draft['id'], 'director', ['pas encore']);
            self::fail('La validation devait être bloquée.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }

        $validated = $service->validate('tenant-dev', 'est-dev', (string) $draft['id'], 'director');
        $published = $service->publish('tenant-dev', 'est-dev', (string) $draft['id'], 'director');
        self::assertSame('published', $published['status']);

        $pdf = $service->pdf('tenant-dev', 'est-dev', (string) $draft['id']);
        self::assertLessThan(200 * 1024, strlen($pdf));

        $dup = $service->reissue('tenant-dev', 'est-dev', (string) $draft['id'], 'director', 'duplicate');
        $html = $service->html($dup, 'fr');
        self::assertStringContainsString('Duplicata', $html);

        $rectified = $service->reissue('tenant-dev', 'est-dev', (string) $draft['id'], 'director', 'rectified');
        $htmlRectified = $service->html($rectified, 'en');
        self::assertStringContainsString('Rectified copy', $htmlRectified);
        self::assertSame($published['document_id'], $rectified['document_id']);
    }
}

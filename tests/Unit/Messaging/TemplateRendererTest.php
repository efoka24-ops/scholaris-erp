<?php

declare(strict_types=1);

namespace Tests\Unit\Messaging;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Messaging\Domain\TemplateException;
use Scholaris\Modules\Socle\Messaging\Domain\TemplateRenderer;

final class TemplateRendererTest extends TestCase
{
    public function testRemplaceLesVariablesAutorisees(): void
    {
        self::assertSame('Reçu R-1 : 35000 FCFA', TemplateRenderer::render('Reçu :receipt_number : :amount FCFA', ['receipt_number' => 'R-1', 'amount' => 35000]));
    }

    public function testListeLesVariablesUtiliseesSansDoublon(): void
    {
        self::assertSame(['first_name', 'amount'], TemplateRenderer::placeholders(':first_name doit :amount, merci :first_name'));
    }

    public function testUneVariableHorsListeBlancheDansLeTexteEstRefusee(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage(':password');

        TemplateRenderer::assertBodyAllowed('Votre code :password');
    }

    public function testUneVariableFournieHorsListeBlancheEstRefusee(): void
    {
        try {
            TemplateRenderer::assertVariablesAllowed(['amount' => 1, 'secret' => 'x']);
            self::fail('Une exception était attendue.');
        } catch (TemplateException $e) {
            self::assertSame(TemplateException::VARIABLE_NOT_ALLOWED, $e->reason);
        }
    }

    public function testUneVariableManquanteLeveUneException(): void
    {
        try {
            TemplateRenderer::render('Solde :balance', []);
            self::fail('Une exception était attendue.');
        } catch (TemplateException $e) {
            self::assertSame(TemplateException::MISSING_VARIABLE, $e->reason);
        }
    }

    public function testLeRemplacementNEstJamaisRecursif(): void
    {
        self::assertSame('A :amount B', TemplateRenderer::render('A :first_name B', ['first_name' => ':amount', 'amount' => 99]));
    }

    public function testLesValeursSontNettoyees(): void
    {
        $text = TemplateRenderer::render('Bonjour :first_name', ['first_name' => "<b>Ma\r\nrie</b>\x00"]);

        self::assertSame('Bonjour b Ma rie /b', $text);
        self::assertStringNotContainsString('<', $text);
        self::assertStringNotContainsString("\x00", $text);
    }

    public function testLesValeursTropLonguesSontTronquees(): void
    {
        self::assertSame(200, mb_strlen(TemplateRenderer::render(':reference', ['reference' => str_repeat('é', 500)])));
    }

    public function testLesHeuresEtLesUrlsNeSontPasDesVariables(): void
    {
        self::assertSame([], TemplateRenderer::placeholders('RDV à 10:30 sur https://exemple.cm/9'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Documents\Domain\NameMask;
use Scholaris\Modules\Socle\Documents\Domain\NumberPattern;
use Scholaris\Modules\Socle\Documents\Domain\TemplateGuard;
use Scholaris\Modules\Socle\Documents\Domain\TemplateRenderer;
use Scholaris\Modules\Socle\Documents\Domain\VerificationToken;

final class DomainTest extends TestCase
{
    public function testGabaritParDefautEtGabaritConfigurable(): void
    {
        self::assertSame('CLP-26-B-004812', NumberPattern::format(NumberPattern::DEFAULT, 'CLP', 2026, 'B', 4812));
        self::assertSame('REL-EC-2026-000315', NumberPattern::format('REL-EC-{AAAA}-{N6}', 'CLP', 2026, 'R', 315));
        self::assertTrue(NumberPattern::isValid('{CODE}/{AA}/{N4}'));
        foreach (['', 'SANS-COMPTEUR', '{N6}{INCONNU}', '{N6}<b>', '{N6} espace'] as $bad) {
            self::assertFalse(NumberPattern::isValid($bad), $bad);
        }
    }

    public function testCodeEtablissement(): void
    {
        self::assertSame('CLP', NumberPattern::establishmentCode('Collège La Palmeraie'));
        self::assertSame('LYC', NumberPattern::establishmentCode('Lycée'));
        self::assertSame('ETB', NumberPattern::establishmentCode('1234'));
    }

    public function testMasqueDuTitulaire(): void
    {
        self::assertSame('F••• Nadia', NameMask::mask('Nadia', 'Fotso'));
        self::assertSame('É••• Zoé', NameMask::mask('Zoé', 'Étoundi'));
        self::assertSame('K•••', NameMask::mask('Kevin', ''));
    }

    public function testJeton(): void
    {
        $token = VerificationToken::generate();
        self::assertTrue(VerificationToken::isWellFormed($token));
        self::assertNotSame($token, VerificationToken::generate());
        self::assertFalse(VerificationToken::isWellFormed($token . 'x'));
        self::assertFalse(VerificationToken::isWellFormed('../../etc/passwd/xxxxx'));
    }

    public function testRenduEchappeLesValeursEtNeReinterpretePasLesAccolades(): void
    {
        $html = TemplateRenderer::render('<p>{{ holder_name }} {{note}} {{qr}}</p>', ['holder_name' => '<b>{{note}}</b> "x"', 'note' => 'ok'], ['qr' => '<i>QR</i>']);

        self::assertSame('<p>&lt;b&gt;{{note}}&lt;/b&gt; &quot;x&quot; ok <i>QR</i></p>', $html);
    }

    public function testGardeDeModele(): void
    {
        TemplateGuard::assertValid('<p>{{holder_name}} {{classe}} {{ number }}</p><a href="https://scholaris.cm/x">lien</a>', ['classe']);

        foreach (['{{classe}}', '<SCRIPT>', '<div ONCLICK="a">', '<a href="vbscript:x">'] as $bad) {
            try {
                TemplateGuard::assertValid($bad, []);
                self::fail('Refus attendu : ' . $bad);
            } catch (HttpException $e) {
                self::assertSame(422, $e->status);
            }
        }
    }
}

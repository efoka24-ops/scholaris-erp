<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\I18n\Translator;

final class TranslatorTest extends TestCase
{
    private Translator $translator;

    protected function setUp(): void
    {
        $this->translator = new Translator(dirname(__DIR__, 2) . '/lang');
    }

    public function testTraductionFrancaisEtAnglais(): void
    {
        self::assertSame('Accès refusé.', $this->translator->translate('error.forbidden', 'fr'));
        self::assertSame('Access denied.', $this->translator->translate('error.forbidden', 'en'));
    }

    public function testRemplacementDesVariables(): void
    {
        self::assertSame('Le champ nom est obligatoire.', $this->translator->translate('validation.required', 'fr', ['field' => 'nom']));
    }

    public function testCleInconnueRenvoieLaCle(): void
    {
        self::assertSame('inconnue.cle', $this->translator->translate('inconnue.cle', 'en'));
    }

    public function testNegociationDeLaLangue(): void
    {
        self::assertSame('en', Translator::negotiate('en-GB,en;q=0.9,fr;q=0.8'));
        self::assertSame('fr', Translator::negotiate('fr-CM,fr;q=0.9,en;q=0.8'));
        self::assertSame('fr', Translator::negotiate('de,es'));
        self::assertSame('fr', Translator::negotiate(''));
        self::assertSame('en', Translator::negotiate('fr;q=0.5,en;q=0.9'));
    }
}

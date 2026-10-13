<?php

declare(strict_types=1);

namespace Tests\Unit\Messaging;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Messaging\Domain\Audience;
use Scholaris\Modules\Socle\Messaging\Domain\SendingWindow;

final class SendingWindowTest extends TestCase
{
    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-08 ' . $time, new DateTimeZone('Africa/Douala'));
    }

    public function testBornesDeLaPlageParDefaut(): void
    {
        $window = new SendingWindow();

        self::assertFalse($window->isOpen($this->at('06:59')));
        self::assertTrue($window->isOpen($this->at('07:00')));
        self::assertTrue($window->isOpen($this->at('20:59')));
        self::assertFalse($window->isOpen($this->at('21:00')));
    }

    public function testPlageParametree(): void
    {
        $window = new SendingWindow(8, 18);

        self::assertFalse($window->isOpen($this->at('07:30')));
        self::assertTrue($window->isOpen($this->at('08:00')));
        self::assertFalse($window->isOpen($this->at('18:00')));
    }

    public function testProchaineOuverture(): void
    {
        $window = new SendingWindow();

        self::assertSame('2026-10-08T07:00:00+01:00', $window->nextOpening($this->at('05:00'))->format(DATE_ATOM));
        self::assertSame('2026-10-09T07:00:00+01:00', $window->nextOpening($this->at('21:30'))->format(DATE_ATOM));
        self::assertSame('2026-10-09T07:00:00+01:00', $window->nextOpening($this->at('07:00'))->format(DATE_ATOM));
    }

    public function testAudienceExigeUnIdentifiantSeulementPourPersonneClasseNiveau(): void
    {
        self::assertSame(['type' => 'arrears', 'id' => null], Audience::of('arrears')->toArray());
        self::assertSame(['type' => 'structure', 'id' => 'c1'], Audience::of('structure', 'c1')->toArray());

        foreach ([['person', null], ['arrears', 'x'], ['inconnu', null], ['level', 'a b']] as [$type, $id]) {
            try {
                Audience::of($type, $id);
                self::fail('Audience invalide acceptée : ' . $type);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

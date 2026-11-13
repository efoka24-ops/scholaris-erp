<?php

declare(strict_types=1);

namespace Tests\Feature;

use LogicException;
use RuntimeException;
use Scholaris\Core\Numbering\SequenceService;
use Tests\Support\AppTestCase;

final class SequenceServiceTest extends AppTestCase
{
    private SequenceService $sequences;

    protected function setUp(): void
    {
        $this->boot();
        $this->sequences = $this->kernel->container()->get(SequenceService::class);
    }

    public function testNumerotationContinueSansTrou(): void
    {
        $numbers = [];
        for ($i = 0; $i < 5; $i++) {
            $numbers[] = $this->sequences->transaction(fn () => $this->sequences->next('t1', 'e1', 'receipt', 2026));
        }

        self::assertSame([1, 2, 3, 4, 5], $numbers);
    }

    public function testLesSuitesSontIndependantesParEtablissementTypeEtAnnee(): void
    {
        $next = fn (string $e, string $k, int $y): int => $this->sequences->transaction(fn () => $this->sequences->next('t1', $e, $k, $y));

        self::assertSame(1, $next('e1', 'receipt', 2026));
        self::assertSame(1, $next('e2', 'receipt', 2026));
        self::assertSame(1, $next('e1', 'document', 2026));
        self::assertSame(1, $next('e1', 'receipt', 2027));
        self::assertSame(2, $next('e1', 'receipt', 2026));
    }

    public function testUnNumeroEstAnnuleAvecLaTransactionQuiEchoue(): void
    {
        $this->sequences->transaction(fn () => $this->sequences->next('t1', 'e1', 'receipt', 2026));

        try {
            $this->sequences->transaction(function (): never {
                $this->sequences->next('t1', 'e1', 'receipt', 2026);
                throw new RuntimeException('échec de l\'enregistrement du reçu');
            });
            self::fail('Exception attendue.');
        } catch (RuntimeException) {
        }

        self::assertSame(2, $this->sequences->transaction(fn () => $this->sequences->next('t1', 'e1', 'receipt', 2026)), 'Le numéro 2 n\'a pas été consommé.');
    }

    public function testNextHorsTransactionEstRefuse(): void
    {
        $this->expectException(LogicException::class);
        $this->sequences->next('t1', 'e1', 'receipt', 2026);
    }

    public function testFormatDuNumero(): void
    {
        self::assertSame('REL-2026-000315', SequenceService::format('REL', 2026, 315));
    }
}

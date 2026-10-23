<?php

declare(strict_types=1);

namespace Tests\Unit\Sync;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Sync\Domain\BatchSignature;

/** HMAC-SHA256 de `appareil \n lot \n corps compressé` ; comparaison à temps constant ; format d'en-tête strict. */
final class BatchSignatureTest extends TestCase
{
    private const KEY = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    public function testLaSignatureLieAppareilLotEtCorps(): void
    {
        $expected = hash_hmac('sha256', "DEV-1\nLOT-1\ncorps", self::KEY);

        self::assertSame($expected, BatchSignature::compute(self::KEY, 'DEV-1', 'LOT-1', 'corps'));
        self::assertNotSame($expected, BatchSignature::compute(self::KEY, 'DEV-2', 'LOT-1', 'corps'));
        self::assertNotSame($expected, BatchSignature::compute(self::KEY, 'DEV-1', 'LOT-2', 'corps'));
        self::assertNotSame($expected, BatchSignature::compute(self::KEY, 'DEV-1', 'LOT-1', 'corps2'));
    }

    public function testLaVerificationAccepteLaBonneSignatureSousSesFormesValides(): void
    {
        $hex = BatchSignature::compute(self::KEY, 'DEV-1', 'LOT-1', 'corps');

        self::assertTrue(BatchSignature::matches(self::KEY, 'DEV-1', 'LOT-1', 'corps', $hex));
        self::assertTrue(BatchSignature::matches(self::KEY, 'DEV-1', 'LOT-1', 'corps', 'sha256=' . $hex));
        self::assertTrue(BatchSignature::matches(self::KEY, 'DEV-1', 'LOT-1', 'corps', strtoupper($hex)));
        self::assertTrue(BatchSignature::matches(self::KEY, 'DEV-1', 'LOT-1', 'corps', '  ' . $hex . ' '));
    }

    public function testLaVerificationRefuseToutLeReste(): void
    {
        $hex = BatchSignature::compute(self::KEY, 'DEV-1', 'LOT-1', 'corps');
        $wrong = ($hex[0] === 'a' ? 'b' : 'a') . substr($hex, 1);

        foreach (['', 'sha256=', 'md5=' . $hex, substr($hex, 0, 63), $hex . '0', $wrong, str_repeat('z', 64), "{$hex}\n0"] as $header) {
            self::assertFalse(BatchSignature::matches(self::KEY, 'DEV-1', 'LOT-1', 'corps', $header), $header);
        }
        self::assertFalse(BatchSignature::matches('autre-cle', 'DEV-1', 'LOT-1', 'corps', $hex));
        self::assertFalse(BatchSignature::matches('', 'DEV-1', 'LOT-1', 'corps', hash_hmac('sha256', "DEV-1\nLOT-1\ncorps", '')), 'Une clé vide ne signe rien.');
    }

    public function testLaComparaisonSeFaitATempsConstant(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Modules/Socle/Sync/Domain/BatchSignature.php');

        self::assertStringContainsString('hash_equals(', $source);
        self::assertDoesNotMatchRegularExpression('/\$(?:header|provided|signature|expected)\s*===?\s*\$/', $source, 'Aucune comparaison directe de signatures.');
    }
}

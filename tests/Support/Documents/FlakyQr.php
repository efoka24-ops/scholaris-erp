<?php

declare(strict_types=1);

namespace Tests\Support\Documents;

use RuntimeException;
use Scholaris\Modules\Socle\Documents\Domain\QrCodeGenerator;

/** Générateur de QR qui peut échouer à la demande (simule une panne en cours d'émission). */
final class FlakyQr implements QrCodeGenerator
{
    public bool $fail = false;

    public function markup(string $verificationUrl): string
    {
        if ($this->fail) {
            throw new RuntimeException('Panne du générateur de QR.');
        }

        return '<span class="qr">QR:' . htmlspecialchars($verificationUrl, ENT_QUOTES) . '</span>';
    }
}

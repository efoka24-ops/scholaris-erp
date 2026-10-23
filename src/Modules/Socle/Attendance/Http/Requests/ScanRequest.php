<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Http\Requests;

/** Pointage par code de séance (QR). */
final class ScanRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return ['token' => Field::string(64)->required()];
    }
}

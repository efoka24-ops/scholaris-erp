<?php

declare(strict_types=1);

namespace Tests\Support\Organisation;

use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGuard;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentRequest;

/** Garde-fou d'inscription d'application (D3) : mémorise les demandes, refuse avec $reason s'il est défini. */
final class RecordingEnrolmentGuard implements EnrolmentGuard
{
    /** @var list<EnrolmentRequest> */
    public array $seen = [];

    public ?string $reason = null;

    public function refusal(EnrolmentRequest $request): ?string
    {
        $this->seen[] = $request;

        return $this->reason;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support\Primary;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentStatusWriter;

/** Doublure d'EnrollmentStatusWriter : garde les changements demandés, peut refuser le suivant (409). */
final class RecordingStatuses implements EnrollmentStatusWriter
{
    /** @var list<string> */
    public array $calls = [];
    public bool $failNext = false;

    public function changeStatus(string $tenantId, string $establishmentId, string $enrollmentId, string $status): void
    {
        if ($this->failNext) {
            $this->failNext = false;

            throw HttpException::conflict('Passage impossible.');
        }
        $this->calls[] = $enrollmentId . ':' . $status;
    }
}

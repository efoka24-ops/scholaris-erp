<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentStatusWriter;
use Scholaris\Modules\Socle\People\Domain\EnrollmentStatus;

/** Réalisation d'EnrollmentStatusWriter sur EnrollmentService::changeStatus. */
final class EnrollmentStatusWriterService implements EnrollmentStatusWriter
{
    public function __construct(private readonly EnrollmentService $enrollments)
    {
    }

    public function changeStatus(string $tenantId, string $establishmentId, string $enrollmentId, string $status): void
    {
        $target = EnrollmentStatus::tryFrom($status) ?? throw HttpException::validation(['status' => ['Statut d\'inscription inconnu.']]);
        $this->enrollments->changeStatus($tenantId, $establishmentId, $enrollmentId, $target);
    }
}

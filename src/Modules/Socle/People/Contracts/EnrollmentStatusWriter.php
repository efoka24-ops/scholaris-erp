<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/**
 * Changement de statut d'une inscription par une application (ex. transfert sortant du primaire : `transferred`), avec les mêmes
 * transitions permises que POST /enrollments/{id}/status. Une transition interdite lève une HttpException 409.
 */
interface EnrollmentStatusWriter
{
    /** @param string $status transferred, withdrawn, excluded, graduated, suspended ou active */
    public function changeStatus(string $tenantId, string $establishmentId, string $enrollmentId, string $status): void;
}

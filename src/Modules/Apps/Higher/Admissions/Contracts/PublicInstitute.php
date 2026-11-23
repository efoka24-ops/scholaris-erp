<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Contracts;

/** Institut retrouvé par son lien public « Candidater » : client, établissement et nom affiché. */
final class PublicInstitute
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $establishmentId,
        public readonly string $name,
    ) {
    }
}

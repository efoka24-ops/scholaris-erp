<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Contracts;

/** École interne, identifiant = unité `school` du socle (org_units). Le code sert au matricule et aux numéros de documents. */
final class SchoolInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $directorPersonId,
    ) {
    }
}

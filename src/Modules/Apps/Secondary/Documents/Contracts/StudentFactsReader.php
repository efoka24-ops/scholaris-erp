<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Documents\Contracts;

/** Lecture consolidée des fournisseurs StudentFacts (`secondary.student_facts.*`). Les portails n'en exposent qu'un booléen (D5). */
interface StudentFactsReader
{
    /** Matricule national du premier fournisseur qui le connaît, null s'il est en attente. */
    public function nationalId(string $tenantId, string $establishmentId, string $personId): ?string;
}

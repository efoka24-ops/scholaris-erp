<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Documents;

use Scholaris\Modules\Apps\Higher\Documents\Contracts\DocumentTypeProvider;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentType;

/** Fournisseur fautif : un intitulé « BTS » est refusé par le catalogue (RG-EXN-3). */
final class ForbiddenTitleProvider implements DocumentTypeProvider
{
    public function types(): array
    {
        return [new HigherDocumentType('DOC-12', 'AFF', 'Attestation BTS', 'BTS certificate', 'nationalexams', 'directeur_general', true, true, [], ['fr' => '<p>{{holder_name}}</p>'])];
    }
}

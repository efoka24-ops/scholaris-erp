<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Documents\Contracts;

/**
 * Fournisseur de types de documents d'un module Higher. Enregistrement : clé de liste du conteneur
 * `higher.document_types.<module>` => [MonFournisseur::class, ...] dans le definitions.php du module ; le catalogue (module
 * Documents) itère toutes les clés `higher.document_types.*`. Un code DOC-nn n'est déclaré qu'une fois.
 */
interface DocumentTypeProvider
{
    /** @return list<HigherDocumentType> */
    public function types(): array;
}

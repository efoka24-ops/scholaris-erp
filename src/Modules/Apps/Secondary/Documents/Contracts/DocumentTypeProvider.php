<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Documents\Contracts;

/**
 * Fournisseur de types de documents d'un sous-module Secondary. Enregistrement : clé de liste du conteneur
 * `secondary.document_types.<module>` => [MonFournisseur::class] dans le definitions.php du sous-module ; le catalogue (Documents)
 * itère toutes les clés `secondary.document_types.*`. Un numéro DOC-nn et une nature ne sont déclarés qu'une fois.
 */
interface DocumentTypeProvider
{
    /** @return list<SecondaryDocumentType> */
    public function types(): array;
}

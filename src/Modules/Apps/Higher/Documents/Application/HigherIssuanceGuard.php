<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Documents\Application;

use Scholaris\Modules\Socle\Documents\Contracts\IssuanceContext;
use Scholaris\Modules\Socle\Documents\Contracts\IssuanceGuard;

/**
 * Garde d'émission de base des documents de l'IPES (S18), appelée par le socle avant toute émission d'un type `hi_doc*` :
 * le type doit figurer au catalogue Higher (et ne pas être un document produit par le socle) ; aucun titre « BTS » ni « HND »
 * (RG-EXN-3) ; l'école émettrice est obligatoire (identité visuelle et signataire de l'école, RG-ORG-4).
 * Les gardes propres à un document (solde nul, PV signé…) sont enregistrées par leur module.
 */
final class HigherIssuanceGuard implements IssuanceGuard
{
    public const SOCLE_PREFIX = 'hi_doc';

    public function __construct(private readonly HigherDocumentCatalog $catalog)
    {
    }

    public function refusal(IssuanceContext $context): ?string
    {
        if (!str_starts_with($context->type, self::SOCLE_PREFIX)) {
            return null;
        }
        if ($this->catalog->bySocleCode($context->type) === null) {
            return 'Type de document absent du catalogue de l\'application Supérieur.';
        }
        $title = $context->data['document_title'] ?? null;
        if (is_string($title) && preg_match(HigherDocumentCatalog::FORBIDDEN_TITLE, $title) === 1) {
            return 'Intitulé interdit : l\'application ne délivre jamais de document intitulé « BTS » ou « HND » (RG-EXN-3).';
        }
        if ($context->orgUnitId === null || $context->orgUnitId === '') {
            return 'Un document de l\'IPES est émis par une école : passez par l\'application Supérieur (/higher/documents).';
        }

        return null;
    }
}

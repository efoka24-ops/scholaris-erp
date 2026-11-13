<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Contracts;

/** Lit un PDF de document officiel du client et de l'établissement, uniquement si le document est valide. */
interface DocumentPdfReader
{
    /** @return string|null octets PDF, null si le document est absent ou révoqué. */
    public function pdfOf(string $tenantId, string $establishmentId, string $documentId): ?string;
}

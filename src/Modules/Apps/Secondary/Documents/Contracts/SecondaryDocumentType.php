<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Documents\Contracts;

use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfile;

/**
 * Type de document du secondaire (catalogue du CDC, DOC-01 à DOC-34) : un numéro DOC-nn, une nature courte utilisée par l'API, le code
 * du type au socle Documents (modèles FR/EN amorcés par migration de données), le QR, le module dont il dépend (null = noyau) et la
 * source de ses variables. Un document produit par le socle lui-même (reçu DOC-27) a `issuedBySocle = true` et aucun code de type.
 */
final class SecondaryDocumentType
{
    /** Variables tirées d'une inscription (élève, classe, année). */
    public const SOURCE_ENROLMENT = 'enrolment';
    /** Variables tirées d'une classe (liste de classe). */
    public const SOURCE_CLASS = 'class';
    /** Variables tirées d'une facture du socle Billing (relevé, attestation de paiement). */
    public const SOURCE_INVOICE = 'invoice';
    /** Variables fournies par le sous-module appelant (préinscription, exeat) par le contrat SecondaryDocuments. */
    public const SOURCE_CALLER = 'caller';
    /** Document émis par un autre module du socle (reçu). */
    public const SOURCE_SOCLE = 'socle';

    /** @param list<string> $permissions droits `secondary.*` qui permettent d'émettre ce document (réunion des périmètres) */
    public function __construct(
        public readonly string $doc,
        public readonly string $kind,
        public readonly ?string $socleCode,
        public readonly string $labelFr,
        public readonly string $labelEn,
        public readonly bool $qr,
        public readonly string $source,
        public readonly array $permissions,
        public readonly ?string $module = null,
        /** Émis par la route POST /secondary/documents (sinon seulement par le contrat, par le sous-module propriétaire du parcours). */
        public readonly bool $direct = true,
        /** Capacité du profil requise en plus du module, sans déduire le type d'établissement. */
        public readonly ?string $requiredTeachingKind = null,
    ) {
    }

    public function issuedBySocle(): bool
    {
        return $this->socleCode === null;
    }

    public function isAvailableFor(EstablishmentProfile $profile): bool
    {
        return ($this->module === null || $profile->isActive($this->module))
            && ($this->requiredTeachingKind === null || $profile->offersTeaching($this->requiredTeachingKind));
    }
}

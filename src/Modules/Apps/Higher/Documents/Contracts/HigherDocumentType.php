<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Documents\Contracts;

use InvalidArgumentException;

/**
 * Type de document de l'IPES (DOC-01 à DOC-32) déclaré par un module Higher : numéro RG-DOC-1 `PREFIXE-{ECOLE}-{AAAA}-{N6}`
 * (ex. REL-EC-2026-000315), signataire attendu (S17), QR, blocage pour impayé (RG-DOC-7 : jamais pour certificat de scolarité,
 * reçu, relevé de compte, attestation de paiement), variables propres et corps HTML FR/EN (en-tête, identité visuelle et pied
 * communs ajoutés par le catalogue). `issuedBySocle` : document produit par le socle (reçu DOC-27), sans type Documents.
 */
final class HigherDocumentType
{
    public const CODE = '/^DOC-\d{2}$/';
    public const PREFIX = '/^[A-Z]{3}$/';
    public const VARIABLE = '/^[a-z][a-z0-9_]{1,39}$/';
    public const CATEGORIES = ['certificate', 'bulletin', 'other'];

    /**
     * @param list<string> $variables variables propres au type (les variables d'identité visuelle sont ajoutées par le catalogue)
     * @param array<string, string> $bodies corps HTML par langue (fr obligatoire, en conseillé)
     */
    public function __construct(
        public readonly string $code,
        public readonly string $prefix,
        public readonly string $labelFr,
        public readonly string $labelEn,
        public readonly string $module,
        public readonly ?string $signerRole,
        public readonly bool $qr,
        public readonly bool $blockable,
        public readonly array $variables = [],
        public readonly array $bodies = [],
        public readonly string $category = 'certificate',
        public readonly bool $issuedBySocle = false,
        public readonly string $typeLetter = 'D',
    ) {
        if (preg_match(self::CODE, $code) !== 1 || preg_match(self::PREFIX, $prefix) !== 1) {
            throw new InvalidArgumentException('Type de document Higher invalide : code DOC-nn et préfixe de 3 majuscules attendus (' . $code . ').');
        }
        foreach ($variables as $variable) {
            if (preg_match(self::VARIABLE, $variable) !== 1) {
                throw new InvalidArgumentException('Variable invalide pour ' . $code . ' : ' . $variable);
            }
        }
        if (!in_array($category, self::CATEGORIES, true) || ($signerRole !== null && preg_match('/^[a-z][a-z0-9_]{1,39}$/', $signerRole) !== 1)) {
            throw new InvalidArgumentException('Catégorie ou rôle de signataire invalide pour ' . $code . '.');
        }
        if (!$issuedBySocle && !isset($bodies['fr'])) {
            throw new InvalidArgumentException('Un modèle français est obligatoire pour ' . $code . '.');
        }
    }

    /** Code du type au socle Documents (minuscules) : DOC-06 donne hi_doc06. */
    public function socleCode(): string
    {
        return 'hi_' . strtolower(str_replace('-', '', $this->code));
    }

    /** Gabarit de numéro RG-DOC-1 : préfixe, code d'école (jeton {ECOLE}, S16), année sur 4 chiffres, ordre sur 6 chiffres. */
    public function numberTemplate(): string
    {
        return $this->prefix . '-{ECOLE}-{AAAA}-{N6}';
    }

    public function label(string $locale): string
    {
        return $locale === 'en' ? $this->labelEn : $this->labelFr;
    }
}

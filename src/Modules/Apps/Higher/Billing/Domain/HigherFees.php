<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Domain;

/**
 * Types de frais de l'IPES (EX-FIN-01, S10) : code stable du frais (`fee_items.code` du socle) => type de frais du socle, libellés.
 * Les types propres à l'application sont déclarés au socle sous `billing.fee_kinds.higher` (voir SOCLE_KINDS) ; l'inscription et la
 * pension gardent les types du socle `registration` et `tuition` (affectation RG-FIN-2 et remises RG-FIN-1).
 * Les frais d'examen national (RG-FIN-4) et les reversements à la tutelle (EX-TUT-05) sont des types de frais comme les autres.
 */
final class HigherFees
{
    public const DOSSIER = 'hi.dossier';
    public const INSCRIPTION = 'hi.inscription';
    public const PENSION = 'hi.pension';
    public const EXAMEN_NATIONAL = 'hi.examen_national';
    public const SOUTENANCE = 'hi.soutenance';
    public const STAGE = 'hi.stage';
    public const CARTE = 'hi.carte';
    public const ASSURANCE = 'hi.assurance';
    public const REVERSEMENT_TUTELLE = 'hi.reversement_tutelle';

    /** Types de frais du socle ajoutés par l'application (clé de conteneur `billing.fee_kinds.higher`). */
    public const SOCLE_KINDS = ['application_fee', 'national_exam', 'defense', 'internship', 'student_card', 'insurance', 'tutelage_fee'];

    /** @var array<string, array{kind: string, fr: string, en: string, refundable: bool}> */
    public const CATALOG = [
        self::DOSSIER => ['kind' => 'application_fee', 'fr' => 'Frais de dossier', 'en' => 'Application fee', 'refundable' => false],
        self::INSCRIPTION => ['kind' => 'registration', 'fr' => 'Frais d\'inscription', 'en' => 'Registration fee', 'refundable' => false],
        self::PENSION => ['kind' => 'tuition', 'fr' => 'Pension', 'en' => 'Tuition', 'refundable' => true],
        self::EXAMEN_NATIONAL => ['kind' => 'national_exam', 'fr' => 'Frais d\'examen national', 'en' => 'National examination fee', 'refundable' => false],
        self::SOUTENANCE => ['kind' => 'defense', 'fr' => 'Frais de soutenance', 'en' => 'Defence fee', 'refundable' => false],
        self::STAGE => ['kind' => 'internship', 'fr' => 'Frais de stage', 'en' => 'Internship fee', 'refundable' => false],
        self::CARTE => ['kind' => 'student_card', 'fr' => 'Carte d\'étudiant', 'en' => 'Student card', 'refundable' => false],
        self::ASSURANCE => ['kind' => 'insurance', 'fr' => 'Assurance', 'en' => 'Insurance', 'refundable' => false],
        self::REVERSEMENT_TUTELLE => ['kind' => 'tutelage_fee', 'fr' => 'Frais reversés à l\'université de tutelle', 'en' => 'Supervising university fee', 'refundable' => false],
    ];

    private function __construct()
    {
    }

    public static function isKnown(string $code): bool
    {
        return isset(self::CATALOG[$code]);
    }

    public static function kindOf(string $code): string
    {
        return self::CATALOG[$code]['kind'] ?? throw new \InvalidArgumentException('Frais inconnu : ' . $code);
    }

    public static function labelOf(string $code, string $locale = 'fr'): string
    {
        $entry = self::CATALOG[$code] ?? throw new \InvalidArgumentException('Frais inconnu : ' . $code);

        return $locale === 'en' ? $entry['en'] : $entry['fr'];
    }

    /** @return list<array{code: string, kind: string, label_fr: string, label_en: string, refundable: bool}> */
    public static function describe(): array
    {
        $out = [];
        foreach (self::CATALOG as $code => $entry) {
            $out[] = ['code' => $code, 'kind' => $entry['kind'], 'label_fr' => $entry['fr'], 'label_en' => $entry['en'], 'refundable' => $entry['refundable']];
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Domain;

/**
 * Cycle d'une candidature : brouillon, déposée (pièces ou frais de dossier attendus), complète (NOT-01, DOC-01), puis décision
 * motivée (EX-ADM-06) : admis, admis sous réserve de pièce (RG-ADM-2), liste d'attente, refusé ; enfin inscrite (INS) ou retirée.
 */
final class ApplicationStatus
{
    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';
    public const COMPLETE = 'complete';
    public const ADMITTED = 'admitted';
    public const CONDITIONAL = 'conditional';
    public const WAITLISTED = 'waitlisted';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';
    public const ENROLLED = 'enrolled';

    /** Décisions motivées (EX-ADM-06). */
    public const DECISIONS = [self::ADMITTED, self::CONDITIONAL, self::WAITLISTED, self::REJECTED];

    /** Candidatures qui occupent une place de la campagne (RG-ADM-3). */
    public const HOLDING_SEAT = [self::ADMITTED, self::CONDITIONAL, self::ENROLLED];

    /** États où une décision peut être prise ou révisée (une décision n'est jamais prise sur un dossier incomplet). */
    public const DECIDABLE = [self::COMPLETE, self::ADMITTED, self::CONDITIONAL, self::WAITLISTED];

    /** Frais de dossier : non remboursables (RG-ADM-4), sauf annulation de la campagne. */
    public const FEE_UNPAID = 'unpaid';
    public const FEE_PENDING = 'pending';
    public const FEE_PAID = 'paid';
    public const FEE_NONE = 'none';

    private const LABELS = [
        self::ADMITTED => ['fr' => 'admis', 'en' => 'admitted'],
        self::CONDITIONAL => ['fr' => 'admis sous réserve de pièce', 'en' => 'admitted subject to documents'],
        self::WAITLISTED => ['fr' => 'liste d\'attente', 'en' => 'waiting list'],
        self::REJECTED => ['fr' => 'refusé', 'en' => 'rejected'],
    ];

    private function __construct()
    {
    }

    public static function label(string $decision, string $locale = 'fr'): string
    {
        return self::LABELS[$decision][$locale === 'en' ? 'en' : 'fr'] ?? $decision;
    }
}

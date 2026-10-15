<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Domain;

/**
 * Statuts d'une inscription IPES (EX-INS-04) : en attente de validation, inscrit, inscrit sous réserve financière (RG-INS-2, décision
 * du directeur d'école), suspendu (gel, EX-INS-08), abandon, exclu, transféré, diplômé. « Vivante » = occupe la filière pour l'année.
 */
final class EnrolmentStatus
{
    public const PENDING = 'pending';
    public const ENROLLED = 'enrolled';
    public const FINANCIAL_HOLD = 'financial_hold';
    public const SUSPENDED = 'suspended';
    public const WITHDRAWN = 'withdrawn';
    public const EXCLUDED = 'excluded';
    public const TRANSFERRED = 'transferred';
    public const GRADUATED = 'graduated';

    /** Inscriptions qui occupent une filière pour l'année (double cursus : une par filière, RG-ORG-6). */
    public const LIVE = [self::PENDING, self::ENROLLED, self::FINANCIAL_HOLD, self::SUSPENDED];

    /** Inscriptions validées (comptent dans la limite d'inscriptions consécutives d'un niveau, RG-INS-5). */
    public const VALIDATED = [self::ENROLLED, self::FINANCIAL_HOLD, self::SUSPENDED, self::WITHDRAWN, self::EXCLUDED, self::TRANSFERRED, self::GRADUATED];

    /** Changements de statut permis par POST /enrolments/{id}/status. */
    private const TRANSITIONS = [
        self::ENROLLED => [self::SUSPENDED, self::WITHDRAWN, self::EXCLUDED, self::TRANSFERRED, self::GRADUATED],
        self::FINANCIAL_HOLD => [self::SUSPENDED, self::WITHDRAWN, self::EXCLUDED, self::TRANSFERRED],
        self::SUSPENDED => [self::ENROLLED, self::WITHDRAWN, self::EXCLUDED, self::TRANSFERRED],
        self::PENDING => [self::WITHDRAWN],
    ];

    private function __construct()
    {
    }

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}

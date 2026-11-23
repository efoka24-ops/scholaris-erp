<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Internships\Domain;

/**
 * Cycle de la convention de stage (EX-STG-02/03) : brouillon (modifiable) → en signature (DOC-20 émise) → signée (étudiant,
 * entreprise et directeur d'école ont signé) → terminée (attestation de fin de stage reçue). Annulable avant la fin.
 */
final class ConventionStatus
{
    public const DRAFT = 'draft';
    public const PENDING = 'pending_signatures';
    public const SIGNED = 'signed';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    public const ALL = [self::DRAFT, self::PENDING, self::SIGNED, self::COMPLETED, self::CANCELLED];

    /** Parties signataires de la convention tripartite (DOC-20). */
    public const PARTIES = ['student', 'company', 'school'];

    /** @var array<string, list<string>> */
    private const NEXT = [
        self::DRAFT => [self::PENDING, self::CANCELLED],
        self::PENDING => [self::SIGNED, self::CANCELLED],
        self::SIGNED => [self::COMPLETED, self::CANCELLED],
        self::COMPLETED => [],
        self::CANCELLED => [],
    ];

    private function __construct()
    {
    }

    public static function allows(string $from, string $to): bool
    {
        return in_array($to, self::NEXT[$from] ?? [], true);
    }

    /** Suivi (visites, rapports, fiche d'évaluation) et dépôt du mémoire : une fois la convention signée. */
    public static function isRunning(string $status): bool
    {
        return in_array($status, [self::SIGNED, self::COMPLETED], true);
    }
}

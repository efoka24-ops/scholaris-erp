<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Internships\Domain;

/**
 * RG-STG-1 : une soutenance n'est programmable que si la convention est signée, l'attestation de stage déposée, le mémoire
 * déposé et le quitus financier émis (DOC-30, RG-FIN-6). Rend la liste des conditions avec leur état.
 */
final class DefenseReadiness
{
    public const CONDITIONS = ['convention_signed', 'completion_certificate', 'thesis_deposited', 'financial_clearance'];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $internship ligne hi_internships
     * @return array<string, bool> condition => remplie
     */
    public static function check(array $internship, bool $quitusIssued): array
    {
        return [
            'convention_signed' => ConventionStatus::isRunning((string) $internship['status']),
            'completion_certificate' => ($internship['completion_received_on'] ?? null) !== null,
            'thesis_deposited' => ($internship['thesis_deposited_at'] ?? null) !== null,
            'financial_clearance' => $quitusIssued,
        ];
    }

    /**
     * @param array<string, bool> $checks
     * @return list<string> conditions manquantes
     */
    public static function missing(array $checks): array
    {
        return array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\NationalExams\Domain;

/**
 * Cycle du candidat à l'examen national : eligible (inscrit en année de sortie, RG-EXN-1) -> complete (assiduité, frais et pièces
 * réunis, EX-EXN-03) -> exported (liste MINESUP produite) -> convened (numéro et centre) -> resulted (résultat importé) ;
 * withdrawn (retiré de la liste, tracé). Sessions : draft -> open -> exported -> closed.
 */
final class CandidateLifecycle
{
    public const ELIGIBLE = 'eligible';
    public const COMPLETE = 'complete';
    public const EXPORTED = 'exported';
    public const CONVENED = 'convened';
    public const RESULTED = 'resulted';
    public const WITHDRAWN = 'withdrawn';
    public const STATUSES = [self::ELIGIBLE, self::COMPLETE, self::EXPORTED, self::CONVENED, self::RESULTED, self::WITHDRAWN];

    public const EXAM_TYPES = ['bts', 'hnd'];
    public const RESULTS = ['passed', 'failed', 'absent'];

    public const SESSION_OPEN = 'open';
    public const SESSION_EXPORTED = 'exported';
    public const SESSION_CLOSED = 'closed';

    private function __construct()
    {
    }

    /**
     * Statut avant export recalculé d'après les conditions internes ; après export, inchangé.
     *
     * @param list<string> $required pièces exigées par la session
     * @param list<string> $received pièces reçues
     */
    public static function preExportStatus(string $current, bool $attendanceOk, bool $feeSettled, array $required, array $received): string
    {
        if (!in_array($current, [self::ELIGIBLE, self::COMPLETE], true)) {
            return $current;
        }

        return $attendanceOk && $feeSettled && self::missingPieces($required, $received) === [] ? self::COMPLETE : self::ELIGIBLE;
    }

    /**
     * @param list<string> $required
     * @param list<string> $received
     * @return list<string>
     */
    public static function missingPieces(array $required, array $received): array
    {
        return array_values(array_diff($required, $received));
    }

    public static function isExportedOrLater(string $status): bool
    {
        return in_array($status, [self::EXPORTED, self::CONVENED, self::RESULTED], true);
    }

    /**
     * Taux de réussite en pour cent sur tous les candidats ayant un résultat (absents compris), null sans résultat.
     *
     * @param array<string, int> $counts passed, failed, absent
     */
    public static function successRate(array $counts): ?float
    {
        $total = ($counts['passed'] ?? 0) + ($counts['failed'] ?? 0) + ($counts['absent'] ?? 0);

        return $total === 0 ? null : round(100 * ($counts['passed'] ?? 0) / $total, 2);
    }
}

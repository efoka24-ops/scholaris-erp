<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Tutelage\Domain;

/**
 * Règles de la tutelle : natures de transmission (EX-TUT-01/02), états des transmissions et des dossiers de diplôme (EX-TUT-03),
 * garde RG-TUT-2 (délibération finale signée et quitus) et échéance de la convention.
 */
final class TransmissionRules
{
    public const LISTS = 'lists';
    public const KINDS = [self::LISTS, 'marks', 'transcripts', 'minutes'];
    /** Natures qui exigent le PV de délibération signé de la période (RG-DEL-3). */
    public const NEED_SIGNED_MINUTES = ['marks', 'transcripts', 'minutes'];

    public const SENT = 'sent';
    public const ANSWERS = ['acknowledged', 'rejected'];

    public const FILE_TRANSMITTED = 'transmitted';
    public const FILE_STATUSES = ['transmitted', 'in_progress', 'available', 'withdrawn'];
    /** Transitions permises du dossier de diplôme. */
    private const FILE_FLOW = [
        'transmitted' => ['in_progress', 'available'],
        'in_progress' => ['available'],
        'available' => ['withdrawn'],
        'withdrawn' => [],
    ];

    private function __construct()
    {
    }

    public static function canMoveFile(string $from, string $to): bool
    {
        return in_array($to, self::FILE_FLOW[$from] ?? [], true);
    }

    /**
     * RG-TUT-2 : raisons du refus de transmettre le dossier de diplôme (liste vide = transmissible).
     *
     * @param array{decision_code: string, diploma_file: bool}|null $decision décision annuelle enregistrée sur PV signé
     * @return list<string>
     */
    public static function diplomaRefusals(?array $decision, int $balanceXaf): array
    {
        $reasons = [];
        if ($decision === null) {
            $reasons[] = 'Aucune décision de délibération finale enregistrée sur PV signé (RG-TUT-2).';
        } elseif (!$decision['diploma_file']) {
            $reasons[] = 'La décision de délibération (' . $decision['decision_code'] . ') n\'ouvre pas droit au dossier de diplôme.';
        }
        if ($balanceXaf > 0) {
            $reasons[] = sprintf('Quitus non émis : solde dû de %d XAF (RG-TUT-2).', $balanceXaf);
        }

        return $reasons;
    }

    /** Jours restants avant l'échéance (négatif si dépassée), null sans échéance. */
    public static function daysLeft(?string $endsOn, string $today): ?int
    {
        if ($endsOn === null || $endsOn === '') {
            return null;
        }
        $diff = (new \DateTimeImmutable($today))->diff(new \DateTimeImmutable(substr($endsOn, 0, 10)));

        return $diff->invert === 1 ? -(int) $diff->days : (int) $diff->days;
    }
}

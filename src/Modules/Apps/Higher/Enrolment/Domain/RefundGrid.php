<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Domain;

/**
 * Remboursement en cas d'abandon (RG-INS-6) : après la date limite paramétrée (`higher.enrolment.withdrawal_deadline`), aucun
 * remboursement ; avant, la grille de l'école (`higher.enrolment.refund_grid` : liste de {until: AAAA-MM-JJ, percent}), appliquée
 * aux seuls frais remboursables payés (pension). Sans grille ni date : aucun remboursement. Les frais de dossier et d'inscription
 * ne sont jamais remboursés (RG-ADM-4).
 */
final class RefundGrid
{
    /** @param list<array{until: string, percent: int}> $steps */
    public function __construct(
        private readonly ?string $deadline,
        private readonly array $steps,
    ) {
    }

    public static function fromSettings(mixed $deadline, mixed $grid): self
    {
        $steps = [];
        foreach (is_array($grid) ? $grid : [] as $step) {
            if (is_array($step) && is_string($step['until'] ?? null) && is_int($step['percent'] ?? null) && $step['percent'] >= 0 && $step['percent'] <= 100) {
                $steps[] = ['until' => $step['until'], 'percent' => $step['percent']];
            }
        }
        usort($steps, static fn (array $a, array $b): int => strcmp($a['until'], $b['until']));

        return new self(is_string($deadline) && $deadline !== '' ? $deadline : null, $steps);
    }

    public function percentOn(string $day): int
    {
        if ($this->deadline === null || $day > $this->deadline) {
            return 0;
        }
        foreach ($this->steps as $step) {
            if ($day <= $step['until']) {
                return $step['percent'];
            }
        }

        return 0;
    }

    public function refundXaf(string $day, int $refundablePaidXaf): int
    {
        return intdiv(max(0, $refundablePaidXaf) * $this->percentOn($day), 100);
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Domain;

/**
 * Situation d'un compte de scolarité à une date (calcul pur) : état de chaque tranche, retard (RG-FIN-3 : le lendemain de la date
 * limite), étudiant « à jour » (aucune tranche échue impayée) et relances du jour (J-7 = NOT-08, J+1 et J+15 = NOT-09).
 */
final class AccountLedger
{
    public const PAID = 'paid';
    public const PARTIAL = 'partial';
    public const PENDING = 'pending';
    public const OVERDUE = 'overdue';

    /** @param list<array{id: string, label: string, due_on: string, amount_xaf: int, paid_xaf: int}> $installments */
    public function __construct(private readonly array $installments, private readonly string $today)
    {
    }

    /** @return list<array{id: string, label: string, due_on: string, amount_xaf: int, paid_xaf: int, remaining_xaf: int, status: string, overdue: bool}> */
    public function lines(): array
    {
        $out = [];
        foreach ($this->sorted() as $row) {
            $remaining = max(0, $row['amount_xaf'] - $row['paid_xaf']);
            $overdue = $remaining > 0 && $row['due_on'] < $this->today;
            $status = $remaining === 0 ? self::PAID : ($overdue ? self::OVERDUE : ($row['paid_xaf'] > 0 ? self::PARTIAL : self::PENDING));
            $out[] = [
                'id' => $row['id'], 'label' => $row['label'], 'due_on' => $row['due_on'], 'amount_xaf' => $row['amount_xaf'],
                'paid_xaf' => $row['paid_xaf'], 'remaining_xaf' => $remaining, 'status' => $status, 'overdue' => $overdue,
            ];
        }

        return $out;
    }

    public function overdueXaf(): int
    {
        return array_sum(array_map(static fn (array $l): int => $l['overdue'] ? $l['remaining_xaf'] : 0, $this->lines()));
    }

    public function remainingXaf(): int
    {
        return array_sum(array_column($this->lines(), 'remaining_xaf'));
    }

    /** À jour : aucune tranche dont la date limite est passée n'a de reste à payer. */
    public function isUpToDate(): bool
    {
        return $this->overdueXaf() === 0;
    }

    /** @return array{id: string, label: string, due_on: string, remaining_xaf: int}|null prochaine tranche à payer */
    public function nextDue(): ?array
    {
        foreach ($this->lines() as $line) {
            if ($line['remaining_xaf'] > 0) {
                return ['id' => $line['id'], 'label' => $line['label'], 'due_on' => $line['due_on'], 'remaining_xaf' => $line['remaining_xaf']];
            }
        }

        return null;
    }

    /**
     * Relances à envoyer ce jour (RG-FIN-3) : décalage négatif = avant la date limite (NOT-08), positif = après (NOT-09).
     *
     * @param list<int> $offsets décalages en jours (défaut -7, 1, 15)
     * @return list<array{installment_id: string, label: string, due_on: string, remaining_xaf: int, offset: int, notification: string}>
     */
    public function remindersOn(array $offsets): array
    {
        $out = [];
        foreach ($this->lines() as $line) {
            if ($line['remaining_xaf'] <= 0) {
                continue;
            }
            foreach ($offsets as $offset) {
                if (self::shift($line['due_on'], $offset) === $this->today) {
                    $out[] = [
                        'installment_id' => $line['id'], 'label' => $line['label'], 'due_on' => $line['due_on'],
                        'remaining_xaf' => $line['remaining_xaf'], 'offset' => $offset, 'notification' => $offset < 0 ? 'NOT-08' : 'NOT-09',
                    ];
                }
            }
        }

        return $out;
    }

    public static function shift(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** @return list<array{id: string, label: string, due_on: string, amount_xaf: int, paid_xaf: int}> */
    private function sorted(): array
    {
        $rows = $this->installments;
        usort($rows, static fn (array $a, array $b): int => [$a['due_on'], $a['id']] <=> [$b['due_on'], $b['id']]);

        return $rows;
    }
}

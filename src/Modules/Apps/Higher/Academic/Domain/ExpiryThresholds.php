<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Domain;

/**
 * Seuils d'alerte d'expiration (EX-ACA-07, défaut 90/30/7 jours). Une alerte part au plus petit seuil franchi qui n'a pas encore
 * été envoyé pour cette échéance ; les seuils plus grands franchis en même temps sont réputés traités (pas de rafale après une
 * interruption de la tâche). Une échéance passée ne déclenche plus rien.
 */
final class ExpiryThresholds
{
    public const DEFAULT = [90, 30, 7];

    /** @var list<int> */
    public readonly array $days;

    /** @param array<mixed> $days */
    public function __construct(array $days = self::DEFAULT)
    {
        $clean = [];
        foreach ($days as $day) {
            if (is_int($day) && $day >= 0 && $day <= 365) {
                $clean[$day] = $day;
            }
        }
        rsort($clean);
        $this->days = $clean === [] ? self::DEFAULT : $clean;
    }

    public static function daysBetween(string $today, string $expiresOn): int
    {
        $from = new \DateTimeImmutable(substr($today, 0, 10) . ' 00:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable(substr($expiresOn, 0, 10) . ' 00:00:00', new \DateTimeZone('UTC'));

        return (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400);
    }

    /**
     * @param list<int> $alreadySent seuils déjà envoyés pour cette échéance
     * @return array{0: int, 1: list<int>}|null [seuil à annoncer, seuils à marquer envoyés] ou null
     */
    public function due(int $daysLeft, array $alreadySent): ?array
    {
        if ($daysLeft < 0) {
            return null;
        }
        $crossed = array_values(array_filter($this->days, static fn (int $t): bool => $daysLeft <= $t));
        if ($crossed === []) {
            return null;
        }
        $smallest = min($crossed);
        if (in_array($smallest, $alreadySent, true)) {
            return null;
        }

        return [$smallest, array_values(array_diff($crossed, $alreadySent))];
    }
}

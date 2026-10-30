<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Domain;

/**
 * Empreinte SHA-256 des décisions d'une délibération, au format normalisé du socle (S8 : en-tête, puis lignes triées par personne,
 * moyenne à deux décimales). Permet de vérifier que les décisions reportées sur un document (PV DOC-15, liste DOC-16) sont
 * exactement celles que le jury a arrêtées à la clôture, sans lire les tables du socle.
 */
final class MinutesFingerprint
{
    /** @param list<array{person_id: string, average: float|null, credits_earned: int, credits_total: int, decision: string|null, reason: string|null}> $lines */
    public static function of(string $deliberationId, string $structureId, string $periodId, string $session, int $ruleVersion, array $lines): string
    {
        $normalized = [];
        foreach ($lines as $line) {
            $normalized[$line['person_id']] = [
                $line['person_id'],
                $line['average'] === null ? null : number_format($line['average'], 2, '.', ''),
                $line['credits_earned'],
                $line['credits_total'],
                $line['decision'],
                $line['reason'],
            ];
        }
        ksort($normalized, SORT_STRING);
        $content = [
            'deliberation' => $deliberationId,
            'structure' => $structureId,
            'period' => $periodId,
            'session' => $session,
            'rule_version' => $ruleVersion,
            'lines' => array_values($normalized),
        ];

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}

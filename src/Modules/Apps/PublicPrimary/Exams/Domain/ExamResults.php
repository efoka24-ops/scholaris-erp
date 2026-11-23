<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Exams\Domain;

/**
 * Résultats officiels d'un examen (EX-EXA-05) : l'application ne calcule ni moyenne ni admission, elle importe le résultat publié
 * (admis, ajourné, absent) et en tire le taux de réussite du CDC : admis ÷ candidats PRÉSENTS (« Indicateurs et formules »).
 */
final class ExamResults
{
    public const PASSED = 'passed';
    public const FAILED = 'failed';
    public const ABSENT = 'absent';
    public const OUTCOMES = [self::PASSED, self::FAILED, self::ABSENT];

    private const ALIASES = [
        'passed' => self::PASSED, 'pass' => self::PASSED, 'admis' => self::PASSED, 'admise' => self::PASSED,
        'failed' => self::FAILED, 'fail' => self::FAILED, 'ajourne' => self::FAILED, 'ajournee' => self::FAILED, 'ajourné' => self::FAILED, 'ajournée' => self::FAILED,
        'absent' => self::ABSENT, 'absente' => self::ABSENT, 'abs' => self::ABSENT,
    ];

    private function __construct()
    {
    }

    /** Résultat normalisé (anglais, français, casse indifférente) ou null s'il n'est pas reconnu. */
    public static function normalise(string $raw): ?string
    {
        return self::ALIASES[mb_strtolower(trim($raw))] ?? null;
    }

    /** Un candidat est présent s'il a composé : admis ou ajourné. */
    public static function isPresent(string $outcome): bool
    {
        return $outcome === self::PASSED || $outcome === self::FAILED;
    }

    /**
     * @param array<string, int> $counts effectifs par résultat (passed, failed, absent)
     * @return array{candidates: int, present: int, passed: int, failed: int, absent: int, success_rate: float|null}
     *         success_rate en pourcentage arrondi au dixième, null sans candidat présent
     */
    public static function summary(array $counts): array
    {
        $passed = $counts[self::PASSED] ?? 0;
        $failed = $counts[self::FAILED] ?? 0;
        $absent = $counts[self::ABSENT] ?? 0;
        $present = $passed + $failed;

        return [
            'candidates' => $present + $absent, 'present' => $present, 'passed' => $passed, 'failed' => $failed, 'absent' => $absent,
            'success_rate' => $present === 0 ? null : round($passed * 100 / $present, 1),
        ];
    }

    /**
     * Lignes d'import (CSV « ; » ou « , » avec en-tête, ou liste JSON) : clé `candidate_number` ou `learner_number` (identifiant national),
     * colonne `result`. Une ligne illisible garde sa clé vide ou son résultat null : elle sera rejetée avec son motif.
     *
     * @return list<array{candidate_number: string, learner_number: string, result: ?string, line: int}>
     */
    public static function fromCsv(string $csv): array
    {
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv) ?: [], static fn (string $line): bool => trim($line) !== ''));
        if ($lines === []) {
            return [];
        }
        $separator = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map(static fn (?string $name): string => mb_strtolower(trim((string) $name)), str_getcsv($lines[0], $separator));
        $rows = [];
        foreach (array_slice($lines, 1) as $index => $line) {
            $cells = str_getcsv($line, $separator);
            $record = [];
            foreach ($header as $position => $name) {
                $record[$name] = trim((string) ($cells[$position] ?? ''));
            }
            $rows[] = self::row($record, $index + 2);
        }

        return $rows;
    }

    /**
     * @param list<mixed> $items objets {candidate_number|learner_number, result}
     * @return list<array{candidate_number: string, learner_number: string, result: ?string, line: int}>
     */
    public static function fromList(array $items): array
    {
        $rows = [];
        foreach ($items as $index => $item) {
            $record = [];
            foreach (is_array($item) ? $item : [] as $name => $value) {
                $record[mb_strtolower((string) $name)] = is_scalar($value) ? trim((string) $value) : '';
            }
            $rows[] = self::row($record, $index + 1);
        }

        return $rows;
    }

    /**
     * @param array<string, string> $record
     * @return array{candidate_number: string, learner_number: string, result: ?string, line: int}
     */
    private static function row(array $record, int $line): array
    {
        return [
            'candidate_number' => $record['candidate_number'] ?? ($record['numero'] ?? ''),
            'learner_number' => $record['learner_number'] ?? ($record['identifiant'] ?? ''),
            'result' => self::normalise($record['result'] ?? ($record['resultat'] ?? '')),
            'line' => $line,
        ];
    }
}

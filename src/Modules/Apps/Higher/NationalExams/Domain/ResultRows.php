<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\NationalExams\Domain;

/**
 * Lignes de résultats publiés par le MINESUP (EX-EXN-06) : CSV « ; » ou « , » avec en-tête (candidate_number ou matricule,
 * result, mark facultatif) ou lignes JSON. Résultats reconnus : passed/failed/absent et leurs équivalents français
 * (admis, ajourné, absent).
 */
final class ResultRows
{
    private const ALIASES = [
        'passed' => 'passed', 'admis' => 'passed', 'admise' => 'passed', 'pass' => 'passed',
        'failed' => 'failed', 'ajourne' => 'failed', 'ajourné' => 'failed', 'ajournee' => 'failed', 'ajournée' => 'failed', 'fail' => 'failed',
        'absent' => 'absent', 'absente' => 'absent', 'abs' => 'absent',
    ];

    private function __construct()
    {
    }

    /** @return list<array{key: string, result: ?string, mark: ?float, line: int}> */
    public static function fromCsv(string $csv): array
    {
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv) ?: [], static fn (string $l): bool => trim($l) !== ''));
        if ($lines === []) {
            return [];
        }
        $separator = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map(static fn (?string $h): string => strtolower(trim((string) $h)), str_getcsv($lines[0], $separator));
        $rows = [];
        foreach (array_slice($lines, 1) as $index => $line) {
            $cells = str_getcsv($line, $separator);
            $record = [];
            foreach ($header as $i => $name) {
                $record[$name] = trim((string) ($cells[$i] ?? ''));
            }
            $rows[] = self::normalise($record, $index + 2);
        }

        return $rows;
    }

    /**
     * @param list<mixed> $items objets {candidate_number|matricule, result, mark}
     * @return list<array{key: string, result: ?string, mark: ?float, line: int}>
     */
    public static function fromList(array $items): array
    {
        $rows = [];
        foreach ($items as $index => $item) {
            $record = [];
            foreach (is_array($item) ? $item : [] as $name => $value) {
                $record[strtolower((string) $name)] = is_scalar($value) ? trim((string) $value) : '';
            }
            $rows[] = self::normalise($record, $index + 1);
        }

        return $rows;
    }

    /**
     * @param array<string, string> $record
     * @return array{key: string, result: ?string, mark: ?float, line: int}
     */
    private static function normalise(array $record, int $line): array
    {
        $key = $record['candidate_number'] ?? '';
        if ($key === '') {
            $key = $record['matricule'] ?? '';
        }
        $raw = mb_strtolower($record['result'] ?? '');
        $mark = str_replace(',', '.', $record['mark'] ?? '');

        return [
            'key' => $key,
            'result' => self::ALIASES[$raw] ?? null,
            'mark' => is_numeric($mark) && (float) $mark >= 0 && (float) $mark <= 20 ? round((float) $mark, 2) : null,
            'line' => $line,
        ];
    }
}

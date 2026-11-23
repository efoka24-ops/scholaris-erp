<?php

declare(strict_types=1);

namespace Scholaris\Core\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Export CSV d'une liste, commun aux contrôleurs : UTF-8 avec BOM (ouverture directe dans Excel), séparateur « ; »,
 * cellules neutralisées contre l'injection de formule. Le contrôleur reste responsable de l'autorisation, du
 * filtrage par client / établissement et du plafond de lignes (il passe un itérable déjà borné).
 */
trait ExportsList
{
    /**
     * @param list<string> $headers en-têtes de colonnes
     * @param iterable<list<mixed>> $rows une ligne = une liste de valeurs dans l'ordre des en-têtes
     */
    protected function csvBody(array $headers, iterable $rows): string
    {
        $handle = fopen('php://temp/maxmemory:2097152', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('Export impossible.');
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_map(self::csvCell(...), $headers), ';');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::csvCell(...), $row), ';');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Réponse CSV complète (en-têtes HTTP de téléchargement comprises).
     *
     * @param list<string> $headers
     * @param iterable<list<mixed>> $rows
     */
    protected function csvResponse(
        ResponseFactoryInterface $responses,
        StreamFactoryInterface $streams,
        string $filename,
        array $headers,
        iterable $rows,
        ?ServerRequestInterface $request = null,
    ): ResponseInterface {
        $safe = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
        if ($request !== null && self::exportFormat($request) === 'xlsx') {
            $name = (string) preg_replace('/\.csv$/i', '', $safe) . '.xlsx';
            $title = (string) preg_replace('/\.[A-Za-z0-9]+$/', '', $safe);

            return $responses->createResponse(200)
                ->withHeader('Content-Type', XlsxWriter::CONTENT_TYPE)
                ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
                ->withHeader('Cache-Control', 'no-store')
                ->withBody($streams->createStream((new XlsxWriter())->build($headers, $rows, str_replace(['_', '-'], ' ', $title))));
        }

        return $responses->createResponse(200)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safe . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($streams->createStream($this->csvBody($headers, $rows)));
    }

    /** Format demandé (`?format=csv|xlsx`, csv par défaut) ; toute autre valeur donne une erreur 422. */
    protected static function exportFormat(ServerRequestInterface $request): string
    {
        $format = $request->getQueryParams()['format'] ?? 'csv';
        $format = is_string($format) && $format !== '' ? strtolower($format) : 'csv';
        if (!in_array($format, ['csv', 'xlsx'], true)) {
            throw HttpException::validation(['format' => ['Le format doit être csv ou xlsx.']]);
        }

        return $format;
    }

    /** Texte d'une cellule : null vide, booléen 0/1, formule neutralisée par une apostrophe. */
    protected static function csvCell(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) || $value instanceof \Stringable => (string) $value,
            default => '',
        };

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $text : $text;
    }
}

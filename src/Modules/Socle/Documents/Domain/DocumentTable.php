<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Domain;

use Scholaris\Core\Http\HttpException;

/**
 * Tableau structuré fourni par une application (bulletins, relevés) et transformé en HTML par le MOTEUR : l'application ne
 * fournit jamais de HTML, chaque cellule est échappée. Le modèle l'insère par `{{table:<variable>}}` ; la valeur de la variable
 * est un JSON (texte, donc une valeur simple pour le contrat d'émission) :
 *
 *   {"columns": [{"label": "Matière", "align": "left", "width": 30}, ...],   // facultatif : une ligne d'en-tête simple
 *    "head": [[cellule, ...], ...],                                             // facultatif : en-tête sur plusieurs lignes
 *    "rows": [{"kind": "group", "cells": [cellule, ...]}, [cellule, ...], ...],
 *    "foot": [ ... comme rows ... ], "class": "compact"}
 *
 * Une cellule est un texte, un nombre, null, ou {"text", "colspan", "rowspan", "align", "bold"}. Les genres de ligne permis
 * (`group`, `total`, `subtotal`, `section`, `summary`, `muted`) deviennent des classes CSS `doc-row-<genre>` que le modèle met en
 * forme (lignes TOTAL grisées, titres de groupe). Un saut de ligne dans un texte donne `<br>`. Une valeur qui n'est pas un tableau
 * JSON (ancien bloc de texte) est rendue telle quelle, échappée, en conservant ses sauts de ligne (rétrocompatibilité).
 */
final class DocumentTable
{
    public const PREFIX = 'table:';
    public const KINDS = ['row', 'group', 'total', 'subtotal', 'section', 'summary', 'muted'];
    public const ALIGNS = ['left', 'center', 'right'];
    public const CLASSES = ['compact', 'grid', 'plain'];
    public const MAX_ROWS = 400;
    public const MAX_CELLS = 40;
    public const MAX_TEXT = 2000;
    /** Mise en forme de base (le modèle peut la surcharger) : bordures, en-têtes grisés, lignes de groupe et de total. */
    public const STYLE = '.doc-table{border-collapse:collapse;width:100%;margin:3px 0}'
        . '.doc-table th,.doc-table td{border:1px solid #555;padding:1px 3px;vertical-align:middle}'
        . '.doc-table th{background:#e8e8e8;text-align:center;font-weight:bold}'
        . '.doc-row-group td{font-weight:bold;background:#f2f2f2}.doc-row-section td{font-weight:bold;text-transform:uppercase}'
        . '.doc-row-total td,.doc-row-subtotal td,.doc-row-summary td{font-weight:bold;background:#d9d9d9}.doc-row-muted td{color:#666}'
        . '.doc-table-plain td,.doc-table-plain th{border:0}.doc-table-compact td,.doc-table-compact th{padding:0 2px}'
        . '.doc-left{text-align:left}.doc-center{text-align:center}.doc-right{text-align:right}.doc-bold{font-weight:bold}'
        . '.doc-table-text{white-space:pre-line}';

    /** Nom de la variable d'un emplacement `table:<variable>`, ou null pour un emplacement ordinaire. */
    public static function variableOf(string $placeholder): ?string
    {
        return str_starts_with($placeholder, self::PREFIX) ? trim(substr($placeholder, strlen(self::PREFIX))) : null;
    }

    /**
     * Encode un tableau d'application en valeur de variable (JSON).
     *
     * @param array<string, mixed> $table
     */
    public static function encode(array $table): string
    {
        return json_encode($table, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** HTML sûr d'une valeur de variable : tableau si c'est un tableau JSON valide, sinon texte échappé. */
    public static function html(string $value): string
    {
        $trimmed = ltrim($value);
        if ($trimmed === '') {
            return '';
        }
        $decoded = str_starts_with($trimmed, '{') ? json_decode($trimmed, true) : null;
        if (!is_array($decoded) || !isset($decoded['rows'])) {
            return '<div class="doc-table-text">' . self::text($value) . '</div>';
        }

        return self::build($decoded);
    }

    /**
     * @param array<mixed> $table
     * @throws HttpException 422 si le tableau est mal formé
     */
    private static function build(array $table): string
    {
        $class = 'doc-table';
        if (isset($table['class'])) {
            if (!is_string($table['class']) || !in_array($table['class'], self::CLASSES, true)) {
                self::fail('classe de tableau inconnue (' . implode(', ', self::CLASSES) . ').');
            }
            $class .= ' doc-table-' . $table['class'];
        }
        $out = '<table class="' . $class . '" cellspacing="0">';

        $columns = $table['columns'] ?? [];
        if (!is_array($columns) || count($columns) > self::MAX_CELLS) {
            self::fail('colonnes invalides.');
        }
        $widths = '';
        $labels = [];
        foreach (array_values($columns) as $column) {
            $column = is_array($column) ? $column : ['label' => $column];
            $width = $column['width'] ?? null;
            if ($width !== null && (!is_int($width) || $width < 1 || $width > 100)) {
                self::fail('largeur de colonne : entier de 1 à 100 (pourcentage).');
            }
            $widths .= $width === null ? '<col>' : '<col width="' . $width . '%">';
            $labels[] = ['text' => $column['label'] ?? '', 'align' => $column['align'] ?? null];
        }
        if ($widths !== '') {
            $out .= '<colgroup>' . $widths . '</colgroup>';
        }

        $head = $table['head'] ?? null;
        if ($head !== null) {
            $out .= '<thead>' . self::rows($head, 'th') . '</thead>';
        } elseif ($labels !== [] && self::hasText($labels)) {
            $out .= '<thead>' . self::rows([$labels], 'th') . '</thead>';
        }
        $aligns = array_map(static fn (array $l): mixed => $l['align'], $labels);
        $out .= '<tbody>' . self::rows($table['rows'], 'td', $aligns) . '</tbody>';
        if (isset($table['foot'])) {
            $out .= '<tfoot>' . self::rows($table['foot'], 'td', $aligns) . '</tfoot>';
        }

        return $out . '</table>';
    }

    /** @param list<mixed> $aligns alignement par défaut de chaque colonne */
    private static function rows(mixed $rows, string $tag, array $aligns = []): string
    {
        if (!is_array($rows) || count($rows) > self::MAX_ROWS) {
            self::fail('lignes invalides (' . self::MAX_ROWS . ' au plus).');
        }
        $out = '';
        foreach ($rows as $row) {
            $kind = 'row';
            $cells = $row;
            if (is_array($row) && array_key_exists('cells', $row)) {
                $kind = $row['kind'] ?? 'row';
                $cells = $row['cells'];
            }
            if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
                self::fail('genre de ligne inconnu (' . implode(', ', self::KINDS) . ').');
            }
            if (!is_array($cells) || count($cells) > self::MAX_CELLS) {
                self::fail('cellules invalides (' . self::MAX_CELLS . ' au plus par ligne).');
            }
            $out .= $kind === 'row' ? '<tr>' : '<tr class="doc-row-' . $kind . '">';
            $index = 0;
            foreach ($cells as $cell) {
                $cell = is_array($cell) ? $cell : ['text' => $cell];
                if (!isset($cell['align']) && isset($aligns[$index])) {
                    $cell['align'] = $aligns[$index];
                }
                $out .= self::cell($cell, $tag);
                $index += isset($cell['colspan']) && is_int($cell['colspan']) ? max(1, $cell['colspan']) : 1;
            }
            $out .= '</tr>';
        }

        return $out;
    }

    /** @param array<mixed> $cell */
    private static function cell(array $cell, string $tag): string
    {
        $text = $cell['text'] ?? '';
        if (!is_scalar($text)) {
            self::fail('le texte d\'une cellule est un texte ou un nombre.');
        }
        $text = is_bool($text) ? ($text ? '1' : '0') : (string) $text;
        if (mb_strlen($text) > self::MAX_TEXT) {
            self::fail('texte de cellule trop long (' . self::MAX_TEXT . ' caractères au plus).');
        }
        $attributes = '';
        foreach (['colspan', 'rowspan'] as $span) {
            if (isset($cell[$span])) {
                if (!is_int($cell[$span]) || $cell[$span] < 1 || $cell[$span] > self::MAX_CELLS) {
                    self::fail($span . ' : entier de 1 à ' . self::MAX_CELLS . '.');
                }
                if ($cell[$span] > 1) {
                    $attributes .= ' ' . $span . '="' . $cell[$span] . '"';
                }
            }
        }
        $classes = [];
        if (isset($cell['align'])) {
            if (!is_string($cell['align']) || !in_array($cell['align'], self::ALIGNS, true)) {
                self::fail('alignement : left, center ou right.');
            }
            $classes[] = 'doc-' . $cell['align'];
        }
        if (($cell['bold'] ?? false) === true) {
            $classes[] = 'doc-bold';
        }
        if ($classes !== []) {
            $attributes .= ' class="' . implode(' ', $classes) . '"';
        }

        return '<' . $tag . $attributes . '>' . self::text($text) . '</' . $tag . '>';
    }

    /** @param list<array{text: mixed, align: mixed}> $labels */
    private static function hasText(array $labels): bool
    {
        foreach ($labels as $label) {
            if ($label['text'] !== '' && $label['text'] !== null) {
                return true;
            }
        }

        return false;
    }

    private static function text(string $text): string
    {
        return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }

    private static function fail(string $reason): never
    {
        throw HttpException::validation(['data' => ['Tableau de document mal formé : ' . $reason]]);
    }
}

<?php

declare(strict_types=1);

return static function (array $data): string {
    $esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $items = static function (array $lines) use ($esc): string {
        if ($lines === []) {
            return '<p>No item.</p>';
        }

        return '<ul><li>' . implode('</li><li>', array_map($esc, $lines)) . '</li></ul>';
    };

    return '<html><body style="font-family: DejaVu Sans, sans-serif; color: #1d2430;">'
        . '<h1>DOC-20 · Transition sheet</h1>'
        . '<p><strong>Learner:</strong> ' . $esc($data['learner_name'] ?? '') . '</p>'
        . '<p><strong>School year:</strong> ' . $esc($data['school_year'] ?? '') . '</p>'
        . '<p><strong>Path summary:</strong> ' . $esc($data['path_summary'] ?? '') . '</p>'
        . '<h2>Strengths</h2>' . $items((array) ($data['strengths'] ?? []))
        . '<h2>Support needs</h2>' . $items((array) ($data['needs'] ?? []))
        . '<p><strong>Status:</strong> ' . $esc($data['status'] ?? '') . '</p>'
        . '<p><strong>Signature:</strong> ' . $esc($data['signed_by'] ?? '—') . ' ' . $esc($data['signed_at'] ?? '') . '</p>'
        . '</body></html>';
};

<?php

declare(strict_types=1);

return static function (array $data): string {
    $esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $items = static function (array $lines) use ($esc): string {
        if ($lines === []) {
            return '<p>Aucune mention.</p>';
        }

        return '<ul><li>' . implode('</li><li>', array_map($esc, $lines)) . '</li></ul>';
    };

    return '<html><body style="font-family: DejaVu Sans, sans-serif; color: #1d2430;">'
        . '<h1>DOC-20 · Fiche de liaison</h1>'
        . '<p><strong>Enfant :</strong> ' . $esc($data['learner_name'] ?? '') . '</p>'
        . '<p><strong>Année scolaire :</strong> ' . $esc($data['school_year'] ?? '') . '</p>'
        . '<p><strong>Parcours :</strong> ' . $esc($data['path_summary'] ?? '') . '</p>'
        . '<h2>Points d’appui</h2>' . $items((array) ($data['strengths'] ?? []))
        . '<h2>Besoins à accompagner</h2>' . $items((array) ($data['needs'] ?? []))
        . '<p><strong>Statut :</strong> ' . $esc($data['status'] ?? '') . '</p>'
        . '<p><strong>Signature :</strong> ' . $esc($data['signed_by'] ?? '—') . ' ' . $esc($data['signed_at'] ?? '') . '</p>'
        . '</body></html>';
};

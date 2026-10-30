<?php

declare(strict_types=1);

return static function (array $book, ?string $revisionLabel = null): string {
    $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $items = $book['items'] ?? [];
    $rows = '';
    foreach ($items as $item) {
        $rows .= '<tr><td>' . $e($item['domain'] ?? '') . '</td><td>' . $e($item['label'] ?? '') . '</td><td>' . $e($item['level'] ?? '') . '</td><td>' . $e($item['comment'] ?? '') . '</td></tr>';
    }

    return '<html><body><h1>Quarterly progress book</h1>'
        . '<p><strong>' . $e($book['learner_name'] ?? 'Child') . '</strong> · ' . $e($book['period_label'] ?? '') . ' · ' . $e($book['school_year'] ?? '') . '</p>'
        . ($revisionLabel !== null ? '<p class="duplicata"><strong>' . $e($revisionLabel === 'rectified' ? 'Rectified copy' : 'Duplicate') . '</strong></p>' : '')
        . '<p>Positive summary: ' . $e($book['synthesis'] ?? '') . '</p>'
        . '<table><thead><tr><th>Domain</th><th>Item</th><th>Level</th><th>Comment</th></tr></thead><tbody>' . $rows . '</tbody></table>'
        . '<p>Validated by: ' . $e($book['validated_by_name'] ?? 'director') . '</p>'
        . '</body></html>';
};

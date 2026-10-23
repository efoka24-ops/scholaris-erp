<?php

declare(strict_types=1);

$env = static fn (string $key, mixed $default = null): mixed => $_ENV[$key] ?? $_SERVER[$key] ?? $default;

/** Décode un objet JSON d'environnement ; toute valeur invalide donne le défaut. */
$json = static function (string $key, array $default) use ($env): array {
    $raw = (string) $env($key, '');
    if ($raw === '') {
        return $default;
    }
    $data = json_decode($raw, true);

    return is_array($data) ? $data : $default;
};

return [
    // « log » (défaut) : simulateur. « http » n'est effectif que si SMS_HTTP_URL est renseigné.
    'gateway' => strtolower((string) $env('SMS_GATEWAY', 'log')),
    'http' => [
        'url' => (string) $env('SMS_HTTP_URL', ''),
        'method' => strtoupper((string) $env('SMS_HTTP_METHOD', 'POST')),
        // En-têtes fixes (objet JSON), hors jeton.
        'headers' => $json('SMS_HTTP_HEADERS', []),
        // Jeton : uniquement dans le .env local ou du serveur.
        'token' => (string) $env('SMS_HTTP_TOKEN', ''),
        'token_header' => (string) $env('SMS_HTTP_TOKEN_HEADER', 'Authorization'),
        'token_prefix' => (string) $env('SMS_HTTP_TOKEN_PREFIX', 'Bearer '),
        // Modèle du corps JSON ; {{to}}, {{message}} et {{sender}} sont remplacés.
        'body_template' => $json('SMS_HTTP_BODY', ['to' => '{{to}}', 'message' => '{{message}}']),
        'sender' => (string) $env('SMS_HTTP_SENDER', ''),
        // Chemin pointé de la référence du message dans la réponse JSON (ex. data.id) ; vide = référence générée.
        'reference_path' => (string) $env('SMS_HTTP_REFERENCE_PATH', ''),
        'timeout_seconds' => 15,
    ],
];

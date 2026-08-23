<?php

declare(strict_types=1);

namespace Scholaris\Support;

use RuntimeException;

/**
 * Client HTTP minimal.
 *
 * Utilise cURL quand l'extension est presente, et retombe sur les flux PHP
 * sinon : l'hebergement mutualise ne garantit pas cURL, et une passerelle de
 * paiement ne doit pas cesser de fonctionner pour cette raison.
 *
 * La verification du certificat pair n'est jamais desactivee : sans elle,
 * l'appel a la passerelle serait interceptable.
 */
final class Http
{
    /** @var array<string, string> */
    private array $headers = [];

    private int $timeout;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(array $headers = [], int $timeout = 30)
    {
        $this->headers = $headers;
        $this->timeout = $timeout;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: string, json: array<string, mixed>|null}
     */
    public function postJson(string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($body === false) {
            throw new RuntimeException('Corps de requete JSON invalide.');
        }

        return $this->send('POST', $url, $body, ['Content-Type' => 'application/json']);
    }

    /**
     * @return array{status: int, body: string, json: array<string, mixed>|null}
     */
    public function get(string $url): array
    {
        return $this->send('GET', $url, null, []);
    }

    /**
     * @param  array<string, string>  $extraHeaders
     * @return array{status: int, body: string, json: array<string, mixed>|null}
     */
    private function send(string $method, string $url, ?string $body, array $extraHeaders): array
    {
        $headers = array_merge($this->headers, $extraHeaders, ['Accept' => 'application/json']);

        $result = function_exists('curl_init')
            ? $this->sendWithCurl($method, $url, $body, $headers)
            : $this->sendWithStream($method, $url, $body, $headers);

        $decoded = json_decode($result['body'], true);

        return [
            'status' => $result['status'],
            'body' => $result['body'],
            'json' => is_array($decoded) ? $decoded : null,
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string}
     */
    private function sendWithCurl(string $method, string $url, ?string $body, array $headers): array
    {
        $formatted = [];

        foreach ($headers as $name => $value) {
            $formatted[] = $name.': '.$value;
        }

        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Initialisation cURL impossible.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $formatted,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);

        curl_close($handle);

        if ($response === false) {
            throw new RuntimeException('Appel HTTP echoue : '.$error);
        }

        return ['status' => $status, 'body' => (string) $response];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string}
     */
    private function sendWithStream(string $method, string $url, ?string $body, array $headers): array
    {
        $lines = '';

        foreach ($headers as $name => $value) {
            $lines .= $name.': '.$value."\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $lines,
                'content' => $body ?? '',
                'timeout' => $this->timeout,
                // Le corps des reponses 4xx et 5xx doit etre lu : c'est la que
                // la passerelle explique le refus.
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new RuntimeException('Appel HTTP echoue vers '.$url);
        }

        $status = 0;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return ['status' => $status, 'body' => $response];
    }
}

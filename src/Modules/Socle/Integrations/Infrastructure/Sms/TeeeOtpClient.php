<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Infrastructure\Sms;

use Closure;
use JsonException;
use Throwable;

/**
 * Client HTTP de TeeeOTP (service SMS mutualisé de TRU GROUP) : `POST /v1/sms`, `GET /v1/sms/{id}` et `GET /v1/me`
 * uniquement (les codes d'accès restent générés et vérifiés par SCHOLARIS : pas de `/v1/otp/*`).
 *
 * La clé API ne part que dans l'en-tête `X-Api-Key` ; elle n'est jamais journalisée ni reprise dans une exception.
 * HTTPS obligatoire. Erreurs `{ "error": { "code", "message" } }` traduites en TeeeOtpException.
 */
final class TeeeOtpClient
{
    /** @var Closure(string, string, array<string, string>, ?string, int): array{status: int, body: string} */
    private readonly Closure $transport;
    private readonly string $baseUrl;

    /**
     * @param (callable(string, string, array<string, string>, ?string, int): array{status: int, body: string})|null $transport substituable dans les tests ; par défaut cURL
     */
    public function __construct(
        string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 10,
        ?callable $transport = null,
    ) {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->transport = Closure::fromCallable($transport ?? self::curlTransport(...));
    }

    /** @return string identifiant TeeeOTP du SMS */
    public function sendSms(string $phone, string $message, ?int $ttlSeconds = null): string
    {
        $payload = ['phone' => $phone, 'message' => $message];
        if ($ttlSeconds !== null) {
            $payload['ttlSeconds'] = $ttlSeconds;
        }
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new TeeeOtpException(TeeeOtpException::INVALID_MESSAGE);
        }
        $data = $this->request('POST', '/v1/sms', $body);
        $id = $data['id'] ?? null;
        if (!is_string($id) && !is_int($id) || (string) $id === '') {
            throw new TeeeOtpException(TeeeOtpException::UNEXPECTED);
        }

        return (string) $id;
    }

    /** @return string statut TeeeOTP : queued, sending, sent, delivered, failed ou expired */
    public function smsStatus(string $id): string
    {
        $data = $this->request('GET', '/v1/sms/' . rawurlencode($id), null);
        $status = $data['status'] ?? null;
        if (!is_string($status) || $status === '') {
            throw new TeeeOtpException(TeeeOtpException::UNEXPECTED);
        }

        return strtolower($status);
    }

    /** @return array<string, mixed> projet de la clé : mode, quota, allocation de test, consommation */
    public function me(): array
    {
        return $this->request('GET', '/v1/me', null);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?string $body): array
    {
        if ($this->baseUrl === '' || $this->apiKey === '') {
            throw new TeeeOtpException(TeeeOtpException::NOT_CONFIGURED);
        }
        $headers = ['Accept' => 'application/json', 'X-Api-Key' => $this->apiKey];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        try {
            $response = ($this->transport)($method, $this->baseUrl . $path, $headers, $body, $this->timeoutSeconds);
        } catch (TeeeOtpException $e) {
            throw $e;
        } catch (Throwable) {
            throw new TeeeOtpException(TeeeOtpException::UNREACHABLE);
        }

        $status = $response['status'];
        $data = json_decode($response['body'], true);
        if ($status >= 200 && $status < 300) {
            if (!is_array($data)) {
                throw new TeeeOtpException(TeeeOtpException::UNEXPECTED, $status);
            }

            return $data;
        }

        $error = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];
        $code = is_string($error['code'] ?? null) ? strtoupper((string) $error['code']) : '';
        if ($code === '' || preg_match('/^[A-Z0-9_]{1,40}$/', $code) !== 1) {
            $code = match (true) {
                $status === 401 || $status === 403 => TeeeOtpException::UNAUTHORIZED,
                $status === 429 => TeeeOtpException::TOO_MANY_REQUESTS,
                default => TeeeOtpException::UNEXPECTED,
            };
        }
        if (in_array($code, ['INVALID_API_KEY', 'MISSING_API_KEY', 'FORBIDDEN'], true)) {
            $code = TeeeOtpException::UNAUTHORIZED;
        }
        $retryAfter = $error['retryAfter'] ?? null;

        throw new TeeeOtpException($code, $status, is_int($retryAfter) ? $retryAfter : null);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public static function curlTransport(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new TeeeOtpException(TeeeOtpException::NOT_CONFIGURED);
        }
        if (!function_exists('curl_init')) {
            throw new TeeeOtpException(TeeeOtpException::UNREACHABLE);
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $handle = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method === '' ? null : $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);
        $result = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($handle);
        curl_close($handle);

        if ($result === false || $error !== 0) {
            throw new TeeeOtpException(TeeeOtpException::UNREACHABLE);
        }

        return ['status' => $status, 'body' => (string) $result];
    }
}

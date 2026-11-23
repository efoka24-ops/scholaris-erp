<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Infrastructure\Sms;

use Closure;
use JsonException;
use Psr\Log\LoggerInterface;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Throwable;

/**
 * Adaptateur SMS générique par HTTP : aucun fournisseur n'est présumé. L'URL, la méthode, les en-têtes, le jeton et le
 * modèle de corps JSON viennent de la configuration (config/sms.php). Dans les chaînes du modèle, {{to}}, {{message}}
 * et {{sender}} sont remplacés APRÈS décodage du JSON : aucune valeur ne peut casser la structure du corps.
 *
 * HTTPS obligatoire. Le jeton, le numéro et le texte ne sont jamais journalisés ni repris dans les exceptions.
 */
final class HttpSmsGateway implements SmsGateway
{
    /** @var Closure(string, string, array<string, string>, string, int): array{status: int, body: string} */
    private readonly Closure $transport;

    /**
     * @param array<string, string> $headers en-têtes fixes (hors jeton)
     * @param array<string, mixed> $bodyTemplate corps JSON avec {{to}}, {{message}}, {{sender}}
     * @param (callable(string, string, array<string, string>, string, int): array{status: int, body: string})|null $transport substituable dans les tests ; par défaut cURL
     */
    public function __construct(
        private readonly string $url,
        private readonly string $method = 'POST',
        private readonly array $headers = [],
        private readonly string $token = '',
        private readonly string $tokenHeader = 'Authorization',
        private readonly string $tokenPrefix = 'Bearer ',
        private readonly array $bodyTemplate = ['to' => '{{to}}', 'message' => '{{message}}'],
        private readonly string $sender = '',
        private readonly string $referencePath = '',
        private readonly int $timeoutSeconds = 15,
        private readonly ?LoggerInterface $logger = null,
        ?callable $transport = null,
    ) {
        $this->transport = Closure::fromCallable($transport ?? self::curlTransport(...));
    }

    public function send(string $to, string $message): string
    {
        if ($this->url === '') {
            throw new SmsGatewayException('Passerelle SMS non configurée (SMS_HTTP_URL).');
        }
        if (preg_match('/^\+?[0-9]{6,15}$/', $to) !== 1) {
            throw new SmsGatewayException('Numéro de destinataire invalide.');
        }

        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'] + $this->headers;
        if ($this->token !== '') {
            $headers[$this->tokenHeader] = $this->tokenPrefix . $this->token;
        }

        try {
            $body = json_encode(
                self::substitute($this->bodyTemplate, ['{{to}}' => $to, '{{message}}' => $message, '{{sender}}' => $this->sender]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
            $response = ($this->transport)($this->method, $this->url, $headers, $body, $this->timeoutSeconds);
        } catch (JsonException) {
            throw new SmsGatewayException('Corps de la demande invalide.');
        } catch (Throwable $e) {
            $this->logger?->warning('Échec SMS HTTP', ['error' => $e::class]);

            throw new SmsGatewayException('Passerelle SMS injoignable.');
        }

        $status = $response['status'];
        if ($status < 200 || $status >= 300) {
            $this->logger?->warning('Refus de la passerelle SMS', ['http_status' => $status]);

            throw new SmsGatewayException(sprintf('La passerelle SMS a répondu HTTP %d.', $status), $status);
        }

        return $this->reference($response['body']);
    }

    private function reference(string $body): string
    {
        if ($this->referencePath !== '') {
            $value = json_decode($body, true);
            foreach (explode('.', $this->referencePath) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }
            if (is_string($value) && $value !== '' || is_int($value)) {
                return mb_substr('http-' . $value, 0, 80);
            }
        }

        return 'http-sms-' . new Ulid();
    }

    /**
     * @param array<array-key, mixed> $node
     * @param array<string, string> $replacements
     * @return array<array-key, mixed>
     */
    private static function substitute(array $node, array $replacements): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = self::substitute($value, $replacements);
            } elseif (is_string($value)) {
                $node[$key] = strtr($value, $replacements);
            }
        }

        return $node;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private static function curlTransport(string $method, string $url, array $headers, string $body, int $timeout): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new SmsGatewayException('HTTPS obligatoire.');
        }
        if (!function_exists('curl_init')) {
            throw new SmsGatewayException('Extension cURL absente.');
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method === '' ? null : $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $result = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($handle);
        curl_close($handle);

        if ($result === false || $error !== 0) {
            throw new SmsGatewayException('Erreur réseau cURL ' . $error);
        }

        return ['status' => $status, 'body' => (string) $result];
    }
}

<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Infrastructure\Sungku;

use Closure;
use JsonException;

/**
 * Client de la passerelle Sungku (relais vers pawaPay pour Orange Money et MTN MoMo).
 * Le backend ne parle jamais directement à pawaPay : uniquement à Sungku, en HTTPS, avec la clé API du projet.
 *
 * Un seul rôle : initier un dépôt. Deux issues d'échec strictement distinctes :
 *  - SungkuRejected    : refus clair (4xx hors 429) → échec prouvé ;
 *  - SungkuUnavailable : délai, 5xx, 429, réseau → échec NON prouvé, ne jamais supposer qu'il a échoué.
 * Un succès HTTP ne vaut pas paiement réussi : seul le webhook (SungkuWebhook) confirme.
 */
final class SungkuClient
{
    /** @var Closure(string, string, array<string, string>, string, int): array{status: int, body: string} */
    private readonly Closure $transport;

    /**
     * @param callable(string, string, array<string, string>, string, int): array{status: int, body: string}|null $transport
     *        substituable dans les tests ; par défaut cURL
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 15,
        ?callable $transport = null,
    ) {
        $this->transport = Closure::fromCallable($transport ?? self::curlTransport(...));
    }

    /**
     * @param array{amount: int, currency?: string, phoneNumber: string, reference: string, description?: string, customerMessage?: string, metadata?: array<string, scalar>} $body
     *        le montant est calculé côté serveur uniquement, jamais repris du client
     * @return array<string, mixed> réponse JSON de Sungku
     * @throws SungkuRejected
     * @throws SungkuUnavailable
     */
    public function initiateDeposit(array $body): array
    {
        if ($this->apiKey === '') {
            throw new SungkuUnavailable('Clé API Sungku non configurée.');
        }
        if ($body['amount'] <= 0) {
            throw new \InvalidArgumentException('Le montant doit être un entier positif en XAF.');
        }

        $payload = $body + ['currency' => 'XAF'];

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $response = ($this->transport)(
                'POST',
                $this->baseUrl . '/api/partners/deposits',
                ['X-Api-Key' => $this->apiKey, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
                $json,
                $this->timeoutSeconds,
            );
        } catch (JsonException $e) {
            throw new \InvalidArgumentException('Corps de la demande invalide.', 0, $e);
        } catch (\Throwable $e) {
            // Aucune trace de la clé dans le message : on n'expose que la nature de la panne.
            throw new SungkuUnavailable('Sungku injoignable : ' . $e::class);
        }

        $status = $response['status'];
        if ($status === 429 || $status >= 500 || $status === 0) {
            throw new SungkuUnavailable('Sungku indisponible (HTTP ' . $status . ').');
        }
        if ($status >= 400) {
            throw new SungkuRejected($status, 'Sungku a refusé la demande (HTTP ' . $status . ').');
        }

        $decoded = json_decode($response['body'], true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private static function curlTransport(string $method, string $url, array $headers, string $body, int $timeout): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new \RuntimeException('HTTPS obligatoire.');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Extension cURL absente.');
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
            throw new \RuntimeException('Erreur réseau cURL ' . $error);
        }

        return ['status' => $status, 'body' => (string) $result];
    }
}

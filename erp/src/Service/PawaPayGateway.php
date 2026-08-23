<?php

declare(strict_types=1);

namespace Scholaris\Service;

use RuntimeException;
use Scholaris\Support\Env;
use Scholaris\Support\Http;

/**
 * Passerelle Mobile Money pawaPay (API Marchand v2).
 *
 * Encaissement de la scolarite aupres des familles, par depot ("deposit").
 *
 * L'API est asynchrone : une initiation acceptee ne signifie pas que l'argent
 * est encaisse. Le statut definitif arrive par callback signe, avec la
 * consultation de statut comme filet de rattrapage si le callback se perd.
 * Toute la logique d'imputation attend donc COMPLETED, jamais ACCEPTED.
 */
final class PawaPayGateway
{
    private const SANDBOX_URL = 'https://api.sandbox.pawapay.io';

    private const PRODUCTION_URL = 'https://api.pawapay.io';

    /** Statuts renvoyes a l'initiation d'un depot. */
    public const INITIATION_ACCEPTED = 'ACCEPTED';

    public const INITIATION_REJECTED = 'REJECTED';

    public const INITIATION_DUPLICATE = 'DUPLICATE_IGNORED';

    /** Statuts du cycle de vie d'un depot. */
    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_PROCESSING = 'PROCESSING';

    public const STATUS_IN_RECONCILIATION = 'IN_RECONCILIATION';

    private string $baseUrl;

    private string $token;

    private Http $http;

    public function __construct(string $token, string $environment = 'sandbox', ?Http $http = null)
    {
        if ($token === '') {
            throw new RuntimeException('Jeton pawaPay absent : renseignez PAWAPAY_API_TOKEN dans .env.');
        }

        $this->token = $token;
        $this->baseUrl = $environment === 'production' ? self::PRODUCTION_URL : self::SANDBOX_URL;

        $this->http = $http ?? new Http([
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
        ]);
    }

    public static function fromEnv(Env $env, ?Http $http = null): self
    {
        return new self(
            $env->get('PAWAPAY_API_TOKEN', '') ?? '',
            $env->get('PAWAPAY_ENVIRONMENT', 'sandbox') ?? 'sandbox',
            $http
        );
    }

    public static function isConfigured(Env $env): bool
    {
        return ($env->get('PAWAPAY_API_TOKEN', '') ?? '') !== '';
    }

    public function environment(): string
    {
        return $this->baseUrl === self::PRODUCTION_URL ? 'production' : 'sandbox';
    }

    /**
     * Initie un encaissement Mobile Money.
     *
     * $depositId est genere par nous et sert de cle d'idempotence : rejouer la
     * meme requete renvoie DUPLICATE_IGNORED au lieu de debiter deux fois.
     * Le montant est transmis en chaine, l'API refusant les flottants.
     *
     * @return array{status: string, raw: array<string, mixed>|null, http: int, failureReason: string|null}
     */
    public function initiateDeposit(
        string $depositId,
        float $amount,
        string $currency,
        string $phoneNumber,
        string $provider
    ): array {
        $payload = [
            'depositId' => $depositId,
            // XAF n'a pas de decimales : un montant a virgule serait refuse.
            'amount' => $currency === 'XAF' ? (string) (int) round($amount) : number_format($amount, 2, '.', ''),
            'currency' => $currency,
            'payer' => [
                'type' => 'MMO',
                'accountDetails' => [
                    'phoneNumber' => self::normalisePhone($phoneNumber),
                    'provider' => $provider,
                ],
            ],
        ];

        $response = $this->http->postJson($this->baseUrl.'/v2/deposits', $payload);
        $json = $response['json'];

        return [
            'status' => (string) ($json['status'] ?? self::INITIATION_REJECTED),
            'raw' => $json,
            'http' => $response['status'],
            'failureReason' => $this->extractFailure($json),
        ];
    }

    /**
     * Consulte le statut d'un depot.
     *
     * Sert de filet quand le callback n'arrive pas : le statut fait foi cote
     * pawaPay, l'application ne doit jamais le deviner.
     *
     * @return array{status: string|null, raw: array<string, mixed>|null, http: int}
     */
    public function depositStatus(string $depositId): array
    {
        $response = $this->http->get($this->baseUrl.'/v2/deposits/'.rawurlencode($depositId));
        $json = $response['json'];

        // La reponse peut etre l'objet lui-meme ou une liste d'un element,
        // selon la version : les deux formes sont acceptees.
        $deposit = $json;

        if (is_array($json) && isset($json[0]) && is_array($json[0])) {
            $deposit = $json[0];
        }

        return [
            'status' => isset($deposit['status']) ? (string) $deposit['status'] : null,
            'raw' => is_array($deposit) ? $deposit : null,
            'http' => $response['status'],
        ];
    }

    /**
     * Operateurs Mobile Money actifs sur le compte.
     *
     * Les identifiants d'operateur varient par pays et par compte : ils sont
     * lus ici plutot que codes en dur, pour ne pas figer une valeur erronee.
     *
     * @return array<string, mixed>|null
     */
    public function activeConfiguration(): ?array
    {
        $response = $this->http->get($this->baseUrl.'/v2/active-conf');

        return $response['json'];
    }

    /**
     * Numero au format MSISDN attendu : chiffres uniquement, indicatif compris,
     * sans "+" ni espaces.
     */
    public static function normalisePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        // Numero camerounais saisi en local (9 chiffres commencant par 6) :
        // l'indicatif pays est ajoute, l'API le refusant sinon.
        if (strlen($digits) === 9 && str_starts_with($digits, '6')) {
            return '237'.$digits;
        }

        return $digits;
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function extractFailure(?array $json): ?string
    {
        if ($json === null) {
            return null;
        }

        $reason = $json['failureReason'] ?? null;

        if (is_array($reason)) {
            $code = $reason['failureCode'] ?? null;
            $message = $reason['failureMessage'] ?? null;

            return trim((string) $code.' '.(string) $message) ?: null;
        }

        if (is_string($reason) && $reason !== '') {
            return $reason;
        }

        return isset($json['message']) && is_string($json['message']) ? $json['message'] : null;
    }
}

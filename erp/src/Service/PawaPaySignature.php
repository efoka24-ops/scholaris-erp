<?php

declare(strict_types=1);

namespace Scholaris\Service;

use Scholaris\Support\Http;

/**
 * Verification des callbacks signes pawaPay, selon la RFC-9421
 * (HTTP Message Signatures).
 *
 * Sans cette verification, l'URL de callback est une porte ouverte : n'importe
 * qui la connaissant pourrait declarer une facture payee. La verification est
 * donc fail-closed — toute anomalie (signature absente, cle inconnue, empreinte
 * du corps differente) fait echouer le controle, jamais l'inverse.
 *
 * Deux controles independants :
 *  1. Content-Digest : le corps recu correspond bien a son empreinte annoncee ;
 *  2. Signature : cette empreinte et les metadonnees de la requete ont bien ete
 *     signees par la cle privee de pawaPay.
 *
 * Le premier sans le second ne prouve rien : un attaquant peut calculer une
 * empreinte. C'est leur combinaison qui fait la garantie.
 */
final class PawaPaySignature
{
    /** Fenetre de tolerance sur l'age de la signature, en secondes. */
    private const MAX_AGE = 300;

    /** @var array<string, string> cle publique PEM, indexee par identifiant */
    private array $publicKeys;

    private ?string $keysUrl;

    private ?Http $http;

    /**
     * @param  array<string, string>  $publicKeys
     */
    public function __construct(array $publicKeys = [], ?string $keysUrl = null, ?Http $http = null)
    {
        $this->publicKeys = $publicKeys;
        $this->keysUrl = $keysUrl;
        $this->http = $http;
    }

    /**
     * Verifie un callback entrant.
     *
     * @param  array<string, string>  $headers  en-tetes en minuscules
     * @return array{valid: bool, reason: string}
     */
    public function verify(string $method, string $authority, string $path, array $headers, string $body): array
    {
        $headers = array_change_key_case($headers, CASE_LOWER);

        $signature = $headers['signature'] ?? '';
        $signatureInput = $headers['signature-input'] ?? '';
        $contentDigest = $headers['content-digest'] ?? '';

        if ($signature === '' || $signatureInput === '') {
            return ['valid' => false, 'reason' => 'Signature absente du callback.'];
        }

        if ($contentDigest !== '' && ! $this->digestMatches($contentDigest, $body)) {
            return ['valid' => false, 'reason' => 'Le corps recu ne correspond pas a son empreinte.'];
        }

        $parsed = $this->parseSignatureInput($signatureInput);

        if ($parsed === null) {
            return ['valid' => false, 'reason' => 'En-tete Signature-Input illisible.'];
        }

        // Une signature trop ancienne peut etre un rejeu : elle est refusee.
        if ($parsed['created'] !== null && abs(time() - $parsed['created']) > self::MAX_AGE) {
            return ['valid' => false, 'reason' => 'Signature expiree ou horodatage incoherent.'];
        }

        $publicKey = $this->publicKey($parsed['keyid']);

        if ($publicKey === null) {
            return ['valid' => false, 'reason' => 'Cle publique inconnue : '.($parsed['keyid'] ?? 'non precisee')];
        }

        $base = $this->signatureBase($parsed, $method, $authority, $path, $headers, $signatureInput);
        $raw = $this->extractSignatureBytes($signature, $parsed['label']);

        if ($raw === null) {
            return ['valid' => false, 'reason' => 'Signature illisible.'];
        }

        $verified = $this->verifyBytes($base, $raw, $publicKey, $parsed['alg']);

        return $verified
            ? ['valid' => true, 'reason' => 'Signature verifiee.']
            : ['valid' => false, 'reason' => 'Signature invalide : le callback ne provient pas de pawaPay.'];
    }

    /**
     * Verifie l'empreinte du corps. Format : "sha-256=:base64:".
     */
    private function digestMatches(string $header, string $body): bool
    {
        if (preg_match('/(sha-256|sha-512)=:([A-Za-z0-9+\/=]+):/i', $header, $matches) !== 1) {
            return false;
        }

        $algorithm = strtolower($matches[1]) === 'sha-512' ? 'sha512' : 'sha256';
        $expected = base64_decode($matches[2], true);

        if ($expected === false) {
            return false;
        }

        return hash_equals(hash($algorithm, $body, true), $expected);
    }

    /**
     * Analyse l'en-tete Signature-Input.
     *
     * Forme : sig1=("@method" "@authority" "@path" "content-digest");created=...;keyid="...";alg="..."
     *
     * @return array{label: string, components: list<string>, created: int|null, keyid: string|null, alg: string|null}|null
     */
    private function parseSignatureInput(string $header): ?array
    {
        if (preg_match('/^([A-Za-z0-9_-]+)=\(([^)]*)\)(.*)$/s', trim($header), $matches) !== 1) {
            return null;
        }

        preg_match_all('/"([^"]+)"/', $matches[2], $componentMatches);
        $parameters = $matches[3];

        $created = null;

        if (preg_match('/created=(\d+)/', $parameters, $createdMatch) === 1) {
            $created = (int) $createdMatch[1];
        }

        $keyid = null;

        if (preg_match('/keyid="([^"]+)"/', $parameters, $keyMatch) === 1) {
            $keyid = $keyMatch[1];
        }

        $alg = null;

        if (preg_match('/alg="([^"]+)"/', $parameters, $algMatch) === 1) {
            $alg = $algMatch[1];
        }

        return [
            'label' => $matches[1],
            'components' => $componentMatches[1],
            'created' => $created,
            'keyid' => $keyid,
            'alg' => $alg,
        ];
    }

    /**
     * Reconstruit la base de signature : une ligne par composant, puis la ligne
     * @signature-params reprenant textuellement les parametres recus.
     *
     * @param  array{label: string, components: list<string>, created: int|null, keyid: string|null, alg: string|null}  $parsed
     * @param  array<string, string>  $headers
     */
    private function signatureBase(
        array $parsed,
        string $method,
        string $authority,
        string $path,
        array $headers,
        string $signatureInput
    ): string {
        $lines = [];

        foreach ($parsed['components'] as $component) {
            $value = match ($component) {
                '@method' => strtoupper($method),
                '@authority' => $authority,
                '@path' => $path,
                '@target-uri' => 'https://'.$authority.$path,
                default => $headers[strtolower($component)] ?? '',
            };

            $lines[] = '"'.$component.'": '.$value;
        }

        // Les parametres sont repris tels quels : les reformater changerait la
        // base et invaliderait une signature pourtant legitime.
        $parameters = '';

        if (preg_match('/^[A-Za-z0-9_-]+=(\(.*)$/s', trim($signatureInput), $matches) === 1) {
            $parameters = $matches[1];
        }

        $lines[] = '"@signature-params": '.$parameters;

        return implode("\n", $lines);
    }

    /**
     * Extrait les octets de signature de l'en-tete Signature.
     * Forme : sig1=:base64:
     */
    private function extractSignatureBytes(string $header, string $label): ?string
    {
        $pattern = '/'.preg_quote($label, '/').'=:([A-Za-z0-9+\/=]+):/';

        if (preg_match($pattern, $header, $matches) !== 1) {
            if (preg_match('/=:([A-Za-z0-9+\/=]+):/', $header, $matches) !== 1) {
                return null;
            }
        }

        $decoded = base64_decode($matches[1], true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Verifie la signature avec la cle publique.
     *
     * L'algorithme annonce dans Signature-Input pilote le mode de verification.
     * Un algorithme inconnu echoue plutot que de retomber sur un mode par
     * defaut, qui pourrait accepter a tort.
     */
    private function verifyBytes(string $base, string $signature, string $publicKeyPem, ?string $alg): bool
    {
        $key = openssl_pkey_get_public($publicKeyPem);

        if ($key === false) {
            return false;
        }

        $algorithm = strtolower($alg ?? '');

        if ($algorithm === 'ed25519' || $algorithm === '') {
            $details = openssl_pkey_get_details($key);

            // Ed25519 se verifie sans algorithme de hachage.
            if (($details['type'] ?? null) === OPENSSL_KEYTYPE_ED25519) {
                return openssl_verify($base, $signature, $key, '') === 1;
            }
        }

        return match ($algorithm) {
            'rsa-pss-sha512' => $this->verifyRsaPss($base, $signature, $key),
            'rsa-v1_5-sha256' => openssl_verify($base, $signature, $key, OPENSSL_ALGO_SHA256) === 1,
            'rsa-v1_5-sha512' => openssl_verify($base, $signature, $key, OPENSSL_ALGO_SHA512) === 1,
            'ecdsa-p256-sha256' => openssl_verify($base, $signature, $key, OPENSSL_ALGO_SHA256) === 1,
            'ecdsa-p384-sha384' => openssl_verify($base, $signature, $key, OPENSSL_ALGO_SHA384) === 1,
            // Sans algorithme annonce, on tente les modes RSA usuels.
            '' => openssl_verify($base, $signature, $key, OPENSSL_ALGO_SHA256) === 1
                || openssl_verify($base, $signature, $key, OPENSSL_ALGO_SHA512) === 1,
            default => false,
        };
    }

    /**
     * RSA-PSS n'est pas expose directement par openssl_verify en PHP 8.1 :
     * la verification passe par la primitive publique et la reconstruction du
     * bloc EMSA-PSS.
     *
     * @param  \OpenSSLAsymmetricKey  $key
     */
    private function verifyRsaPss(string $base, string $signature, $key): bool
    {
        $details = openssl_pkey_get_details($key);
        $bits = $details['bits'] ?? 0;

        if ($bits === 0) {
            return false;
        }

        $emLen = (int) ceil(($bits - 1) / 8);
        $em = '';

        if (openssl_public_decrypt($signature, $em, $key, OPENSSL_NO_PADDING) === false) {
            return false;
        }

        return $this->emsaPssVerify(hash('sha512', $base, true), $em, $emLen, 64);
    }

    /**
     * Verification EMSA-PSS (RFC 8017, section 9.1.2).
     */
    private function emsaPssVerify(string $mHash, string $em, int $emLen, int $hLen): bool
    {
        $sLen = $hLen;

        if ($emLen < $hLen + $sLen + 2 || substr($em, -1) !== chr(0xBC)) {
            return false;
        }

        $maskedDb = substr($em, 0, $emLen - $hLen - 1);
        $h = substr($em, $emLen - $hLen - 1, $hLen);

        $dbMask = $this->mgf1($h, $emLen - $hLen - 1, 'sha512');
        $db = $maskedDb ^ $dbMask;

        // Les bits de tete au-dela de la taille de cle doivent etre a zero.
        $bitsToClear = 8 * $emLen - ($emLen * 8 - 1);
        $db[0] = chr(ord($db[0]) & (0xFF >> $bitsToClear));

        $paddingLength = $emLen - $hLen - $sLen - 2;

        if (strspn($db, "\x00", 0, $paddingLength) !== $paddingLength || $db[$paddingLength] !== chr(0x01)) {
            return false;
        }

        $salt = substr($db, $paddingLength + 1);
        $mPrime = str_repeat("\x00", 8).$mHash.$salt;

        return hash_equals(hash('sha512', $mPrime, true), $h);
    }

    private function mgf1(string $seed, int $length, string $algorithm): string
    {
        $output = '';
        $counter = 0;

        while (strlen($output) < $length) {
            $output .= hash($algorithm, $seed.pack('N', $counter), true);
            $counter++;
        }

        return substr($output, 0, $length);
    }

    /**
     * Cle publique correspondant a l'identifiant annonce.
     *
     * Les cles configurees en local priment ; sinon elles sont recuperees une
     * fois aupres de pawaPay et conservees en memoire pour la requete.
     */
    private function publicKey(?string $keyid): ?string
    {
        if ($keyid !== null && isset($this->publicKeys[$keyid])) {
            return $this->publicKeys[$keyid];
        }

        // Une seule cle configuree sans identifiant : elle sert par defaut.
        if ($keyid === null && count($this->publicKeys) === 1) {
            return reset($this->publicKeys) ?: null;
        }

        if ($this->keysUrl === null || $this->http === null) {
            return null;
        }

        $response = $this->http->get($this->keysUrl);
        $json = $response['json'];

        if (! is_array($json)) {
            return null;
        }

        foreach ($this->extractKeys($json) as $id => $pem) {
            $this->publicKeys[$id] = $pem;
        }

        if ($keyid !== null && isset($this->publicKeys[$keyid])) {
            return $this->publicKeys[$keyid];
        }

        return count($this->publicKeys) === 1 ? (reset($this->publicKeys) ?: null) : null;
    }

    /**
     * @param  array<mixed>  $json
     * @return array<string, string>
     */
    private function extractKeys(array $json): array
    {
        $keys = [];
        $candidates = $json['keys'] ?? $json['publicKeys'] ?? $json;

        if (! is_array($candidates)) {
            return $keys;
        }

        foreach ($candidates as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = $entry['id'] ?? $entry['keyId'] ?? $entry['kid'] ?? null;
            $pem = $entry['key'] ?? $entry['publicKey'] ?? $entry['pem'] ?? null;

            if (is_string($id) && is_string($pem)) {
                $keys[$id] = $pem;
            }
        }

        return $keys;
    }
}

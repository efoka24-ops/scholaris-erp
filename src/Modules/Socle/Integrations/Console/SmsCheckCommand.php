<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Console;

use Scholaris\Modules\Socle\Integrations\Infrastructure\Sms\TeeeOtpClient;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sms\TeeeOtpException;
use Throwable;

/**
 * `sms:check` : interroge TeeeOTP (`GET /v1/me`) et affiche le mode (test/live), le quota et la consommation du projet de la
 * clé configurée. La clé n'est jamais affichée. Code de sortie 0 si le service répond, 1 sinon. N'envoie aucun SMS.
 */
final class SmsCheckCommand
{
    /** Clés de la réponse /v1/me qui peuvent contenir un secret : jamais affichées. */
    private const SECRET_KEY_PATTERN = '/key|secret|token|password/i';

    public function __construct(private readonly TeeeOtpClient $client, private readonly string $driver, private readonly string $apiKey)
    {
    }

    /** @param list<string> $args */
    public function run(array $args = []): int
    {
        echo "Contrôle TeeeOTP (GET /v1/me ; aucun SMS envoyé)\n";
        echo sprintf("  Pilote SMS (SMS_DRIVER)   : %s%s\n", $this->driver === '' ? 'journal (défaut)' : $this->driver, $this->driver === 'teeeotp' ? '' : ' (TeeeOTP non actif)');
        echo sprintf("  Clé API (TEEEOTP_API_KEY) : %s\n", $this->apiKey === '' ? 'ABSENTE' : 'présente (' . strlen($this->apiKey) . ' caractères, non affichée)');
        if ($this->apiKey !== '' && !str_starts_with($this->apiKey, 'sk_')) {
            echo "  ATTENTION : une clé TeeeOTP commence par sk_test_ ou sk_live_.\n";
        }

        try {
            $me = $this->client->me();
        } catch (TeeeOtpException $e) {
            echo sprintf("Résultat : ÉCART, %s (code %s%s).\n", $e->getMessage(), $e->errorCode, $e->httpStatus > 0 ? ', HTTP ' . $e->httpStatus : '');

            return 1;
        } catch (Throwable) {
            echo "Résultat : ÉCART, erreur inattendue.\n";

            return 1;
        }

        foreach (self::flatten($me) as $label => $value) {
            echo sprintf("  %-26s: %s\n", $label, $value);
        }
        $mode = $me['mode'] ?? '';
        $mode = is_string($mode) ? strtolower($mode) : '';
        if ($mode === 'live') {
            echo "Résultat : service joignable, clé de production (live).\n";
        } elseif ($mode === 'test') {
            echo "Résultat : service joignable, clé de test : au-delà de l'allocation de test, les SMS sont simulés (rien ne part).\n";
        } else {
            echo "Résultat : service joignable, mode non précisé par TeeeOTP.\n";
        }

        return 0;
    }

    /**
     * Valeurs scalaires de la réponse, une par ligne (objets imbriqués aplatis en « a.b »), hors clés secrètes.
     *
     * @param array<array-key, mixed> $data
     * @return array<string, string>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $lines = [];
        foreach ($data as $key => $value) {
            $name = $prefix . (string) $key;
            if (preg_match(self::SECRET_KEY_PATTERN, (string) $key) === 1) {
                continue;
            }
            if (is_array($value)) {
                $lines += self::flatten($value, $name . '.');
            } elseif (is_bool($value)) {
                $lines[$name] = $value ? 'oui' : 'non';
            } elseif (is_scalar($value)) {
                $lines[$name] = mb_substr((string) $value, 0, 120);
            } elseif ($value === null) {
                $lines[$name] = '-';
            }
        }

        return $lines;
    }
}

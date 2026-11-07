<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Console;

use Scholaris\Core\Config\Config;

/**
 * `sungku:check` : contrôle de configuration de la passerelle mobile money Sungku, SANS aucun appel réseau et sans jamais
 * afficher un secret. Code de sortie 0 si la mise en service est possible, 1 sinon. Ne modifie rien (n'active rien).
 */
final class SungkuCheckCommand
{
    public const WEBHOOK_PATH = '/api/v1/webhooks/payments/sungku';

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param list<string> $args
     */
    public function run(array $args = []): int
    {
        $lines = [];
        $failed = 0;
        foreach ($this->checks() as [$label, $ok, $detail, $blocking]) {
            $lines[] = sprintf('  [%s] %s%s', $ok ? 'OK' : ($blocking ? 'ÉCART' : 'ATTENTION'), $label, $detail === '' ? '' : ' : ' . $detail);
            if (!$ok && $blocking) {
                $failed++;
            }
        }
        echo "Contrôle de configuration Sungku (aucun appel réseau)\n" . implode("\n", $lines) . "\n";
        echo $failed === 0
            ? "Résultat : configuration complète. Pour activer : PAYMENT_GATEWAY=sungku dans .env, puis tester avec un montant minimal.\n"
            : "Résultat : {$failed} écart(s) bloquant(s). La passerelle reste sur le simulateur tant qu'ils ne sont pas corrigés.\n";

        return $failed === 0 ? 0 : 1;
    }

    /** @return list<array{0: string, 1: bool, 2: string, 3: bool}> libellé, réussite, détail, bloquant */
    public function checks(): array
    {
        $baseUrl = (string) $this->config->get('sungku.base_url', '');
        $apiKey = (string) $this->config->get('sungku.api_key', '');
        $secret = (string) $this->config->get('sungku.webhook_secret', '');
        $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');
        $baseParts = parse_url($baseUrl) ?: [];
        $appParts = parse_url($appUrl) ?: [];
        $appHost = strtolower((string) ($appParts['host'] ?? ''));
        $local = $appHost === '' || $appHost === 'localhost' || str_ends_with($appHost, '.local') || str_ends_with($appHost, '.test') || preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $appHost) === 1;

        return [
            ['Passerelle choisie (PAYMENT_GATEWAY)', $this->config->get('sungku.enabled') === true, $this->config->get('sungku.enabled') === true ? 'sungku' : 'simulateur (normal avant mise en service)', false],
            ['URL de Sungku en HTTPS', ($baseParts['scheme'] ?? '') === 'https' && ($baseParts['host'] ?? '') !== '', $baseUrl === '' ? 'SUNGKU_BASE_URL vide' : (string) ($baseParts['host'] ?? 'URL illisible'), true],
            ['Clé API renseignée', $apiKey !== '', $apiKey === '' ? 'SUNGKU_API_KEY vide' : 'présente (' . strlen($apiKey) . ' caractères, non affichée)', true],
            ['Secret de webhook renseigné (32 caractères au moins)', strlen($secret) >= 32, $secret === '' ? 'SUNGKU_WEBHOOK_SECRET vide' : (strlen($secret) >= 32 ? 'présent, non affiché' : 'trop court (' . strlen($secret) . ' caractères)'), true],
            ['URL publique de l\'application en HTTPS (APP_URL)', ($appParts['scheme'] ?? '') === 'https' && !$local, $appUrl === '' ? 'APP_URL vide' : $appUrl, true],
            ['Tolérance d\'horodatage du webhook', (int) $this->config->get('sungku.webhook_tolerance_seconds', 600) > 0 && (int) $this->config->get('sungku.webhook_tolerance_seconds', 600) <= 900, (int) $this->config->get('sungku.webhook_tolerance_seconds', 600) . ' s', false],
            ['Environnement de production', (string) $this->config->get('app.env', '') === 'production', (string) $this->config->get('app.env', ''), false],
            ['URL de webhook à déclarer chez Sungku', $appUrl !== '', $appUrl . self::WEBHOOK_PATH, false],
        ];
    }
}

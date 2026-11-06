<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use Scholaris\Modules\Socle\Integrations\Contracts\GatewayStatus;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryMonitor;
use Scholaris\Modules\Socle\Platform\Infrastructure\HealthPaths;
use Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb;
use Throwable;

/**
 * Santé de la plateforme (C-112) : sondes `ok | warning | critical | unknown`, chacune avec sa valeur et ses seuils
 * (réglages `health_*` du back-office). Une sonde sans source fiable rend `unknown` avec la raison, jamais une estimation.
 * État global : le pire des états (critical > warning > unknown > ok).
 */
final class HealthService
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const CRITICAL = 'critical';
    public const UNKNOWN = 'unknown';

    /** Mise en cache de l'état TeeeOTP (GET /v1/me) : 10 minutes. */
    public const SMS_CACHE_SECONDS = 600;
    private const SMS_CACHE_KEY = 'cache_sms_gateway';
    private const LOG_TAIL_BYTES = 2_000_000;
    private const RANK = [self::OK => 0, self::UNKNOWN => 1, self::WARNING => 2, self::CRITICAL => 3];

    public function __construct(
        private readonly PlatformDb $db,
        private readonly PlatformSettings $settings,
        private readonly PlatformClock $clock,
        private readonly DeliveryMonitor $deliveries,
        private readonly GatewayStatus $gateways,
        private readonly HealthPaths $paths,
    ) {
    }

    /** @return array{status: string, as_of: string, probes: list<array<string, mixed>>} */
    public function report(): array
    {
        $probes = [
            $this->database(),
            ...$this->cron(),
            ...$this->queues(),
            $this->sms(),
            $this->smtp(),
            $this->disk(),
            $this->backup(),
            $this->errors(),
        ];
        $status = self::OK;
        foreach ($probes as $probe) {
            if (self::RANK[$probe['status']] > self::RANK[$status]) {
                $status = $probe['status'];
            }
        }

        return ['status' => $status, 'as_of' => $this->clock->iso(), 'probes' => $probes];
    }

    /** @return array<string, mixed> */
    private function database(): array
    {
        $started = microtime(true);
        try {
            $this->db->scalar('SELECT 1');
        } catch (Throwable) {
            return self::probe('database', self::CRITICAL, null, 'ms', null, 'Base de données injoignable.');
        }

        return $this->rated('database', (int) round((microtime(true) - $started) * 1000), 'ms', 'db', 'ms');
    }

    /** @return list<array<string, mixed>> */
    private function cron(): array
    {
        $last = is_file($this->paths->scheduleLast) ? strtotime(trim((string) @file_get_contents($this->paths->scheduleLast))) : false;
        $cron = $last === false
            ? self::probe('cron_last_run', self::UNKNOWN, null, 'min', null, 'Aucun passage du planificateur enregistré (storage/schedule.last).')
            : $this->rated('cron_last_run', intdiv(max(0, $this->clock->timestamp() - $last), 60), 'min', 'cron', 'minutes');

        if (!is_file($this->paths->scheduleLog)) {
            return [$cron, self::probe('cron_failures', self::UNKNOWN, null, 'tasks', null, 'Journal du planificateur absent (storage/logs/schedule.log).')];
        }
        $since = $this->clock->timestamp() - 86400;
        $failed = [];
        foreach (self::tailLines($this->paths->scheduleLog) as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2})\] (.+?) : code (\d+)/', $line, $m) !== 1 || (int) $m[3] === 0) {
                continue;
            }
            $at = strtotime($m[1]);
            if ($at !== false && $at >= $since) {
                $failed[] = $m[2];
            }
        }
        $failures = self::probe('cron_failures', $failed === [] ? self::OK : self::WARNING, count($failed), 'tasks', null, $failed === [] ? null : 'Tâches en échec sur 24 h : ' . implode(', ', array_slice(array_values(array_unique($failed)), 0, 10)) . '.');

        return [$cron, $failures];
    }

    /** @return list<array<string, mixed>> */
    private function queues(): array
    {
        try {
            $queue = $this->deliveries->queue();
        } catch (Throwable) {
            return [self::probe('message_queue', self::UNKNOWN, null, 'messages', null, 'File des messages illisible.')];
        }

        return [
            $this->rated('message_queue', $queue['pending'], 'messages', 'queue', ''),
            $this->rated('message_failures', $queue['failed'], 'messages', 'failed', ''),
            self::probe('document_batches', self::UNKNOWN, null, 'batches', null, 'Le module Documents n\'expose pas encore de contrat de lecture de sa file de lots.'),
        ];
    }

    /** @return array<string, mixed> */
    private function sms(): array
    {
        $state = $this->smsState();
        $detail = sprintf('Pilote : %s%s.', $state['driver'], $state['mode'] === null ? '' : ', mode ' . $state['mode']);
        if (!$state['configured']) {
            return self::probe('sms_gateway', $state['driver'] === 'log' ? self::WARNING : self::CRITICAL, null, 'sms', null, $state['driver'] === 'log' ? 'SMS simulés (pilote journal) : aucun SMS ne part.' : 'Passerelle SMS incomplètement configurée.') + ['driver' => $state['driver']];
        }
        if ($state['reachable'] === false) {
            return self::probe('sms_gateway', self::CRITICAL, null, 'sms', null, 'TeeeOTP injoignable (' . (string) $state['error'] . ').') + ['driver' => $state['driver']];
        }
        if ($state['quota_remaining'] === null) {
            return self::probe('sms_gateway', $state['reachable'] === true ? self::OK : self::UNKNOWN, null, 'sms', null, $detail . ' Quota restant non communiqué.') + ['driver' => $state['driver'], 'mode' => $state['mode']];
        }
        $warning = $this->settings->value('health_sms_quota_warning');
        $critical = $this->settings->value('health_sms_quota_critical');
        $quota = $state['quota_remaining'];
        $status = $quota <= $critical ? self::CRITICAL : ($quota <= $warning ? self::WARNING : self::OK);

        return self::probe('sms_gateway', $status, $quota, 'sms', ['warning' => $warning, 'critical' => $critical, 'direction' => 'below'], $detail) + ['driver' => $state['driver'], 'mode' => $state['mode']];
    }

    /**
     * État TeeeOTP mis en cache 10 minutes (table platform_settings, clé technique non éditable) : quota et mode pour le
     * tableau de bord sans appeler le fournisseur à chaque lecture.
     *
     * @return array{driver: string, configured: bool, reachable: ?bool, mode: ?string, quota_remaining: ?int, error: ?string}
     */
    public function smsState(): array
    {
        $raw = $this->db->one('SELECT setting_value FROM platform_settings WHERE setting_key = ?', [self::SMS_CACHE_KEY]);
        $cached = $raw === null ? null : json_decode((string) $raw['setting_value'], true);
        if (is_array($cached) && isset($cached['at'], $cached['state']) && is_array($cached['state']) && $this->clock->timestamp() - (int) $cached['at'] < self::SMS_CACHE_SECONDS) {
            /** @var array{driver: string, configured: bool, reachable: ?bool, mode: ?string, quota_remaining: ?int, error: ?string} $state */
            $state = $cached['state'] + ['driver' => 'log', 'configured' => false, 'reachable' => null, 'mode' => null, 'quota_remaining' => null, 'error' => null];

            return $state;
        }
        $state = $this->gateways->sms();
        $value = json_encode(['at' => $this->clock->timestamp(), 'state' => $state], JSON_THROW_ON_ERROR);
        $now = $this->clock->iso();
        if ($this->db->update('platform_settings', ['setting_value' => $value, 'updated_at' => $now], ['setting_key' => self::SMS_CACHE_KEY]) === 0) {
            $this->db->insert('platform_settings', ['setting_key' => self::SMS_CACHE_KEY, 'setting_value' => $value, 'updated_by' => null, 'updated_at' => $now]);
        }

        return $state;
    }

    /** @return array<string, mixed> */
    private function smtp(): array
    {
        $mail = $this->gateways->mail();

        return self::probe('smtp', $mail['configured'] ? self::OK : self::WARNING, null, '', null, $mail['configured'] ? 'SMTP configuré.' : 'E-mails simulés (pilote ' . $mail['driver'] . ') : aucun e-mail ne part.') + ['driver' => $mail['driver']];
    }

    /** @return array<string, mixed> */
    private function disk(): array
    {
        $dir = is_dir($this->paths->uploads) ? $this->paths->uploads : dirname($this->paths->uploads);
        $total = is_dir($dir) ? @disk_total_space($dir) : false;
        $free = is_dir($dir) ? @disk_free_space($dir) : false;
        if ($total === false || $free === false || $total <= 0) {
            return self::probe('disk', self::UNKNOWN, null, '%', null, 'Espace disque des fichiers illisible.');
        }
        $used = (int) round(100 * ($total - $free) / $total);

        return $this->rated('disk', $used, '%', 'disk', 'percent') + ['free_mb' => (int) floor($free / 1048576)];
    }

    /** @return array<string, mixed> */
    private function backup(): array
    {
        $latest = null;
        foreach (is_dir($this->paths->backups) ? (scandir($this->paths->backups) ?: []) : [] as $name) {
            $file = $this->paths->backups . '/' . $name;
            if ($name[0] !== '.' && is_file($file)) {
                $latest = max($latest ?? 0, (int) filemtime($file));
            }
        }
        if ($latest === null) {
            return self::probe('backup', self::CRITICAL, null, 'h', null, 'Aucune sauvegarde trouvée (storage/backups).');
        }

        return $this->rated('backup', intdiv(max(0, $this->clock->timestamp() - $latest), 3600), 'h', 'backup', 'hours') + ['last_backup_at' => date(DATE_ATOM, $latest)];
    }

    /**
     * Erreurs 500 sur 24 h, comptées dans le journal applicatif (« Erreur non gérée »), avec leurs trace_id.
     *
     * @return array<string, mixed>
     */
    private function errors(): array
    {
        if (!is_file($this->paths->appLog)) {
            return self::probe('errors_24h', self::OK, 0, 'errors', null, 'Journal applicatif vide.') + ['trace_ids' => []];
        }
        $since = $this->clock->timestamp() - 86400;
        $count = 0;
        $traces = [];
        foreach (self::tailLines($this->paths->appLog) as $line) {
            if (!str_contains($line, 'Erreur non gérée') || preg_match('/^\[([^\]]+)\]/', $line, $m) !== 1) {
                continue;
            }
            $at = strtotime($m[1]);
            if ($at === false || $at < $since) {
                continue;
            }
            $count++;
            if (preg_match('/"trace_id":"([A-Za-z0-9_-]{1,64})"/', $line, $t) === 1) {
                $traces[] = $t[1];
            }
        }

        return $this->rated('errors_24h', $count, 'errors', 'errors', '') + ['trace_ids' => array_slice(array_reverse($traces), 0, 20)];
    }

    /**
     * Sonde à seuils croissants (valeur ≥ seuil) ; réglages `health_<setting>_warning[_<unité>]` et `_critical[_<unité>]`.
     *
     * @return array<string, mixed>
     */
    private function rated(string $key, int $value, string $unit, string $setting, string $settingUnit): array
    {
        $tail = $settingUnit === '' ? '' : '_' . $settingUnit;
        $warning = $this->settings->value(PlatformSettings::HEALTH_PREFIX . $setting . '_warning' . $tail);
        $critical = $this->settings->value(PlatformSettings::HEALTH_PREFIX . $setting . '_critical' . $tail);
        $status = $value >= $critical ? self::CRITICAL : ($value >= $warning ? self::WARNING : self::OK);

        return self::probe($key, $status, $value, $unit, ['warning' => $warning, 'critical' => $critical, 'direction' => 'above'], null);
    }

    /**
     * @param array{warning: int, critical: int, direction: string}|null $thresholds
     * @return array<string, mixed>
     */
    private static function probe(string $key, string $status, ?int $value, string $unit, ?array $thresholds, ?string $detail): array
    {
        return ['key' => $key, 'status' => $status, 'value' => $value, 'unit' => $unit, 'thresholds' => $thresholds, 'detail' => $detail];
    }

    /** @return list<string> dernières lignes du fichier (au plus 2 Mo lus) */
    private static function tailLines(string $file): array
    {
        $size = (int) @filesize($file);
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }
        if ($size > self::LOG_TAIL_BYTES) {
            fseek($handle, $size - self::LOG_TAIL_BYTES);
            fgets($handle);
        }
        $lines = [];
        while (($line = fgets($handle)) !== false) {
            $lines[] = rtrim($line, "\r\n");
        }
        fclose($handle);

        return $lines;
    }
}

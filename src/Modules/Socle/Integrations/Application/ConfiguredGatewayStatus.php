<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Application;

use Scholaris\Core\Config\Config;
use Scholaris\Modules\Socle\Integrations\Contracts\GatewayStatus;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Mail\EmailLayout;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Mail\SmtpSettings;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sms\TeeeOtpClient;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sms\TeeeOtpException;
use Throwable;

/** État des passerelles d'après la configuration ; TeeeOTP interrogé seulement s'il est le pilote actif et configuré. */
final class ConfiguredGatewayStatus implements GatewayStatus
{
    /** Champs possibles du quota restant dans la réponse /v1/me (format non documenté : voir C-101). */
    private const QUOTA_KEYS = ['quota_remaining', 'remaining', 'sms_remaining', 'balance', 'credits', 'quota.remaining', 'usage.remaining'];

    public function __construct(private readonly Config $config, private readonly TeeeOtpClient $client)
    {
    }

    public function sms(): array
    {
        $driver = strtolower(trim((string) $this->config->get('sms.driver', '')));
        $driver = $driver !== '' ? $driver : strtolower(trim((string) $this->config->get('sms.gateway', 'log')));
        $out = ['driver' => $driver, 'configured' => false, 'reachable' => null, 'mode' => null, 'quota_remaining' => null, 'error' => null];
        if ($driver === 'http') {
            $out['configured'] = trim((string) $this->config->get('sms.http.url', '')) !== '';

            return $out;
        }
        if ($driver !== 'teeeotp') {
            return $out;
        }
        $out['configured'] = trim((string) $this->config->get('sms.teeeotp.api_key', '')) !== '' && trim((string) $this->config->get('sms.teeeotp.base_url', '')) !== '';
        if (!$out['configured']) {
            return $out;
        }
        try {
            $me = $this->client->me();
            $out['reachable'] = true;
            $mode = $me['mode'] ?? null;
            $out['mode'] = is_string($mode) && $mode !== '' ? strtolower($mode) : null;
            foreach (self::QUOTA_KEYS as $path) {
                $value = $me;
                foreach (explode('.', $path) as $segment) {
                    $value = is_array($value) ? ($value[$segment] ?? null) : null;
                }
                if (is_int($value) || (is_string($value) && ctype_digit($value)) || is_float($value)) {
                    $out['quota_remaining'] = (int) $value;
                    break;
                }
            }
        } catch (TeeeOtpException $e) {
            $out['reachable'] = false;
            $out['error'] = $e->errorCode;
        } catch (Throwable) {
            $out['reachable'] = false;
            $out['error'] = 'UNEXPECTED';
        }

        return $out;
    }

    public function mail(): array
    {
        return ['driver' => strtolower((string) $this->config->get('mail.mailer', 'log')), 'configured' => SmtpSettings::isSelected($this->config)];
    }

    public function emailPreview(string $subject, string $body): string
    {
        return EmailLayout::render($subject, $body, (string) $this->config->get('mail.from_name', 'SCHOLARIS'));
    }
}

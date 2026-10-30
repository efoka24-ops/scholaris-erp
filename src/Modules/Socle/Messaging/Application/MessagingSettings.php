<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Messaging\Domain\SendingWindow;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/** Paramètres du module : plage d'envoi `messaging.window_start_hour` (7) et `messaging.window_end_hour` (21). */
final class MessagingSettings
{
    public function __construct(private readonly SettingsReader $settings)
    {
    }

    public function window(string $tenantId, string $establishmentId): SendingWindow
    {
        $start = $this->hour($tenantId, $establishmentId, 'messaging.window_start_hour', 7);
        $end = $this->hour($tenantId, $establishmentId, 'messaging.window_end_hour', 21);

        return $start < $end ? new SendingWindow($start, $end) : new SendingWindow();
    }

    /** Plafond annuel de SMS standard par élève (`messaging.sms_cap_per_learner_year`) ; 0 = aucun plafond. */
    public function smsCapPerLearnerYear(string $tenantId, string $establishmentId): int
    {
        try {
            $value = $this->settings->value($tenantId, $establishmentId, 'messaging.sms_cap_per_learner_year', 0);
        } catch (HttpException) {
            return 0;
        }

        return is_int($value) && $value > 0 && $value <= 10000 ? $value : 0;
    }

    private function hour(string $tenantId, string $establishmentId, string $key, int $default): int
    {
        try {
            $value = $this->settings->value($tenantId, $establishmentId, $key, $default);
        } catch (HttpException) {
            return $default;
        }

        return is_int($value) && $value >= 0 && $value <= 24 ? $value : $default;
    }
}

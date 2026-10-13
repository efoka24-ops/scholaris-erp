<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Application;

use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceCodes;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/** Paramètres du module (clés attendance.*), lus avec leur héritage plateforme, client, établissement. */
final class AttendanceSettings
{
    public const DEFAULT_SCALE = ['avertissement', 'blame', 'retenue', 'exclusion_temporaire', 'conseil_discipline'];

    public function __construct(private readonly SettingsReader $settings)
    {
    }

    /** Seuil de non justifié au-dessus duquel l'apprenant n'est pas autorisé à composer (30 % par défaut). */
    public function maxUnjustifiedPercent(string $tenantId, string $establishmentId): float
    {
        return $this->percent($tenantId, $establishmentId, 'attendance.max_unjustified_percent', 30.0);
    }

    /** Seuil de l'alerte préventive (25 % par défaut, jamais au-dessus du seuil maximal). */
    public function alertPercent(string $tenantId, string $establishmentId): float
    {
        return min($this->percent($tenantId, $establishmentId, 'attendance.alert_unjustified_percent', 25.0), $this->maxUnjustifiedPercent($tenantId, $establishmentId));
    }

    public function notifyParents(string $tenantId, string $establishmentId): bool
    {
        $value = $this->settings->value($tenantId, $establishmentId, 'attendance.notify_parents', true);

        return !in_array($value, [false, 0, '0', 'false', 'off'], true);
    }

    /** Durée de validité du jeton de séance (pointage par QR), en minutes. */
    public function qrWindowMinutes(string $tenantId, string $establishmentId): int
    {
        $value = $this->settings->value($tenantId, $establishmentId, 'attendance.qr_window_minutes', 10);

        return is_numeric($value) ? max(1, min(120, (int) $value)) : 10;
    }

    /**
     * Base des taux par élément constitutif (D20) : `held` (séances tenues, appel validé ; défaut) ou `planned` (séances
     * programmées de l'emploi du temps). Toute autre valeur donne `held`.
     */
    public function base(string $tenantId, string $establishmentId): string
    {
        $value = $this->settings->value($tenantId, $establishmentId, 'attendance.base', AttendanceCodes::BASE_HELD);

        return $value === AttendanceCodes::BASE_PLANNED ? AttendanceCodes::BASE_PLANNED : AttendanceCodes::BASE_HELD;
    }

    /** @return list<string> barème des sanctions de l'établissement */
    public function sanctionScale(string $tenantId, string $establishmentId): array
    {
        $raw = $this->settings->value($tenantId, $establishmentId, 'attendance.sanction_scale', null);
        if (!is_array($raw)) {
            return self::DEFAULT_SCALE;
        }
        $scale = [];
        foreach ($raw as $kind) {
            if (is_string($kind) && preg_match('/^[a-z0-9_]{2,40}$/', $kind) === 1) {
                $scale[] = $kind;
            }
        }

        return $scale === [] ? self::DEFAULT_SCALE : array_values(array_unique($scale));
    }

    private function percent(string $tenantId, string $establishmentId, string $key, float $default): float
    {
        $value = $this->settings->value($tenantId, $establishmentId, $key, $default);

        return is_numeric($value) ? max(0.0, min(100.0, (float) $value)) : $default;
    }
}

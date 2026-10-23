<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Attendance\Application;

use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\PrimaryClock;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummaryProvider;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter;

/**
 * Préréglages de l'établissement primaire à l'ouverture de l'application (EX-PRI-29, RG-PRI-19) : appel par demi-journée
 * (`attendance.mode = day_part`, Q9 à confirmer). Idempotent et SANS écrasement : une valeur déjà choisie par l'école est conservée.
 * Aucun crochet d'ouverture n'existe dans la plateforme : le préréglage est posé par l'écran « Démarrage » ou par cet appel.
 */
final class SetupService
{
    /** @var array<string, mixed> clé de paramètre => valeur recommandée (défauts à confirmer, questions.md) */
    public const PRESETS = ['attendance.mode' => 'day_part'];
    public const MANAGE = ['primary.settings.manage'];
    public const READ = ['primary.settings.manage', 'primary.dashboard.read', 'attendance.take', 'attendance.read'];

    public function __construct(
        private readonly SettingsReader $reader,
        private readonly SettingsWriter $writer,
        private readonly AttendanceSummaryProvider $attendance,
        private readonly Access $access,
        private readonly PrimaryClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function status(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, self::READ);

        return $this->snapshot($principal->tenantId, $establishmentId);
    }

    /** @return array<string, mixed> */
    public function apply(Principal $principal, string $establishmentId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'primary_setup');
        $applied = [];
        foreach (self::PRESETS as $key => $value) {
            if ($this->writer->presetIfAbsent($principal->tenantId, $establishmentId, $key, $value, $principal->userId)) {
                $applied[] = $key;
            }
        }
        if ($applied !== []) {
            $this->access->audit($principal, 'primary.setup.applied', 'establishment', $establishmentId, null, ['keys' => $applied]);
        }

        return $this->snapshot($principal->tenantId, $establishmentId) + ['applied' => $applied];
    }

    /** @return array<string, mixed> */
    private function snapshot(string $tenantId, string $establishmentId): array
    {
        $presets = [];
        foreach (self::PRESETS as $key => $recommended) {
            $current = $this->reader->value($tenantId, $establishmentId, $key, null);
            $presets[] = ['key' => $key, 'recommended' => $recommended, 'current' => $current, 'ok' => $current === $recommended];
        }

        return [
            'presets' => $presets, 'ready' => array_reduce($presets, static fn (bool $carry, array $p): bool => $carry && $p['ok'], true),
            'day' => $this->clock->today(), 'absent_today' => $this->attendance->absentCountOn($tenantId, $establishmentId, $this->clock->today()),
        ];
    }
}

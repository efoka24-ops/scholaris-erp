<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Grades\Application;

use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Grades\Domain\Defaults;
use Scholaris\Modules\Apps\Secondary\Grades\Domain\LetterScale;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter;

/**
 * Paramètres de la saisie des notes (T868) : valeurs en vigueur pour l'établissement (héritage plateforme, client, établissement)
 * et préréglage d'exemple posé à la demande, sans jamais écraser une valeur déjà fixée par l'établissement.
 */
final class GradesSettings
{
    public const READ = ['secondary.grades.read', 'secondary.grades.enter', 'secondary.grades.validate', 'secondary.grades.lock', 'secondary.settings.read', 'secondary.settings.manage'];
    public const MANAGE = ['secondary.settings.manage'];
    public const OFFLINE = 'secondary.grades.offline_entry';
    public const LETTERS = 'secondary.grades.letter_scale';

    public function __construct(
        private readonly SettingsReader $settings,
        private readonly SettingsWriter $writer,
        private readonly Access $access,
    ) {
    }

    /** @return array<string, mixed> */
    public function show(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, self::READ);

        return $this->effective($principal->tenantId, $establishmentId);
    }

    /**
     * Valeurs utiles aux écrans : la clé, la valeur en vigueur (null si absente) et l'indication « posée » ou non.
     *
     * @return array<string, mixed>
     */
    public function effective(string $tenantId, string $establishmentId): array
    {
        $values = [];
        foreach (array_keys(Defaults::settings()) as $key) {
            $values[$key] = $this->settings->value($tenantId, $establishmentId, $key, null);
        }
        $codes = [];
        foreach ((array) ($values['assessment.absence_codes'] ?? []) as $code => $declaration) {
            if (is_array($declaration)) {
                $codes[] = ['code' => (string) $code, 'label' => (string) ($declaration['label'] ?? $code), 'effect' => $declaration['effect'] ?? null];
            }
        }
        $step = $values['assessment.step'];

        return [
            'values' => $values,
            'configured' => array_map(static fn (mixed $v): bool => $v !== null, $values),
            'origin_of_examples' => Defaults::ORIGIN,
            'step' => is_int($step) || is_float($step) ? (float) $step : null,
            'absence_codes' => $codes,
            'offline_entry' => $values[self::OFFLINE] === true,
            'letter_scale' => LetterScale::fromSetting($values[self::LETTERS])->toArray(),
            'lock_requires_second_factor' => $this->lockRequiresSecondFactor($values['assessment.lifecycle']),
        ];
    }

    public function offlineEnabled(string $tenantId, string $establishmentId): bool
    {
        return $this->settings->value($tenantId, $establishmentId, self::OFFLINE, false) === true;
    }

    public function letters(string $tenantId, string $establishmentId): LetterScale
    {
        return LetterScale::fromSetting($this->settings->value($tenantId, $establishmentId, self::LETTERS, null));
    }

    /**
     * Pose le préréglage d'exemple : chaque clé absente au niveau de l'établissement reçoit la valeur d'exemple, une clé déjà posée
     * est conservée (et listée). Tracé dans l'audit par le socle (SettingsWriter) et par l'application.
     *
     * @param list<string>|null $keys clés à poser (null = toutes)
     * @return array<string, mixed>
     */
    public function preset(Principal $principal, string $establishmentId, ?array $keys): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'se_grades_settings');
        $defaults = Defaults::settings();
        $selected = $keys === null ? array_keys($defaults) : array_values(array_intersect(array_keys($defaults), $keys));
        $applied = [];
        $kept = [];
        foreach ($selected as $key) {
            if ($this->writer->presetIfAbsent($principal->tenantId, $establishmentId, $key, $defaults[$key], $principal->userId)) {
                $applied[] = $key;
            } else {
                $kept[] = $key;
            }
        }
        $this->access->audit($principal, 'secondary.grades.settings.preset', 'se_grades_settings', $establishmentId, null, ['applied' => $applied, 'kept' => $kept]);

        return ['applied' => $applied, 'kept' => $kept] + $this->effective($principal->tenantId, $establishmentId);
    }

    private function lockRequiresSecondFactor(mixed $lifecycle): bool
    {
        if (!is_array($lifecycle) || !is_array($lifecycle['transitions'] ?? null)) {
            return false;
        }
        foreach ($lifecycle['transitions'] as $transition) {
            if (is_array($transition) && ($transition['to'] ?? null) === 'locked' && ($transition['require_recent_2fa'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }
}

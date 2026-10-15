<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Application;

use Scholaris\Modules\Apps\Higher\Grades\Domain\GradeLifecycle;
use Scholaris\Modules\Apps\Higher\Grades\Domain\IpesDefaults;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitSettingsReader;

/**
 * Paramètres du moteur d'évaluation pour une école de l'IPES (RG-ORG-5 : l'école hérite de l'établissement et peut surcharger) :
 * clés du socle `assessment.*` (pas, codes d'absence, cycle, chaîne de correction, séparation saisie/verrouillage) et clés
 * `higher.grades.*`. Clé absente ou valeur aberrante : valeur IPES (IpesDefaults), jamais une règle fausse.
 */
final class GradesSettings
{
    public function __construct(private readonly OrgUnitSettingsReader $settings)
    {
    }

    private function raw(string $tenantId, string $establishmentId, ?string $schoolId, string $key): mixed
    {
        $value = $this->settings->valueForOrgUnit($tenantId, $establishmentId, $schoolId, $key, null);

        return is_string($value) && in_array($value[0] ?? '', ['{', '['], true) ? json_decode($value, true) : $value;
    }

    public function step(string $tenantId, string $establishmentId, ?string $schoolId): float
    {
        $value = $this->raw($tenantId, $establishmentId, $schoolId, 'assessment.step');

        return (is_int($value) || is_float($value)) && $value > 0 && $value <= 10 ? (float) $value : IpesDefaults::STEP;
    }

    /**
     * Codes d'absence acceptés : codes IPES puis surcharges déclarées par `assessment.absence_codes`.
     *
     * @return array<string, array{effect: ?string, label: string, aliases: list<string>, discipline: bool}>
     */
    public function absenceCodes(string $tenantId, string $establishmentId, ?string $schoolId): array
    {
        $codes = [];
        foreach (IpesDefaults::ABSENCE_CODES as $code => $declared) {
            $codes[$code] = ['effect' => $declared['effect'], 'label' => $declared['label'], 'aliases' => $declared['aliases'], 'discipline' => ($declared['discipline'] ?? false) === true];
        }
        $raw = $this->raw($tenantId, $establishmentId, $schoolId, 'assessment.absence_codes');
        foreach (is_array($raw) ? $raw : [] as $code => $declared) {
            $code = (string) $code;
            if (preg_match('/^[a-z][a-z0-9_]{0,11}$/', $code) !== 1 || !is_array($declared)) {
                continue;
            }
            $effect = in_array($declared['effect'] ?? null, [IpesDefaults::ZERO, IpesDefaults::EXCLUDED, IpesDefaults::RETAKE], true) ? (string) $declared['effect'] : ($codes[$code]['effect'] ?? IpesDefaults::ZERO);
            $aliases = $codes[$code]['aliases'] ?? [];
            foreach ((array) ($declared['aliases'] ?? []) as $alias) {
                if (is_string($alias) && preg_match('/^[A-Za-z]{1,8}$/', $alias) === 1) {
                    $aliases[] = strtoupper($alias);
                }
            }
            $label = is_string($declared['label'] ?? null) && trim($declared['label']) !== '' ? trim($declared['label']) : ($codes[$code]['label'] ?? $code);
            $codes[$code] = ['effect' => $effect, 'label' => $label, 'aliases' => array_values(array_unique($aliases)), 'discipline' => ($declared['discipline'] ?? ($codes[$code]['discipline'] ?? false)) === true];
        }

        return $codes;
    }

    /** @return array<string, string|null> code => effet */
    public function absenceEffects(string $tenantId, string $establishmentId, ?string $schoolId): array
    {
        return array_map(static fn (array $c): ?string => $c['effect'], $this->absenceCodes($tenantId, $establishmentId, $schoolId));
    }

    public function lifecycle(string $tenantId, string $establishmentId, ?string $schoolId): GradeLifecycle
    {
        return GradeLifecycle::fromArray($this->raw($tenantId, $establishmentId, $schoolId, 'assessment.lifecycle')) ?? GradeLifecycle::ipes();
    }

    /** @return list<array{permission: string, role: ?string, label: string, require_recent_2fa: bool}> */
    public function correctionChain(string $tenantId, string $establishmentId, ?string $schoolId): array
    {
        $raw = $this->raw($tenantId, $establishmentId, $schoolId, 'assessment.correction.chain');
        $source = is_array($raw) && $raw !== [] && array_is_list($raw) && count($raw) <= 5 ? $raw : IpesDefaults::CORRECTION_CHAIN;
        $steps = [];
        foreach ($source as $index => $step) {
            if (!is_array($step) || !is_string($step['permission'] ?? null) || preg_match('/^[a-z][a-z0-9_.]{2,60}$/', $step['permission']) !== 1) {
                return $source === IpesDefaults::CORRECTION_CHAIN ? [] : self::defaultChain();
            }
            $steps[] = [
                'permission' => $step['permission'],
                'role' => is_string($step['role'] ?? null) && $step['role'] !== '' ? $step['role'] : null,
                'label' => is_string($step['label'] ?? null) && trim($step['label']) !== '' ? trim($step['label']) : 'Étape ' . ($index + 1),
                'require_recent_2fa' => ($step['require_recent_2fa'] ?? false) === true,
            ];
        }

        return $steps;
    }

    /** @return list<array{permission: string, role: ?string, label: string, require_recent_2fa: bool}> */
    private static function defaultChain(): array
    {
        return IpesDefaults::CORRECTION_CHAIN;
    }

    /**
     * États cibles soumis à la séparation saisie / verrouillage (liste vide = désactivée).
     *
     * @return list<string>
     */
    public function segregatedTargets(string $tenantId, string $establishmentId, ?string $schoolId): array
    {
        $raw = $this->raw($tenantId, $establishmentId, $schoolId, 'assessment.segregate_lock');
        if ($raw === false) {
            return [];
        }
        if (is_array($raw) && array_is_list($raw)) {
            return array_values(array_filter($raw, 'is_string'));
        }

        return IpesDefaults::SEGREGATE_LOCK;
    }

    public function queryDays(string $tenantId, string $establishmentId, ?string $schoolId): int
    {
        $value = $this->raw($tenantId, $establishmentId, $schoolId, 'higher.grades.query_days');

        return is_int($value) && $value >= 0 && $value <= 90 ? $value : IpesDefaults::QUERY_DAYS;
    }

    public function defaultWeight(string $tenantId, string $establishmentId, ?string $schoolId, string $kind): int
    {
        $raw = $this->raw($tenantId, $establishmentId, $schoolId, 'higher.grades.default_weights');
        $value = is_array($raw) ? ($raw[$kind] ?? null) : null;

        return is_int($value) && $value >= 0 && $value <= 100 ? $value : IpesDefaults::WEIGHTS[$kind];
    }

    public function reminderEveryDays(string $tenantId, string $establishmentId): int
    {
        $value = $this->raw($tenantId, $establishmentId, null, 'higher.grades.reminder_every_days');

        return is_int($value) && $value >= 1 && $value <= 30 ? $value : IpesDefaults::REMINDER_EVERY_DAYS;
    }

    public function publishWithoutDeliberation(string $tenantId, string $establishmentId): bool
    {
        return $this->raw($tenantId, $establishmentId, null, 'higher.grades.publish_without_deliberation') === true;
    }

    /**
     * Configuration effective (écran de paramétrage, tests).
     *
     * @return array<string, mixed>
     */
    public function effective(string $tenantId, string $establishmentId, ?string $schoolId): array
    {
        return [
            'assessment.step' => $this->step($tenantId, $establishmentId, $schoolId),
            'assessment.absence_codes' => $this->absenceCodes($tenantId, $establishmentId, $schoolId),
            'assessment.lifecycle' => $this->lifecycle($tenantId, $establishmentId, $schoolId)->toArray(),
            'assessment.correction.chain' => $this->correctionChain($tenantId, $establishmentId, $schoolId),
            'assessment.segregate_lock' => $this->segregatedTargets($tenantId, $establishmentId, $schoolId),
            'higher.grades.query_days' => $this->queryDays($tenantId, $establishmentId, $schoolId),
            'higher.grades.default_weights' => [
                'cc' => $this->defaultWeight($tenantId, $establishmentId, $schoolId, 'cc'),
                'tp' => $this->defaultWeight($tenantId, $establishmentId, $schoolId, 'tp'),
                'exam' => $this->defaultWeight($tenantId, $establishmentId, $schoolId, 'exam'),
                'retake' => $this->defaultWeight($tenantId, $establishmentId, $schoolId, 'retake'),
            ],
            'higher.grades.max_score' => IpesDefaults::MAX_SCORE,
        ];
    }
}

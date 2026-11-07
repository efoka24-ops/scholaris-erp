<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Discipline\Application;

use Scholaris\Modules\Apps\Secondary\Councils\Contracts\ConductRules;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\SchoolRules;

/**
 * Barème de l'établissement (RG-PRE-1) : règle versionnée `discipline_scale` du paramétrage (Setup, SchoolRules). Forme attendue :
 * liste de paliers `{code, label, level, from_hours | above_hours}` ; le palier marqué `repeat_offence: true` est celui de la
 * récidive. Un objet `{tiers, repeat_offence_code}` est aussi accepté. Sans règle : aucun barème, aucune proposition.
 */
final class DisciplineScale
{
    public const RULE_KEY = 'discipline_scale';

    public function __construct(private readonly SchoolRules $rules, private readonly ConductRules $conduct)
    {
    }

    /**
     * @return array{configured: bool, scale: array{tiers: list<array<string, mixed>>, repeat_offence_code?: string}, version: string, errors: list<string>}
     */
    public function current(string $tenantId, string $establishmentId): array
    {
        $value = $this->rules->value($tenantId, $establishmentId, self::RULE_KEY);
        if (!is_array($value) || $value === []) {
            return ['configured' => false, 'scale' => ['tiers' => []], 'version' => 'none', 'errors' => []];
        }
        $scale = self::normalise($value);
        $errors = $this->conduct->scaleErrors($scale);

        return [
            'configured' => $errors === [],
            'scale' => $scale,
            'version' => substr(sha1((string) json_encode($scale)), 0, 12),
            'errors' => $errors,
        ];
    }

    /** @return array{tiers: list<array<string, mixed>>, repeat_offence_code?: string} */
    public function example(): array
    {
        $example = $this->conduct->exampleScale();

        return ['tiers' => $example['tiers']] + (isset($example['repeat_offence_code']) ? ['repeat_offence_code' => $example['repeat_offence_code']] : []);
    }

    /**
     * @param array<mixed> $value
     * @return array{tiers: list<array<string, mixed>>, repeat_offence_code?: string}
     */
    private static function normalise(array $value): array
    {
        if (!array_is_list($value)) {
            $tiers = is_array($value['tiers'] ?? null) ? array_values(array_filter($value['tiers'], 'is_array')) : [];
            $scale = ['tiers' => $tiers];
            if (is_string($value['repeat_offence_code'] ?? null)) {
                $scale['repeat_offence_code'] = $value['repeat_offence_code'];
            }

            return $scale;
        }
        $tiers = [];
        $repeat = null;
        foreach ($value as $tier) {
            if (!is_array($tier)) {
                continue;
            }
            if (($tier['repeat_offence'] ?? false) === true && is_string($tier['code'] ?? null)) {
                $repeat = $tier['code'];
            }
            unset($tier['repeat_offence']);
            $tiers[] = $tier;
        }

        return ['tiers' => $tiers] + ($repeat === null ? [] : ['repeat_offence_code' => $repeat]);
    }
}

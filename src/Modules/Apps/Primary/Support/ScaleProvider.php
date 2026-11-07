<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Support;

use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/**
 * Échelles paramétrables de l'école (Q7, Q14, valeurs par défaut À CONFIRMER) :
 *  - compétences : `assessment.qualitative_scale` (socle, PS2), défaut = les trois codes du socle, aucun seuil numérique ;
 *  - conduite : `primary.conduct_scale`, défaut = quatre niveaux de libellés paramétrables ;
 *  - absences à l'évaluation d'une compétence : `primary.competencies.absence_codes`.
 * Chaque échelle est une liste ordonnée {code, label_fr, label_en, sort_order}, codes `^[a-z][a-z0-9_]{1,19}$`.
 */
final class ScaleProvider
{
    public const QUALITATIVE_KEY = 'assessment.qualitative_scale';
    public const CONDUCT_KEY = 'primary.conduct_scale';
    public const ABSENCE_KEY = 'primary.competencies.absence_codes';
    public const CODE = '/^[a-z][a-z0-9_]{1,19}$/';

    private const QUALITATIVE_DEFAULT = [
        ['code' => 'acquired', 'label_fr' => 'Acquis', 'label_en' => 'Acquired', 'sort_order' => 1],
        ['code' => 'in_progress', 'label_fr' => 'En cours d’acquisition', 'label_en' => 'In progress', 'sort_order' => 2],
        ['code' => 'not_acquired', 'label_fr' => 'Non acquis', 'label_en' => 'Not acquired', 'sort_order' => 3],
    ];
    private const CONDUCT_DEFAULT = [
        ['code' => 'very_good', 'label_fr' => 'Très bonne', 'label_en' => 'Very good', 'sort_order' => 1],
        ['code' => 'good', 'label_fr' => 'Bonne', 'label_en' => 'Good', 'sort_order' => 2],
        ['code' => 'fair', 'label_fr' => 'Passable', 'label_en' => 'Fair', 'sort_order' => 3],
        ['code' => 'poor', 'label_fr' => 'Mauvaise', 'label_en' => 'Poor', 'sort_order' => 4],
    ];
    private const ABSENCE_DEFAULT = ['absent', 'exempt'];

    public function __construct(private readonly SettingsReader $settings)
    {
    }

    /** @return list<array{code: string, label_fr: string, label_en: string, sort_order: int}> */
    public function qualitative(string $tenantId, string $establishmentId): array
    {
        return $this->scale($tenantId, $establishmentId, self::QUALITATIVE_KEY, self::QUALITATIVE_DEFAULT);
    }

    /** @return list<array{code: string, label_fr: string, label_en: string, sort_order: int}> */
    public function conduct(string $tenantId, string $establishmentId): array
    {
        return $this->scale($tenantId, $establishmentId, self::CONDUCT_KEY, self::CONDUCT_DEFAULT);
    }

    /** @return list<string> */
    public function absenceCodes(string $tenantId, string $establishmentId): array
    {
        $raw = $this->settings->value($tenantId, $establishmentId, self::ABSENCE_KEY, null);
        if (!is_array($raw)) {
            return self::ABSENCE_DEFAULT;
        }
        $codes = array_values(array_filter($raw, static fn (mixed $c): bool => is_string($c) && preg_match(self::CODE, $c) === 1));

        return $codes === [] ? self::ABSENCE_DEFAULT : $codes;
    }

    /** @return list<string> */
    public function codes(string $tenantId, string $establishmentId, bool $conduct = false): array
    {
        return array_column($conduct ? $this->conduct($tenantId, $establishmentId) : $this->qualitative($tenantId, $establishmentId), 'code');
    }

    /**
     * @param list<array{code: string, label_fr: string, label_en: string, sort_order: int}> $default
     * @return list<array{code: string, label_fr: string, label_en: string, sort_order: int}>
     */
    private function scale(string $tenantId, string $establishmentId, string $key, array $default): array
    {
        $raw = $this->settings->value($tenantId, $establishmentId, $key, null);
        if (!is_array($raw)) {
            return $default;
        }
        $scale = [];
        foreach ($raw as $item) {
            if (!is_array($item) || !isset($item['code']) || !is_string($item['code']) || preg_match(self::CODE, $item['code']) !== 1) {
                return $default;
            }
            $fr = isset($item['label_fr']) && is_string($item['label_fr']) ? $item['label_fr'] : $item['code'];
            $scale[] = [
                'code' => $item['code'], 'label_fr' => $fr,
                'label_en' => isset($item['label_en']) && is_string($item['label_en']) ? $item['label_en'] : $fr,
                'sort_order' => isset($item['sort_order']) && is_int($item['sort_order']) ? $item['sort_order'] : count($scale) + 1,
            ];
        }
        usort($scale, static fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);

        return $scale === [] ? $default : $scale;
    }
}

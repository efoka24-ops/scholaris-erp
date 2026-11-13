<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Academic\Http;

/** Ressources publiques du référentiel : colonnes explicites (jamais la ligne brute), booléens typés. */
final class Resources
{
    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public static function profile(array $r): array
    {
        return [
            'id' => (string) $r['id'], 'level_structure_id' => (string) $r['level_structure_id'],
            'section_org_unit_id' => $r['section_org_unit_id'] === null ? null : (string) $r['section_org_unit_id'],
            'cycle_code' => $r['cycle_code'] === null ? null : (string) $r['cycle_code'], 'sort_order' => (int) $r['sort_order'],
            'is_final_level' => (int) $r['is_final_level'] === 1, 'is_exam_level' => (int) $r['is_exam_level'] === 1,
            'language' => (string) $r['language'], 'external_key' => $r['external_key'] === null ? null : (string) $r['external_key'],
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public static function domain(array $r): array
    {
        return [
            'id' => (string) $r['id'], 'academic_year_id' => (string) $r['academic_year_id'], 'code' => (string) $r['code'],
            'label_fr' => (string) $r['label_fr'], 'label_en' => $r['label_en'] === null ? null : (string) $r['label_en'],
            'sort_order' => (int) $r['sort_order'], 'active' => (int) $r['active'] === 1,
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public static function levelSubject(array $r): array
    {
        return [
            'id' => (string) $r['id'], 'academic_year_id' => (string) $r['academic_year_id'], 'level_structure_id' => (string) $r['level_structure_id'],
            'subject_id' => (string) $r['subject_id'], 'subject_code' => (string) $r['subject_code'], 'subject_name' => (string) $r['subject_name'],
            'domain_id' => (string) $r['domain_id'], 'evaluation_mode' => (string) $r['evaluation_mode'], 'sort_order' => (int) $r['sort_order'],
            'active' => (int) $r['active'] === 1,
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public static function competency(array $r): array
    {
        return [
            'id' => (string) $r['id'], 'academic_year_id' => (string) $r['academic_year_id'], 'level_structure_id' => (string) $r['level_structure_id'],
            'domain_id' => (string) $r['domain_id'], 'subject_id' => $r['subject_id'] === null ? null : (string) $r['subject_id'],
            'code' => (string) $r['code'], 'label_fr' => (string) $r['label_fr'], 'label_en' => $r['label_en'] === null ? null : (string) $r['label_en'],
            'sort_order' => (int) $r['sort_order'], 'active' => (int) $r['active'] === 1,
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public static function template(array $r, bool $withPayload = false): array
    {
        $payload = json_decode((string) $r['payload'], true);
        $payload = is_array($payload) ? $payload : [];
        $levels = is_array($payload['levels'] ?? null) ? $payload['levels'] : [];
        $resource = [
            'code' => (string) $r['code'], 'label' => (string) $r['label'], 'origin' => (string) $r['origin'], 'language' => (string) $r['language'],
            'version' => (int) $r['version'], 'levels' => count($levels), 'platform' => (string) $r['tenant_id'] === 'platform',
        ];
        if ($withPayload) {
            $resource['payload'] = $payload;
        }

        return $resource;
    }
}
